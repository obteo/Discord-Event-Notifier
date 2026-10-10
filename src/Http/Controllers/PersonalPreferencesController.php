<?php

namespace DiscordEventNotifier\Http\Controllers;

use DiscordEventNotifier\Services\DiscordNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
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

    private const PERMISSION = 'ext.discord-event-notifier.manage';

    private function canManage(User $user, Server $server): bool
    {
        return (int) $server->owner_id === (int) $user->id || $user->can(self::PERMISSION, $server);
    }

    private function servers(Request $request)
    {
        $user = $request->user();
        // Only servers with an actual owner/subuser relationship. A panel administrator
        // does not implicitly subscribe to every server just because they are root.
        $servers = Server::query()
            ->where(function ($query) use ($user) {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('subusers', fn ($q) => $q->where('user_id', $user->id));
            })->orderBy('name')->get();
        return $servers->filter(fn (Server $server) => $this->canManage($user, $server))->values();
    }

    private function userServerStore(User $user): ExtensionSettings
    {
        return (new ExtensionSettings('discord-event-notifier'))->forUser($user);
    }

    private function overrideKey(Server $server): string
    {
        return 'server_webhook_'.$server->uuid;
    }

    private function overrideExists(User $user, Server $server): bool
    {
        if ((string) $this->userServerStore($user)->get($this->overrideKey($server), '') !== '') return true;
        if ((int) $server->owner_id !== (int) $user->id) return false;
        // Preserve existing v1.2.x owner overrides.
        $legacy = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
        return (int) $legacy->get('personal_webhook_owner_id', 0) === (int) $user->id
            && (string) $legacy->get('personal_webhook_url', '') !== '';
    }

    public function show(Request $request): JsonResponse
    {
        $this->access($request);
        $store = (new ExtensionSettings('discord-event-notifier'))->forUser($request->user());
        $allowed = $this->servers($request)->map(fn ($server) => [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'enabled' => (bool) data_get($store->get('server_enabled', []), $server->uuid, true),
            'role' => (int) $server->owner_id === (int) $request->user()->id ? 'owner' : 'subuser',
            'has_override' => $this->overrideExists($request->user(), $server),
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
        $defaults = ['started' => true, 'stopped' => true, 'restarted' => true, 'crashed' => true, 'recovered' => true, 'provision' => true, 'install' => true, 'reinstall' => true, 'backup' => true, 'failed' => true];
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
            abort_unless(is_string($uuid) && $owned->has($uuid), 403, 'You can only configure servers for which you have Discord Notifications permission.');
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
            // Per-user, per-server encrypted override; never shared with another subuser.
            $key = $this->overrideKey($server);
            if (!empty($item['remove_override'])) {
                $store->forget($key);
                // Old owner overrides remain supported until explicitly removed.
                if ((int) $server->owner_id === (int) $request->user()->id) {
                    $legacy = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
                    if ((int) $legacy->get('personal_webhook_owner_id', 0) === (int) $request->user()->id) {
                        $legacy->forget('personal_webhook_url');
                        $legacy->forget('personal_webhook_owner_id');
                    }
                }
            } elseif (!empty($item['webhook_url'])) {
                $store->setSecret($key, $item['webhook_url']);
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
            $server = $this->servers($request)->firstWhere('uuid', $data['server_uuid']);
            abort_unless($server !== null, 403, 'Discord Notifications permission is required for this server.');
        }
        $this->notifier->sendPersonalTest($request->user(), $server);
        return response()->json(['success' => true]);
    }
}
