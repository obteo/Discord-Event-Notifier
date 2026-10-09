# Discord Event Notifier

**Discord notifications for Pterodactyl Panel 2.0** — administrator-wide alerts, personal webhooks for server owners, and optional per-server webhook overrides.

**Version:** 1.2.3  
**Author:** Teo  
**Platform:** Pterodactyl 2.0 extension

> This project is an independent community extension and is not affiliated with the Pterodactyl project or Discord.

## Features

- **Administrator notifications:** a global Discord webhook, customizable bot name, optional server UUID filter, and event toggles.
- **Personal notifications:** each server owner can save a personal webhook and choose their notification events.
- **Per-server overrides:** optionally direct an owned server's notifications to a different Discord webhook, or disable notifications for that server.
- **Discord embeds:** formatted messages with server names, UUIDs, event details, timestamps, and success/failure indicators where available.
- **Webhook tests:** test the administrator or personal webhook without restarting a game server.
- **Wings power-state monitoring:** optional polling for server start, stop, and inferred restart events.
- **Server operations:** notifications for provisioning, installation, reinstallation, and backup completion/failure events reported by the Panel.

## Requirements

- A working **Pterodactyl Panel 2.0** installation with extension support.
- **PHP 8.3+**.
- A Panel/SDK version compatible with the extension manifest (`panel: ^2.0.0-dev`, `sdk: ^2.0.0-beta.4`). Compatibility depends on your specific development build; run the extension doctor before installation.
- A **Discord channel webhook**.
- For automatic start/stop/restart notifications only: cron, `flock`, and a working connection from the Panel to Wings.

## Installation

1. Back up your Panel database and existing extension files before installing or updating.
2. Download the `discord-event-notifier-v1.2.3.pteroext` release package and upload it to the **Panel host** (for example, `/root/`).
3. On the Panel host, run:

   ```bash
   cd /var/www/pterodactyl
   php artisan p:extension:install /root/discord-event-notifier-v1.2.3.pteroext --enable
   php artisan p:extension:list
   ```

4. Open **Admin → Extensions → Discord Event Notifier → Settings** to configure the administrator webhook and notification options.

**Updating an existing installation:** after backing up your configuration and database, use the replacement option rather than uninstalling first:

```bash
cd /var/www/pterodactyl
php artisan p:extension:install /root/discord-event-notifier-v1.2.3.pteroext --replace --enable
php artisan p:extension:list
```

**Optional pre-install validation:** `p:extension:doctor` accepts an unpacked extension directory. Extract the package and check it before installation:

```bash
mkdir -p /root/discord-event-notifier-check
unzip -oq /root/discord-event-notifier-v1.2.3.pteroext -d /root/discord-event-notifier-check
cd /var/www/pterodactyl
php artisan p:extension:doctor /root/discord-event-notifier-check
```

Adjust paths if your Panel is not installed in `/var/www/pterodactyl`.

## Administrator configuration

Open **Admin → Extensions → Discord Event Notifier → Settings**.

| Setting | Purpose |
| --- | --- |
| Enable notifications | Controls notifications to the administrator webhook. |
| Discord webhook URL | Administrator's webhook (stored as a secret). |
| Webhook display name | Name shown for the administrator webhook messages. |
| Server UUID filter | Optional comma-separated list of full server UUIDs for administrator alerts; blank means all servers. |
| Server provisioned / installed / reinstalled / Backup finished | Toggle operation alerts. |
| Failed operations | Control failed operation alerts for the administrator webhook. |
| Allow personal Discord webhooks | Allow server owners to configure their own alerts. |
| Monitor Wings power state (requires cron) | Enable polling for power-state changes. |
| Server started / stopped / restarted | Toggle administrator power-state alerts. |

The administrator webhook is separate from personal user webhooks. Personal alerts require the **Allow personal Discord webhooks** setting, not a configured administrator webhook.

## Personal webhooks for server owners

A server owner can open **Account → Discord Notifications** (`/account/discord-notifications`) to:

1. Add or replace a personal Discord webhook.
2. Enable personal notifications and select the events to receive.
3. Enable or disable notifications for individual owned servers.
4. Optionally set a different webhook for a specific server.
5. Save settings and send a test notification.

