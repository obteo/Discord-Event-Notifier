# Wings Crash Agent — Complete Installation Guide (v1.3.3)

**Discord Event Notifier by Teo · Pterodactyl Panel 2.0 · Pre-release**

This guide is for **first-time installations**. You do **not** need to install v1.3.2 first. The v1.3.3 Wings agent is the same as the tested v1.3.2 agent.

The Panel extension alone handles normal power notifications. **Confirmed Wings crash alerts require an agent on every Wings node you want to monitor.** Install it on the **Wings VPS**, not just the Panel VPS (unless Panel and Wings share a machine).

## 1. Install or upgrade the Panel extension

Back up the Panel database and existing extension configuration before upgrading. Upload `discord-event-notifier-v1.3.3.pteroext` to `/root/` on the **Panel** host, then run:

```bash
cd /var/www/pterodactyl
mkdir -p /root/discord-notifier-check-133
unzip -oq /root/discord-event-notifier-v1.3.3.pteroext -d /root/discord-notifier-check-133
php artisan p:extension:doctor /root/discord-notifier-check-133
```

Only if the compatibility check passes and backups are complete:

```bash
php artisan p:extension:install /root/discord-event-notifier-v1.3.3.pteroext --replace --enable
php artisan p:extension:list
php artisan route:list --path=extensions/discord-event-notifier/wings-event -v
```

The expected route is `POST /extensions/discord-event-notifier/wings-event`. It must have an effective CSRF exemption while enforcing signed HMAC payloads.

Check the endpoint over HTTPS (replace with your **actual** Panel domain):

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST \
  -H 'Content-Type: application/json' -d '{}' \
  https://YOUR-PANEL-DOMAIN/extensions/discord-event-notifier/wings-event
