<?php

namespace DiscordEventNotifier;

use DiscordEventNotifier\Services\DiscordNotifier;
use DiscordEventNotifier\Console\PollServerStates;
use DiscordEventNotifier\Console\ManageNodeKey;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class DiscordEventNotifierProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->registerWebRoutes(__DIR__.'/../routes/web.php');
        // Registered by the extension SDK: visible in the server subuser permission editor.
        $this->registerPermissions('Discord Notifications', [
            'manage' => 'Configure personal Discord notifications for this server.',
        ]);
        if ($this->app->runningInConsole()) {
            $this->commands([PollServerStates::class, ManageNodeKey::class]);
        }
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('enabled', 'enabled', true, ['boolean'])
                ->label('Enable notifications')->field('toggle'),
            ExtensionSettingDefinition::make('webhook_url', 'webhook_url', '', ['nullable', 'string', 'max:2000'])
                ->label('Discord webhook URL')->help('Create a Discord channel webhook. Kept encrypted; leave blank to retain the previous value.')->secret(),
            ExtensionSettingDefinition::make('bot_name', 'bot_name', 'Pterodactyl Notifier', ['required', 'string', 'max:80'])
                ->label('Webhook display name')->field('text'),
            ExtensionSettingDefinition::make('server_uuids', 'server_uuids', '', ['nullable', 'string', 'max:4000'])
                ->label('Server UUID filter')->help('Optional: comma-separated FULL server UUIDs. Leave empty for all servers.')->field('text'),
            ExtensionSettingDefinition::make('notify_provision', 'notify_provision', true, ['boolean'])
                ->label('Server provisioned')->field('toggle'),
            ExtensionSettingDefinition::make('notify_install', 'notify_install', true, ['boolean'])
                ->label('Server installed')->field('toggle'),
            ExtensionSettingDefinition::make('notify_reinstall', 'notify_reinstall', true, ['boolean'])
                ->label('Server reinstalled')->field('toggle'),
            ExtensionSettingDefinition::make('notify_backup', 'notify_backup', true, ['boolean'])
                ->label('Backup finished')->field('toggle'),
            ExtensionSettingDefinition::make('allow_personal_webhooks', 'allow_personal_webhooks', true, ['boolean'])
                ->label('Allow personal Discord webhooks')->field('toggle')
                ->help('Server owners and authorized subusers may configure personal webhooks from Account > Discord Notifications.'),
            ExtensionSettingDefinition::make('monitor_power', 'monitor_power', false, ['boolean'])
                ->label('Monitor Wings power state (requires cron)')->field('toggle')
                ->help('Requires running discord-notifier:poll every minute. First check records a baseline without sending notifications.'),
            ExtensionSettingDefinition::make('notify_started', 'notify_started', true, ['boolean'])
                ->label('Server started')->field('toggle'),
            ExtensionSettingDefinition::make('notify_stopped', 'notify_stopped', true, ['boolean'])
                ->label('Server stopped (reason unknown)')->field('toggle'),
            ExtensionSettingDefinition::make('notify_restarted', 'notify_restarted', true, ['boolean'])
                ->label('Server restarted (inferred from uptime)')->field('toggle'),
            ExtensionSettingDefinition::make('notify_crashed', 'notify_crashed', true, ['boolean'])
                ->label('Server crashed (experimental log detection)')->field('toggle'),
            ExtensionSettingDefinition::make('notify_recovered', 'notify_recovered', true, ['boolean'])
                ->label('Server recovered after detected crash')->field('toggle'),
            ExtensionSettingDefinition::make('notify_failed', 'notify_failed', true, ['boolean'])
                ->label('Failed operations')->field('toggle'),
        ]));

        $this->listenToServerOperations(function (OperationCompleted $event): void {
            app(DiscordNotifier::class)->sendOperation($event);
        });
    }
}
