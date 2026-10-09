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

                            if ($state === 'running' && $oldState !== 'running') {
                                $notifier->sendPowerState($server, 'started');
                            } elseif ($state === 'offline' && $oldState === 'running') {
                                $notifier->sendPowerState($server, 'stopped');
                            } elseif ($state === 'running' && $oldState === 'running' && $oldUptime !== null && $uptime !== null && $elapsed > 0 && $elapsed < 600 && $uptime + 15000 < $oldUptime) {
                                // Uptime from Wings is milliseconds. Restart is inferred, not proven.
                                $notifier->sendPowerState($server, 'restarted');
                            }
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
