<?php

namespace DiscordEventNotifier\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

class DiscordNotifier
{
    public function __construct(private readonly ExtensionSettingsRegistry $settings) {}

    private function option(string $key): mixed
    {
        return $this->settings->get('discord-event-notifier')->get($key);
    }

    public function allowsServer(string $uuid): bool
    {
        $filter = trim((string) ($this->option('server_uuids') ?? ''));
        return $filter === '' || in_array($uuid, array_map('trim', explode(',', $filter)), true);
    }

    public function sendPowerState(Server $server, string $event): void
    {
        if (!in_array($event, ['started', 'stopped', 'restarted'], true)) return;

        $titles = [
            'started' => ['Server Started', 0x22c55e, 'Wings reports that the server is running.'],
            'stopped' => ['Server Stopped', 0xef4444, 'Wings reports that the server is offline. The reason is unknown.'],
            'restarted' => ['Server Restart Detected', 0x3b82f6, 'A reset in server uptime suggests a restart. This is an inference.'],
        ];
        [$title, $color, $description] = $titles[$event];
        $embed = [
            'title' => $title,
            'description' => $description,
            'color' => $color,
            'fields' => [
                ['name' => 'Server', 'value' => $this->sanitize($server->name), 'inline' => true],
                ['name' => 'State', 'value' => ucfirst($event), 'inline' => true],
                ['name' => 'Server UUID', 'value' => '`'.$server->uuid.'`', 'inline' => false],
            ],
        ];
        if ($this->option('enabled') && $this->option('monitor_power') && $this->allowsServer($server->uuid) && $this->option('notify_'.$event)) $this->post($embed);
        $this->postPersonal($server, $embed, $event);
    }

    public function sendOperation(OperationCompleted $event): void
    {
        if (!in_array($event->operation, ['provision', 'install', 'reinstall', 'backup'], true)) return;

        $uuid = $event->serverUuid;

        $server = Server::query()->where('uuid', $uuid)->first();
        $name = $server?->name ?? 'Unknown / removed server';
        $operation = ucfirst($event->operation);
        $title = $operation.' '.($event->successful ? 'completed' : 'failed');
        $embed = [
            'title' => $title,
            'description' => 'A Pterodactyl server operation has finished.',
            'color' => $event->successful ? 0x22c55e : 0xef4444,
            'fields' => [
                ['name' => 'Server', 'value' => $this->sanitize($name), 'inline' => true],
                ['name' => 'Operation', 'value' => $operation, 'inline' => true],
                ['name' => 'Result', 'value' => $event->successful ? 'Success' : 'Failed', 'inline' => true],
                ['name' => 'Server UUID', 'value' => '`'.$uuid.'`', 'inline' => false],
                ['name' => 'Resource UUID', 'value' => '`'.$event->resourceUuid.'`', 'inline' => false],
            ],
        ];
        if ($this->option('enabled') && $this->allowsServer($uuid) && $this->option('notify_'.$event->operation) && ($event->successful || $this->option('notify_failed'))) $this->post($embed);
        if ($server) $this->postPersonal($server, $embed, $event->successful ? $event->operation : 'failed');
    }

    public function sendTest(): void
    {
        $this->post([
            'title' => 'Test notification',
            'description' => 'Discord Event Notifier is connected successfully.',
            'color' => 0x5865f2,
            'fields' => [['name' => 'Source', 'value' => 'Pterodactyl Panel', 'inline' => true]],
        ], true);
    }

    public function validateWebhook(string $url): void
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), ['discord.com', 'discordapp.com'], true)
            || !preg_match('~^/api/webhooks/[0-9]+/[A-Za-z0-9._-]+/?$~D', $parts['path'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['webhook_url' => 'Enter a valid Discord webhook URL.']);
        }
    }

    private function postPersonal(Server $server, array $embed, string $event): void
    {
        if (!$this->option('allow_personal_webhooks')) return;
        $owner = User::query()->find($server->owner_id);
        if (!$owner) return;
        $store = (new ExtensionSettings('discord-event-notifier'))->forUser($owner);
        if (!$store->get('enabled', false)) return;
        $events = $store->get('events', []);
        if (is_array($events) && array_key_exists($event, $events) && !$events[$event]) return;
        $flags = $store->get('server_enabled', []);
        if (is_array($flags) && array_key_exists($server->uuid, $flags) && !$flags[$server->uuid]) return;
        $scoped = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
        $override = (int) $scoped->get('personal_webhook_owner_id', 0) === (int) $owner->id
            ? (string) $scoped->get('personal_webhook_url', '') : '';
        $url = $override ?: (string) $store->get('webhook_url', '');
        if ($url === '') return;
        $this->deliver($url, $embed, 'Pterodactyl Notifier');
    }

    public function sendPersonalTest(User $user, ?Server $server = null): void
    {
        $store = (new ExtensionSettings('discord-event-notifier'))->forUser($user);
        $url = (string) $store->get('webhook_url', '');
        if ($server) {
            $scoped = (new ExtensionSettings('discord-event-notifier'))->forServer($server);
            if ((int) $scoped->get('personal_webhook_owner_id', 0) === (int) $user->id) {
                $url = (string) ($scoped->get('personal_webhook_url', '') ?: $url);
            }
        }
        if ($url === '') throw \Illuminate\Validation\ValidationException::withMessages(['webhook_url' => 'Save a webhook before testing.']);
        $this->deliver($url, [
            'title' => 'Test notification',
            'description' => 'Your personal Discord webhook is connected successfully.',
            'color' => 0x5865f2,
        ], 'Pterodactyl Notifier', true);
    }

    private function sanitize(string $value): string
    {
        return mb_substr(str_replace(['@', '`'], ['＠', "'"], $value), 0, 1000);
    }

    private function post(array $embed, bool $throw = false): void
    {
        $this->deliver((string) ($this->option('webhook_url') ?? ''), $embed,
            (string) ($this->option('bot_name') ?: 'Pterodactyl Notifier'), $throw);
    }

    private function deliver(string $url, array $embed, string $name, bool $throw = false): void
    {
        try {
            $this->validateWebhook($url);
            $payload = [
                'username' => mb_substr($name, 0, 80),
                'allowed_mentions' => ['parse' => []],
                'embeds' => [array_merge($embed, ['timestamp' => now()->toIso8601String(), 'footer' => ['text' => 'Discord Event Notifier • by Teo']])],
            ];
            $response = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->post($url, $payload);
            if (!$response->successful()) throw new \RuntimeException('Discord returned HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('Discord Event Notifier delivery failed', ['error' => $e->getMessage()]);
            if ($throw) throw $e;
        }
    }
}
