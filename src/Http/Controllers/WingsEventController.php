<?php

namespace DiscordEventNotifier\Http\Controllers;

use DiscordEventNotifier\Services\DiscordNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;

final class WingsEventController
{
    public function __invoke(Request $request, DiscordNotifier $notifier): JsonResponse
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 4096, 413);
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 400);
        $nodeUuid = (string) ($payload['node_uuid'] ?? '');
        $uuid = (string) ($payload['server_uuid'] ?? '');
        $id = (string) ($payload['event_id'] ?? '');
        $ts = $payload['timestamp'] ?? null;
        $event = (string) ($payload['event'] ?? '');
        $uuidPattern = '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i';
        abort_unless(preg_match($uuidPattern, $nodeUuid) && preg_match($uuidPattern, $uuid), 422);
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $id) && $event === 'crashed', 422);
        abort_unless(is_int($ts) && abs(time() - $ts) <= 120, 401);
        $node = Node::query()->where('uuid', $nodeUuid)->first();
        abort_unless($node, 403);
        $key = (string) (new ExtensionSettings('discord-event-notifier'))->get('wings_node_key_'.$nodeUuid, '');
        abort_unless(strlen($key) >= 32, 403);
        $signature = $request->header('X-Wings-Event-Signature', '');
        abort_unless(is_string($signature) && hash_equals(hash_hmac('sha256', $raw, $key), $signature), 403);
        $server = Server::query()->where('uuid', $uuid)->where('node_id', $node->id)->first();
        abort_unless($server, 404);

        // Prevent replay of the same crash even when the agent retries after timeout.
        $dedupeKey = 'discord-notifier:node-crash:'.$nodeUuid.':'.$id;
        if (!Cache::add($dedupeKey, 1, now()->addDays(7))) {
            return response()->json(['status' => 'duplicate']);
        }
        $store = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
        $settings = new ExtensionSettings('discord-event-notifier');
        // Keep evidence even if webhook delivery is disabled.
        $count = (int) $store->get('crash_count', 0) + 1;
        $store->set('crash_count', $count);
        $store->set('crash_recovery_pending', true);
        $store->set('crash_observed_at', time());
        if ($settings->get('monitor_power', false)) {
            try {
                $notifier->sendCrash($server, $count);
            } catch (\Throwable $e) {
                Log::warning('Discord Event Notifier crash delivery failed', ['server_uuid' => $uuid, 'error' => $e->getMessage()]);
            }
        }
        return response()->json(['status' => 'accepted']);
    }
}
