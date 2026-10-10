# Discord Event Notifier v1.3.3 — Wings Crash Agent Preview

**By Teo** · Pterodactyl 2.0 extension · Experimental/pre-release

This build preserves the v1.3.1 user, subuser, admin and per-server Discord webhooks, power monitoring, and operation notifications. It adds a **node-side Wings journal agent** for confirmed crash events, because Wings' process crash messages do not reliably appear in the game console API.

## What changed in v1.3.3

- Suppress inferred **Server Restart Detected** (or **Server Started**) when a confirmed Wings crash happened after the previous poll and within the previous three minutes.
- Keep normal/manual restart notifications unchanged.
- Preserve existing webhooks, permissions, node-side agent, HMAC signing, crash/recovery detection and deduplication.
- **Agent unchanged:** nodes already running the v1.3.2 agent do not need to reinstall it.

## How it works

1. The Wings node agent checks new `wings.service` journal entries every 10 seconds. It only accepts the explicit Wings message `detected server as entering a crashed state; running crash handler` with `server=<full-uuid>`.
2. The agent sends an HTTPS POST to the Panel at `/extensions/discord-event-notifier/wings-event`, signed with HMAC-SHA256 and a **separate secret per node**.
3. The Panel verifies node existence, timestamp, signature, server membership and event ID. Replays are suppressed for 7 days using Laravel cache.
4. On accepted crash evidence, it increments the server crash counter and delivers the existing Discord `Server Crashed` notification.
5. The existing `discord-notifier:poll` job sends `Server Recovered` after a subsequent `running` observation.

**Limitations:** This does not report all possible crashes; it depends on Wings journal logging. It does not include a crash-loop aggregation notification. A restart inferred from Wings uptime is suppressed when it matches a recently confirmed Wings crash after the previous power observation; ordinary restarts remain enabled. Separate stop messages can still occur depending on polling timing. Webhook delivery is best effort. Cache replay suppression requires a persistent cache backend. Node agents must reach the Panel over HTTPS.

## Important: compatibility check before enabling

The v2 extension documentation supports web routes under `/extensions/{id}` but actual route middleware can differ between development builds. Before exposing an endpoint on a production Panel, verify that `POST /extensions/discord-event-notifier/wings-event` is registered, that its CSRF exemption works, and that unsigned requests return 4xx. **Do not deploy agents until these checks pass.**

## Panel — installation

Back up the Panel database and existing extension files before replacing v1.3.2. Upload the `.pteroext` to the Panel VPS. Extract it into a temporary directory and run:

```bash
cd /var/www/pterodactyl
php artisan p:extension:doctor /root/discord-notifier-check-133
```

Then (only after confirming doctor and backups):

```bash
php artisan p:extension:install /root/discord-event-notifier-v1.3.3.pteroext --replace --enable
php artisan p:extension:list
php artisan route:list --path=extensions | grep wings-event
```

Keep your existing once-per-minute `discord-notifier:poll` cron, and enable **Monitor Wings power state** in Admin → Extensions → Settings. Do not add a duplicate cron.

## Generate a per-node key

Find the **NODE UUID**, not the game server UUID. On the Panel VPS:

```bash
cd /var/www/pterodactyl
php artisan discord-notifier:node-key NODE_UUID
```

The command prints `NODE_UUID` and `NODE_KEY` only once. Put these securely in each Wings node's agent config. Never commit credentials to GitHub. To replace a compromised key use `--rotate` and update the agent config immediately.

## Wings node — agent installation

Install Python 3 and ensure `journalctl` is available. Copy `node-agent/wings_crash_agent.py` to `/opt/discord-wings-agent/` and `node-agent/discord-wings-agent.service` to `/etc/systemd/system/`.

```bash
install -d -m 700 /etc/discord-wings-agent /var/lib/discord-wings-agent
install -d -m 755 /opt/discord-wings-agent
install -m 755 wings_crash_agent.py /opt/discord-wings-agent/
install -m 644 discord-wings-agent.service /etc/systemd/system/
cp config.example.json /etc/discord-wings-agent/config.json
chmod 600 /etc/discord-wings-agent/config.json
```

Edit `/etc/discord-wings-agent/config.json` with the HTTPS Panel URL and the node UUID/key generated above. Start the agent:

```bash
systemctl daemon-reload
systemctl enable --now discord-wings-agent
journalctl -u discord-wings-agent -n 30 --no-pager
```

On first start it records a baseline journal cursor **without replaying old crashes**. It then scans every 10 seconds. If a network error occurs, it retries from the last acknowledged cursor. Never run two instances against the same cursor file.

## Security

- HTTPS only; node secret in a root-only config file.
- HMAC SHA-256 over the raw request body; request timestamps valid for 120 seconds.
- The node UUID is matched to the server's assigned Pterodactyl node.
- Unique journal cursor hash deduplicates retries; no game-console content leaves the node.
- The endpoint intentionally does not use user authentication or a CSRF cookie because the node uses signed machine-to-machine requests. Confirm the Panel middleware applies the documented exemption.
- Treat a compromised node key as compromised: rotate it promptly.

## Troubleshooting

On Panel:

```bash
php artisan route:list --path=extensions | grep wings-event
php artisan discord-notifier:poll -v
```

On Wings node:

```bash
systemctl status discord-wings-agent
journalctl -u discord-wings-agent -n 60 --no-pager
journalctl -u wings --since '1 hour ago' --no-pager | grep -i 'crashed state'
```

A 419 response suggests CSRF middleware was not exempted by this build. A 403 may indicate a wrong signing key, incorrect node UUID or missing key. A 404 may indicate a missing route or server not belonging to that node. Do not expose your node key or full private logs in bug reports.

## Validation state

Static PHP syntax checks and Python syntax checks passed for the generated source. This updated release has **not yet** been tested end-to-end on a running Panel and node. The previous v1.3.2 release was tested successfully on one Panel and Wings node (crash notification, restart detection, recovery, signed delivery). This v1.3.3 suppression logic has not yet been verified on a live node; test before declaring stable.