Webhook URLs are not returned in plaintext by the account preferences API. Server-specific preferences are restricted to servers **owned by the signed-in user**; being a subuser does not grant ownership of webhook settings. A blank saved-webhook field does not mean the webhook is missing—look for the saved/configured indicator.

## Enable automatic Start / Stop / Restart alerts

**The extension does not install a cron entry automatically.** Operation notifications and webhook tests work without it, but Wings power-state notifications require the polling command to run regularly.

1. In administrator settings, enable **Monitor Wings power state (requires cron)**.
2. On the **Panel host** (not the Wings node), verify the correct PHP path with `command -v php` and the Panel directory.
3. If you do not already have a `discord-notifier:poll` cron job, add **one** scheduled run per minute:

   ```cron
   * * * * * /usr/bin/flock -n /tmp/discord-notifier.lock /usr/bin/php /var/www/pterodactyl/artisan discord-notifier:poll >> /var/log/discord-notifier.log 2>&1
   ```

   Install this line in the appropriate user's crontab with `crontab -e`. Adjust executable paths and ensure the cron user can read the Panel configuration, write extension settings/cache, and write the chosen log file. Do not add a duplicate if the command is already scheduled.

4. Check it manually:

   ```bash
   cd /var/www/pterodactyl
   php artisan discord-notifier:poll -v
   crontab -l | grep discord-notifier
   ```

**What to expect:** the first successful observation stores a silent baseline. Stop/start or restart a non-production test server after the baseline is recorded and allow up to a couple of minutes to receive messages.

### Power-state limitations

- Power events are **sampled**, not a real-time record of power commands. Rapid transitions between polls can be missed.
- **Server Restart Detected** means the observed uptime reset; it does not prove that an administrator clicked Restart.
- A stopped server may have been stopped intentionally or may have crashed. **Crash detection/attribution is not implemented.**
- Communication failures with Wings are logged, not interpreted as server stops.
- The command may finish with exit code `0` even when one server's poll fails; inspect Laravel logs when debugging.

## Troubleshooting

**Extension not enabled or the account page is missing**

```bash
cd /var/www/pterodactyl
php artisan p:extension:list
php artisan route:list --path=api | grep -i discord
```

Confirm that `discord-event-notifier` is enabled and has UI support, then refresh the browser.

**Test webhook works, but power-state alerts do not**

- Confirm **Monitor Wings power state** is enabled.
- Confirm the cron entry exists on the correct Panel host and runs only once per minute.
- Run `php artisan discord-notifier:poll -v` and inspect `storage/logs/laravel-YYYY-MM-DD.log` for `Discord Event Notifier Wings poll failed` or delivery warnings.
- Allow one successful observation to establish a baseline before testing a change.
- Make sure the user enabled notifications for that server, or that the administrator webhook and relevant global event setting are enabled.

**No notification reaches Discord**

- Use the built-in test button first.
- Check the correct Discord channel/webhook (administrator, personal, or per-server override).
- Verify the webhook URL is still valid and outbound HTTPS connections to Discord are allowed.
- Review Laravel logs for `Discord Event Notifier delivery failed`.

**Logs and useful commands**

```bash
cd /var/www/pterodactyl
php artisan p:extension:list
php artisan discord-notifier:poll -v
tail -n 50 /var/log/discord-notifier.log
tail -n 100 storage/logs/laravel-$(date +%F).log
```

## Security notes

- Webhook URLs are credentials: do not publish them in GitHub issues, screenshots, configuration examples, or logs.
- The extension validates Discord HTTPS webhook URLs, rejects redirects, and disables message mentions.
- User- and server-scoped webhook values are stored using the extension settings secret-storage methods.
- A Panel administrator should review and test the extension before rolling it out to customers.

## Version history

- **1.2.3** — Fix Wings response paths (`state` and `utilization.uptime`); verified by live operator tests for Start, Stop, and inferred Restart.
- **1.2.2** — Switch polling to Pterodactyl 2.0 `DaemonManager` and normalize stopped/offline states.
- **1.2.1** — Fix personal settings load and save using user/server-scoped settings storage.
- **1.2.0** — Add account UI, personal webhook settings, per-server overrides, and Wings polling.
- **1.0.0** — Initial administrator webhook and server operation notifications.

## Credits

**Created by Teo.** Built for the Pterodactyl 2.0 community.
