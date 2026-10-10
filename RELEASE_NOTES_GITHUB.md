## Discord Event Notifier v1.3.3 (Pre-release)

This update improves Discord notifications following a **confirmed Wings crash**. Previously, an automatically restarted server could trigger three messages: `Server Crashed`, `Server Restart Detected`, and `Server Recovered`. When the Wings crash occurred after the previous state observation, the inferred restart/start notification is now suppressed. The expected sequence is: **Server Crashed → Server Recovered**.

### Changes
- Avoid an unnecessary inferred restart/start notification after a recent, signed Wings crash event.
- Keep normal restarts and power notifications enabled.
- Preserve per-user and per-server webhook settings and subuser access controls.
- No changes to the v1.3.2 node agent; reinstalling the agent is **not required**.

### Installation / upgrade
1. Back up your Panel database and extension.
2. Upload `discord-event-notifier-v1.3.3.pteroext` to your Panel.
3. Extract and run `php artisan p:extension:doctor /path/to/extracted-extension`.
4. Install: `php artisan p:extension:install /path/to/discord-event-notifier-v1.3.3.pteroext --replace --enable`.
5. Keep the existing `discord-notifier:poll` cron and running v1.3.2 Wings agent.
6. Verify `php artisan p:extension:list`, then check normal restart and crash/recovery notifications.

### Requirements
Pterodactyl Panel 2.0 development builds meeting the manifest requirements; PHP 8.3+; Python 3 + systemd/journalctl for the optional Wings node agent.

### Status
**Pre-release:** the v1.3.2 crash → Discord → recovery path was tested successfully on one node; the v1.3.3 notification suppression has not yet been tested on the live panel.
