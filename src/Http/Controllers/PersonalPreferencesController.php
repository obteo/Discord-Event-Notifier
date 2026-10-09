<?php

namespace DiscordEventNotifier\Http\Controllers;

use DiscordEventNotifier\Services\DiscordNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

class PersonalPreferencesController
{
    public function __construct(private readonly ExtensionSettingsRegistry $settings, private readonly DiscordNotifier $notifier) {}

    private function access(Request $request): void
    {
        abort_unless((bool) $this->settings->get('discord-event-notifier')->get('allow_personal_webhooks'), 403, 'Personal webhook configuration is disabled by the administrator.');
        abort_unless($request->user() !== null, 401);
    }

    private function servers(Request $request)
    {
        return Server::query()->where('owner_id', $request->user()->id)->orderBy('name')->get(['id', 'uuid', 'name']);
    }

    public function show(Request $request): JsonResponse
    {
        $this->access($request);
        $store = (new ExtensionSettings('discord-event-notifier'))->forUser($request->user());
        $allowed = $this->servers($request)->map(fn ($server) => [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'enabled' => (bool) data_get($store->get('server_enabled', []), $server->uuid, true),
            'has_override' => (int) (new ExtensionSettings('discord-event-notifier'))->forServer($server)->get('personal_webhook_owner_id', 0) === (int) $request->user()->id && (bool) (new ExtensionSettings('discord-event-notifier'))->forServer($server)->get('personal_webhook_url', ''),
        ]);
        // Personal server override URLs are never returned, even encrypted ones.
        return response()->json([
            'enabled' => (bool) $store->get('enabled', false),
            'has_webhook' => (bool) $store->get('webhook_url', ''),
            'events' => $this->events($store),
            'servers' => $allowed,
        ]);
    }

    private function events($store): array
    {
        $saved = $store->get('events', []);
        $defaults = ['started' => true, 'stopped' => true, 'restarted' => true, 'provision' => true, 'install' => true, 'reinstall' => true, 'backup' => true, 'failed' => true];
        return array_replace($defaults, is_array($saved) ? array_intersect_key($saved, $defaults) : []);
    }

    public function update(Request $request): JsonResponse
    {
        $this->access($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'remove_webhook' => ['sometimes', 'boolean'],
            'events' => ['required', 'array'],
            'events.*' => ['boolean'],
            'servers' => ['required', 'array'],
            'servers.*.enabled' => ['required', 'boolean'],
            'servers.*.webhook_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'servers.*.remove_override' => ['sometimes', 'boolean'],
        ]);
        $owned = $this->servers($request)->keyBy('uuid');
        $incoming = $data['servers'];
        foreach ($incoming as $uuid => $value) {
            abort_unless(is_string($uuid) && $owned->has($uuid), 403, 'You can only configure servers you own.');
            foreach (['webhook_url'] as $key) {
                if (!empty($value[$key])) $this->notifier->validateWebhook($value[$key]);
            }
        }
        if (!empty($data['webhook_url'])) $this->notifier->validateWebhook($data['webhook_url']);
        $store = (new ExtensionSettings('discord-event-notifier'))->forUser($request->user());
        $store->set('enabled', (bool) $data['enabled']);
        if (!empty($data['remove_webhook'])) $store->forget('webhook_url');
        elseif (!empty($data['webhook_url'])) $store->setSecret('webhook_url', $data['webhook_url']);
        $allowedEvents = $this->events($store);
        foreach ($data['events'] as $key => $on) {
            abort_unless(array_key_exists($key, $allowedEvents), 422, 'Unknown event.');
            $allowedEvents[$key] = (bool) $on;
        }
        $store->set('events', $allowedEvents);
        $flags = $store->get('server_enabled', []);
        $flags = is_array($flags) ? $flags : [];
        // Keep per-server secrets individually encrypted rather than embedding them in an ordinary JSON setting.
        foreach ($incoming as $uuid => $item) {
            $server = $owned->get($uuid);
            $flags[$uuid] = (bool) $item['enabled'];
            $scoped = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
            // A server can have only one owner, so its private override follows the owner, not subusers.
            if (!empty($item['remove_override'])) {
                $scoped->forget('personal_webhook_url');
                $scoped->forget('personal_webhook_owner_id');
            } elseif (!empty($item['webhook_url'])) {
                $scoped->setSecret('personal_webhook_url', $item['webhook_url']);
                $scoped->set('personal_webhook_owner_id', (int) $request->user()->id);
            }
        }
        $store->set('server_enabled', array_intersect_key($flags, $owned->all()));
        return $this->show($request);
    }

    public function test(Request $request): JsonResponse
    {
        $this->access($request);
        $key = 'discord-event-notifier:personal-test:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Try again in one minute.');
        RateLimiter::hit($key, 60);
        $data = $request->validate(['server_uuid' => ['nullable', 'string', 'max:36']]);
        $server = null;
        if (!empty($data['server_uuid'])) {
            $server = Server::query()->where('owner_id', $request->user()->id)->where('uuid', $data['server_uuid'])->firstOrFail();
        }
        $this->notifier->sendPersonalTest($request->user(), $server);
        return response()->json(['success' => true]);
    }
}
