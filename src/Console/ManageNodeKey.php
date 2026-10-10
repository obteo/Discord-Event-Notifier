<?php

namespace DiscordEventNotifier\Console;

use Illuminate\Console\Command;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Extensions\ExtensionSettings;

final class ManageNodeKey extends Command
{
    protected $signature = 'discord-notifier:node-key {node_uuid} {--rotate}';
    protected $description = 'Create or rotate a separate signing key for a Wings node.';

    public function handle(): int
    {
        $uuid = strtolower((string) $this->argument('node_uuid'));
        if (!Node::query()->where('uuid', $uuid)->exists()) {
            $this->error('Unknown node UUID. Use the Pterodactyl node UUID, not a game server UUID.');
            return self::FAILURE;
        }
        $store = new ExtensionSettings('discord-event-notifier');
        $setting = 'wings_node_key_'.$uuid;
        if ($store->get($setting) && !$this->option('rotate')) {
            $this->error('Key already exists. Use --rotate to replace it (update node agent too).');
            return self::FAILURE;
        }
        $key = bin2hex(random_bytes(32));
        $store->setSecret($setting, $key);
        $this->line('NODE_UUID='.$uuid);
        $this->line('NODE_KEY='.$key);
        $this->warn('Save this key privately now. It will not be displayed again.');
        return self::SUCCESS;
    }
}
