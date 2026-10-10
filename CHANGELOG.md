# Changelog

## 1.3.3 (2026-10-10) — pre-release

- Suppress inferred Server Restart Detected / Server Started when caused by a recently confirmed Wings crash, to avoid a third Discord notification between Server Crashed and Server Recovered.
- Preserve ordinary restart/start notifications and all existing event settings.
- Keep the Wings crash agent unchanged from 1.3.2; no node reinstall necessary.
- Tested v1.3.2 crash/recovery on one node; v1.3.3 suppression awaits live validation.

## 1.3.2

- Added authenticated node-side journal agent for confirmed Wings crash events.
- HMAC-signed endpoint, deduplication, recovery polling, per-node keys.
