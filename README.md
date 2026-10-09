# Discord Event Notifier v1.2.3 — by Teo

Pterodactyl 2.0 extension with separate administrator and server-owner Discord webhooks.

## What it includes
- Administrator webhook and operation notifications from v1.0.0.
- Account → Discord Notifications page for every server owner.
- One encrypted webhook per user and optional encrypted overrides per owned server.
- User-selectable events, server opt-in/out, and a rate-limited test endpoint.
- Existing v1.1.0 Wings poller (optional; requires cron). Power-state events are sampled and restart is inferred; crash attribution is **not** supported.
- Server ownership verified by the backend. Subusers do not inherit webhook settings.
- Discord requests use HTTPS, strict host/path validation, no redirects, and no mentions.

## Important
This build was prepared against the published Pterodactyl 2.0 extension API but **has not been integration-tested on a live Panel**. Use a staging Panel first, or make backups of database and extensions before updating. The frontend is a native JS module loaded through the Panel import map; SDK runtime compatibility must be verified using `php artisan p:extension:doctor` and a browser test.

## UI
Log in to Pterodactyl and open **Account → Discord Notifications** (`/account/discord-notifications`).
The global settings are at Admin → Extensions → Discord Event Notifier → Settings.

## First deployment / update
Do not install this over a production v1.0.0 without checking the currently supported upgrade workflow for your exact Panel build. Keep the old .pteroext and backup the Panel database. You can verify package files with `php artisan p:extension:doctor extensions/discord-event-notifier` on a staging copy.

## Polling
Only if you enable `Monitor Wings power state` in admin settings:
`* * * * * cd /var/www/pterodactyl && /usr/bin/php artisan discord-notifier:poll >> /var/log/discord-event-notifier.log 2>&1`
The first poll records the baseline silently. Cron is NOT required for operations or for test webhooks.

## Notes
The personal webhook does not depend on the administrator webhook being enabled. Server owners can receive provision/install/reinstall/backup events, provided the administrator permits personal webhooks. Power event collection is enabled/disabled globally via `monitor_power`. For performance with many servers, polling on a single node may need optimization.


## v1.2.1 compatibility fix

Use the underlying `ExtensionSettings` store for owner/server-scoped settings (`forUser`, `forServer`). Keep global settings on the registered definitions. Fixes `Unknown extension setting "events"` on account settings load and missing `set`/`setSecret`/`forget` methods on save. No database schema changes.

## v1.2.2 Wings compatibility fix

- Replaces the removed `DaemonServerRepository` with the Pterodactyl 2.0 `DaemonManager::server($server)->details()` API.
- Reads the Wings response as returned by `ServerClient::details()`.
- Normalizes `stopped` to `offline` and ignores intermediate states (`starting`, `stopping`) while comparing stable observations.
- Keeps existing settings and database records; no schema changes.
- The first successful observation creates a baseline without a message. A server that stops and restarts between polls may be missed. A crash cannot be reliably differentiated from a user-initiated stop.
- This package has passed local PHP syntax and ZIP integrity checks, but must be verified on the target Panel using `p:extension:doctor` and a live stop/start test.

## v1.2.3 verified Wings state shape fix

- Reads `state` and `utilization.uptime` from the **top-level** array returned by `DaemonManager::server($server)->details()`, not `data.state` or `data.utilization.uptime`.
- Preserves existing power observations and all global, personal, and per-server webhook preferences. No migrations or changes to core Panel files.
- On an existing installation, replace the extension package in place (`--replace --enable`). Reinstalling or removing the extension is unnecessary.
- Polling is still optional and is activated by the administrator's `Monitor Wings power state` setting plus a **single** system cron. Do not add a duplicate cron if already present.
- Verified through operator testing on Pterodactyl 2.0: `Restart Detected`, `Server Stopped`, and `Server Started` Discord messages were received after manually correcting these data paths. Package-level integration testing on a production Panel remains necessary.
- `Restart Detected` is an uptime inference rather than proof of a user restart request; transitions between samples can be missed.