```

**Expected: HTTP 422** for missing required fields. `419` suggests CSRF blocking; `404` suggests the route or URL is wrong. **This test does not establish that HMAC authentication works.** Do not install agents until the route is accessible and secured.

### Keep the existing polling cron

Check that the existing Panel poller is scheduled **once per minute** (do not duplicate an existing cron):

```bash
crontab -l | grep discord-notifier
```

For a new installation, if no existing scheduler invokes this command, add this to an appropriate cron (running as a user with permission to run the Panel artisan command):

```cron
* * * * * /usr/bin/flock -n /tmp/discord-notifier.lock /usr/bin/php /var/www/pterodactyl/artisan discord-notifier:poll >> /var/log/discord-notifier.log 2>&1
```

Enable **Monitor Wings power state** and the desired crash/recovery events in extension settings. Configure the appropriate Discord webhook(s).

## 2. Get the Wings node UUID

Run on the **Panel** host:

```bash
cd /var/www/pterodactyl
php artisan tinker --execute='\Pterodactyl\Models\Node::query()->get(["id","name","uuid","fqdn"])->each(fn($n) => dump(["name"=>$n->name,"uuid"=>$n->uuid,"fqdn"=>$n->fqdn]));'
```

Use the **node UUID**, not the game server UUID. Repeat the following steps **for each node**.

## 3. Generate a unique signing key for each node

On the **Panel** host:

```bash
cd /var/www/pterodactyl
php artisan discord-notifier:node-key YOUR-NODE-UUID
```

The key prints **once** as `NODE_KEY=...`. Keep it private. If a key already exists, do **not** rotate it without also updating that node's agent. The `--rotate` option revokes the old key.

Do not post node keys or Discord webhook URLs in GitHub issues, screenshots or logs.

## 4. Install the agent on the Wings host

Requirements: Linux with systemd, `journalctl`, Python 3 and outbound HTTPS to the Panel. The agent uses only Python standard-library modules.

Upload `discord-wings-agent-v1.3.3.zip` to `/root/` on the **Wings node**, then run:

```bash
mkdir -p /tmp/discord-wings-install
unzip -oq /root/discord-wings-agent-v1.3.3.zip -d /tmp/discord-wings-install
install -d -m 755 /opt/discord-wings-agent
install -d -m 700 /etc/discord-wings-agent /var/lib/discord-wings-agent
install -m 755 /tmp/discord-wings-install/node-agent/wings_crash_agent.py /opt/discord-wings-agent/wings_crash_agent.py
install -m 644 /tmp/discord-wings-install/node-agent/discord-wings-agent.service /etc/systemd/system/discord-wings-agent.service
install -m 600 /tmp/discord-wings-install/node-agent/config.example.json /etc/discord-wings-agent/config.json
```

**Important:** the Python program reads its configuration from `/etc/discord-wings-agent/config.json`, **not** `/opt/discord-wings-agent/config.json`.

## 5. Configure the agent

Edit the root-only configuration locally:

```bash
nano /etc/discord-wings-agent/config.json
```

Example (replace all placeholders):

```json
{
  "panel_url": "https://YOUR-PANEL-DOMAIN",
  "node_uuid": "YOUR-PTERODACTYL-NODE-UUID",
  "node_key": "YOUR-UNIQUE-NODE-KEY",
  "cursor_file": "/var/lib/discord-wings-agent/cursor"
}
```

Never paste the configured file into public chats or issues. Set permissions:

```bash
chown root:root /etc/discord-wings-agent/config.json
chmod 600 /etc/discord-wings-agent/config.json
chmod 700 /etc/discord-wings-agent /var/lib/discord-wings-agent
python3 -m py_compile /opt/discord-wings-agent/wings_crash_agent.py
systemd-analyze verify /etc/systemd/system/discord-wings-agent.service
```

## 6. Start and verify the service

```bash
systemctl daemon-reload
systemctl enable --now discord-wings-agent
systemctl status discord-wings-agent --no-pager -l
journalctl -u discord-wings-agent -n 30 --no-pager
ls -l /var/lib/discord-wings-agent/
```

Expected: `active (running)` and, on first launch, `Journal baseline recorded; historical entries ignored.` A cursor file should appear at `/var/lib/discord-wings-agent/cursor`.

If you see `FileNotFoundError: /etc/discord-wings-agent/config.json`, the config was saved to the wrong directory. If you see `HTTP 403`, check node UUID, HMAC key and clock synchronization. `HTTP 419` points to a CSRF middleware problem. The service logs should not contain secrets.

**Note:** The initial baseline intentionally skips historical journal entries; only new crash events will be forwarded. The agent must run on every monitored Wings node, with a different signing key per node. Do not start duplicate agent instances using the same cursor file.

## 7. Verify signed communication safely

A POST containing `{}` should return `422`, showing that the endpoint is reached, **not** that authentication is valid. To check HMAC without triggering a notification, use a correctly signed `crashed` event for a *nonexistent* server UUID: a `404` means the signature and node were accepted before the server lookup; a `403` means authentication failed. A script using Python `hmac`/`hashlib` can perform this check without printing the key; avoid publishing the signed payload or headers.

After verifying HTTPS and authentication, perform an actual crash/recovery test **only on a disposable, unoccupied game server**. The expected Discord sequence is `Server Crashed` followed by `Server Recovered`. The v1.3.3 suppression of an extra inferred restart message still needs live validation.

## 8. Upgrade from an existing v1.3.2 agent

**No agent reinstall is needed.** Upgrade only the Panel extension to v1.3.3, keep the existing node keys, service and once-per-minute poller, and test the updated crash/recovery messaging. Do not rotate keys just for this upgrade.

## 9. Troubleshooting

```bash
# On the Panel host:
cd /var/www/pterodactyl
php artisan p:extension:list
php artisan route:list --path=extensions/discord-event-notifier/wings-event -v
php artisan discord-notifier:poll -v

# On each Wings host:
systemctl is-active wings discord-wings-agent
journalctl -u discord-wings-agent -n 60 --no-pager
journalctl -u wings --since '1 hour ago' --no-pager | grep -i 'crashed state'
```

**Limitations:** This is a pre-release. It detects explicit crash messages from the Wings system journal; it cannot guarantee detection of every possible failure. Webhook delivery is best-effort. Use a persistent Laravel cache for replay suppression, and keep the server clocks synchronized (request timestamp tolerance is 120 seconds).
