# Discord Event Notifier

Discord notifications for **Pterodactyl 2.0**, created by **Teo**.

## Version 1.3.0 (crash detection preview)

Builds on the tested v1.2.3 and adds **evidence-based crash alerts**, **recovery alerts**, a per-server crash counter, and separate admin/user notification toggles for `crashed` and `recovered`.

### Features

- Admin Discord webhook and optional full-server UUID filter
- Personal webhooks for server owners, with optional per-server webhook overrides
- Owner-controlled event and server notification switches
- Installation, reinstall, provisioning, and backup operation notifications
- Wings state notifications: Started, Stopped, Restart Detected (uptime inference)
- **Server Crashed**, only when a *new* explicit Wings crash marker is visible in recent server console logs
- **Server Recovered**, when Wings reports Running after such a confirmed crash marker
- Per-server crash count (stored internally and included in crash messages)
- Rolling log fingerprints to suppress repeated alerts for the same visible log entry
- No changes to Panel core files, Wings or game server processes

### Important limitations

**Crash detection is not comprehensive.** Wings' `ServerClient::logs(100)` is a capped snapshot of recent console output, not a durable crash-event feed. If Wings does not expose its crash message in those lines, or the log rotates/overflows between checks, **no crash notification is emitted**. No heuristic based only on offline state or uptime is labelled as a crash. Log messages may also be game-generated and cannot be cryptographically authenticated as Wings messages.

Recovery is inferred from a previously observed crash marker and the Running state. A crash that restarts between one-minute checks may be detected if its marker is retained in the last 100 lines, but can still be missed. Each match window increments the crash counter once, not once per crash during the window. A user-initiated in-game restart can sometimes be treated as a crash by Wings itself.

The first log snapshot after enabling a server is used as a silent baseline. If no overlap between successive capped log snapshots is found, the new snapshot is also treated as a silent baseline to avoid replaying historical log lines.

**v1.3.0 is an unverified candidate:** PHP lint/ZIP checks are not equivalent to live Wings integration testing. Test on a non-production Panel first.

## Installation

1. Back up the Panel database and the installed extension directory.
2. Upload `discord-event-notifier-v1.3.0.pteroext` to the Panel host.
3. Unpack the archive to a temporary directory and validate:

```bash
mkdir -p /root/discord-notifier-check-130
unzip -oq /root/discord-event-notifier-v1.3.0.pteroext -d /root/discord-notifier-check-130
cd /var/www/pterodactyl
php artisan p:extension:doctor /root/discord-notifier-check-130
```

4. If the checks pass, install/upgrade using:

```bash
php artisan p:extension:install /root/discord-event-notifier-v1.3.0.pteroext --replace --enable
php artisan p:extension:list
```

5. Configure the **Admin → Extensions → Discord Event Notifier → Settings** webhook and enable `Monitor Wings power state (requires cron)`. Enable/disable `Server crashed` and `Server recovered` independently.
6. Server owners can open **Account → Discord Notifications** to set their personal webhook and choose events.

## Cron (manual)

The extension **does not install a cron automatically**. If the Panel host already has the following job, **do not add it twice**:

```cron
* * * * * /usr/bin/flock -n /tmp/discord-notifier.lock /usr/bin/php /var/www/pterodactyl/artisan discord-notifier:poll >> /var/log/discord-notifier.log 2>&1
```

Paths may vary between installations. `artisan schedule:run` does **not** automatically run this extension command.

## Monitoring and troubleshooting

```bash
cd /var/www/pterodactyl
php artisan discord-notifier:poll -v
php artisan p:extension:list
crontab -l | grep discord-notifier
```

Server poll failures may appear in `storage/logs/laravel-YYYY-MM-DD.log`. An exit code of `0` does not guarantee every server was queried: the extension catches and logs per-server errors. The initial pass seeds its state and log baseline without sending old alerts.

Check Wings console history if a known crash produces no notification. Only explicit Wings crash strings such as `Detected server process in a crashed state` / `Server detected as crashed` can trigger detection. Do not share secret Discord webhook URLs in logs or screenshots.

## Change log

- **1.3.0**: Conservative Wings crash/recovery detection, crash counter, deduplication, configurable crash/recovery notifications.
- **1.2.3**: Verified Wings state polling for `state` and `utilization.uptime` in Pterodactyl 2.0.
- **1.2.1**: Corrected account preferences storage handling.
- **1.2.0**: Added per-user and per-server Discord webhook settings.

## Author

**Teo**

## Distribution note

This source archive contains the deployable PHP and browser bundle of this extension, rather than the original React build project. The v1.3.0 crash detector is experimental and has not been validated on a live Wings instance.
