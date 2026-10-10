<?php

namespace DiscordEventNotifier\Console;

use DiscordEventNotifier\Services\DiscordNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\DaemonManager;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

/**
 * Poll Wings' current state. This is observation, NOT a power-action audit log.
 * A one-minute sampling interval cannot distinguish an intentional stop from a crash.
 */
class PollServerStates extends Command
{
    protected $signature = 'discord-notifier:poll';
    protected $description = 'Check Wings server power states and notify Discord about changes.';

    /**
     * Experimental: the Wings logs endpoint returns a bounded console snapshot,
     * not a reliable event feed. If its return shape is unknown, skip quietly.
     */
    private function checkCrashEvidence(Server $server, string $state, ExtensionSettings $store, DiscordNotifier $notifier): void
    {
        try {
            $result = app(DaemonManager::class)->server($server)->logs(100);
            // Support a list of lines or a validated payload with a logs/data field.
            if (is_string($result)) {
                $lines = preg_split('/\r?\n/', $result);
            } elseif (is_array($result)) {
                $raw = $result['logs'] ?? $result['data'] ?? $result;
                $lines = is_string($raw) ? preg_split('/\r?\n/', $raw) : (is_array($raw) ? $raw : []);
            } else {
                return;
            }
            $lines = array_values(array_filter($lines, 'is_string'));
            $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
            if (!$lines) return;
            // Store only bounded fingerprints, never full console output or secrets.
            $fingerprints = array_map(fn ($line) => hash('sha256', $line), $lines);
            $previous = $store->get('crash_log_fingerprints');
            $store->set('crash_log_fingerprints', array_slice($fingerprints, -100));
            if (!is_array($previous) || !$previous) return;
            // A known fingerprint must overlap; otherwise avoid reporting old log entries.
            $previousSet = array_fill_keys(array_filter($previous, 'is_string'), true);
            $overlap = -1;
            foreach ($fingerprints as $idx => $fingerprint) {
                if (isset($previousSet[$fingerprint])) $overlap = $idx;
            }
            if ($overlap < 0) return;
            $marker = false;
            foreach (array_slice($lines, $overlap + 1) as $line) {
                if (preg_match('/(?:detected server process in a crashed state|server detected as crashed)/i', $line)) {
                    $marker = true;
                    break;
                }
            }
            $pending = (bool) $store->get('crash_recovery_pending', false);
            if ($marker && !$pending) {
                $count = (int) $store->get('crash_count', 0) + 1;
                $store->set('crash_count', $count);
                $store->set('crash_recovery_pending', true);
                $notifier->sendCrash($server, $count);
                $pending = true;
            }
            if ($pending && $state === 'running' && !$marker) {
                $store->set('crash_recovery_pending', false);
                $notifier->sendRecovery($server, (int) $store->get('crash_count', 0));
            }
        } catch (\Throwable $e) {
            Log::warning('Discord Event Notifier crash log probe failed', [
                'server_uuid' => $server->uuid, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function handle(ExtensionSettingsRegistry $registry, DiscordNotifier $notifier): int
    {
        $settings = $registry->get('discord-event-notifier');
        if (!$settings->get('monitor_power')) {
            return self::SUCCESS;
        }

        // Prevent overlap if cron accidentally starts a second copy.
        if (!Cache::add('discord-event-notifier:polling', 1, now()->addMinutes(5))) {
            $this->warn('Poll already in progress.');
            return self::SUCCESS;
        }

        try {
            Server::query()->chunkById(100, function ($servers) use ($notifier) {
                foreach ($servers as $server) {

                    try {
                        $details = app(DaemonManager::class)->server($server)->details();
                        // Native Pterodactyl 2.0 Wings response shape:
                        // state and utilization.uptime (milliseconds).
                        $state = strtolower((string) data_get($details, 'state', ''));
                        if ($state === 'stopped') $state = 'offline';
                        // Intermediate states are skipped, so only stable states are compared.
                        if (!in_array($state, ['offline', 'running'], true)) continue;

                        $uptime = data_get($details, 'utilization.uptime');
                        $uptime = is_numeric($uptime) ? max(0, (int) $uptime) : null;

                        $store = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
                        $previous = $store->get('power_observation');
                        $current = ['state' => $state, 'uptime' => $uptime, 'checked_at' => now()->timestamp];
                        // Seed the initial observation silently.
                        if (is_array($previous)) {
                            $oldState = (string) ($previous['state'] ?? '');
                            $oldUptime = isset($previous['uptime']) && is_numeric($previous['uptime']) ? (int) $previous['uptime'] : null;
                            $elapsed = now()->timestamp - (int) ($previous['checked_at'] ?? now()->timestamp);

                            // Suppress inferred power events attributable to a confirmed Wings crash.
                            // Only suppress when the crash occurred after the previous observation:
                            // a later manual restart must still be reported normally.
                            $crashAt = (int) $store->get('crash_observed_at', 0);
                            $previousCheckedAt = (int) ($previous['checked_at'] ?? 0);
                            $crashInObservationWindow = $crashAt > 0
                                && $previousCheckedAt > 0
                                && $crashAt >= $previousCheckedAt
                                && $crashAt <= now()->timestamp
                                && now()->timestamp - $crashAt <= 180;

                            if ($state === 'running' && $oldState !== 'running') {
                                if (!$crashInObservationWindow) {
                                    $notifier->sendPowerState($server, 'started');
                                }
                            } elseif ($state === 'offline' && $oldState === 'running') {
                                $notifier->sendPowerState($server, 'stopped');
                            } elseif ($state === 'running' && $oldState === 'running' && $oldUptime !== null && $uptime !== null && $elapsed > 0 && $elapsed < 600 && $uptime + 15000 < $oldUptime) {
                                // Uptime from Wings is milliseconds. Restart is inferred, not proven.
                                if (!$crashInObservationWindow) {
                                    $notifier->sendPowerState($server, 'restarted');
                                }
                            }
                        }
                        // Crash recognition uses only NEW lines from a capped console snapshot.
                        // Initial and non-overlapping snapshots are silently baselined.
                        // Crash evidence arrives from authenticated Wings node agents, not game console output.
                        // Recovery only after an authenticated Wings crash event and a fresh running observation.
                        if ($state === 'running' && $store->get('crash_recovery_pending', false)
                            && now()->timestamp >= (int) $store->get('crash_observed_at', 0)) {
                            $store->set('crash_recovery_pending', false);
                            $notifier->sendRecovery($server, (int) $store->get('crash_count', 0));
                        }
                        $store->set('power_observation', $current);
                    } catch (\Throwable $e) {
                        // Do not interpret a Wings timeout as a server shutdown.
                        Log::warning('Discord Event Notifier Wings poll failed', [
                            'server_uuid' => $server->uuid, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        } finally {
            Cache::forget('discord-event-notifier:polling');
        }
        return self::SUCCESS;
    }
}
