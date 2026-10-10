#!/usr/bin/env python3
"""Signed Wings journal crash event forwarder. Standard library only."""
import hashlib
import hmac
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

CONFIG = Path('/etc/discord-wings-agent/config.json')
MARKER = re.compile(r'detected server as entering a crashed state; running crash handler', re.I)
UUID = re.compile(r'\bserver=([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})\b', re.I)


def atomic_write(path, content):
    tmp = Path(str(path) + '.tmp')
    tmp.write_text(content)
    os.replace(tmp, path)


def journal_entries(cursor):
    args = ['journalctl', '-u', 'wings', '-o', 'json', '--no-pager', '-n', '100']
    if cursor:
        args = ['journalctl', '-u', 'wings', '-o', 'json', '--no-pager', '--after-cursor', cursor]
    result = subprocess.run(args, capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('journalctl failed: ' + result.stderr[-250:])
    for line in result.stdout.splitlines():
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(obj, dict) and obj.get('__CURSOR'):
            yield obj


def send_event(cfg, entry, uuid):
    # Stable ID for deduplicating journal replays across agent restarts.
    event_id = hashlib.sha256(entry['__CURSOR'].encode()).hexdigest()
    body = json.dumps({
        'node_uuid': cfg['node_uuid'], 'server_uuid': uuid, 'event': 'crashed',
        'event_id': event_id, 'timestamp': int(time.time()),
    }, separators=(',', ':')).encode()
    signature = hmac.new(cfg['node_key'].encode(), body, hashlib.sha256).hexdigest()
    req = urllib.request.Request(
        cfg['panel_url'].rstrip('/') + '/extensions/discord-event-notifier/wings-event',
        data=body,
        headers={'Content-Type': 'application/json', 'X-Wings-Event-Signature': signature},
        method='POST',
    )
    with urllib.request.urlopen(req, timeout=15) as response:
        if response.status != 200:
            raise RuntimeError('panel returned HTTP ' + str(response.status))


def run():
    cfg = json.loads(CONFIG.read_text())
    assert cfg['panel_url'].startswith('https://'), 'HTTPS is required'
    assert len(cfg['node_key']) >= 32, 'Signing key too short'
    state_path = Path(cfg.get('cursor_file', '/var/lib/discord-wings-agent/cursor'))
    state_path.parent.mkdir(parents=True, exist_ok=True)
    cursor = state_path.read_text().strip() if state_path.exists() else None
    if not cursor:
        # Baseline current journal without replaying historic crashes.
        entries = list(journal_entries(None))
        if entries:
            atomic_write(state_path, entries[-1]['__CURSOR'])
        print('Journal baseline recorded; historical entries ignored.', flush=True)
    while True:
        try:
            cursor = state_path.read_text().strip() if state_path.exists() else None
            for entry in journal_entries(cursor):
                message = str(entry.get('MESSAGE', ''))
                match = UUID.search(message) if MARKER.search(message) else None
                if match:
                    send_event(cfg, entry, match.group(1).lower())
                    print('Crash event acknowledged:', match.group(1), flush=True)
                atomic_write(state_path, entry['__CURSOR'])
        except urllib.error.HTTPError as e:
            # Do not advance cursor on failure; retry safely with same event ID.
            print('Panel rejected crash event: HTTP', e.code, file=sys.stderr, flush=True)
        except Exception as e:
            print('Agent error:', str(e), file=sys.stderr, flush=True)
        time.sleep(10)


if __name__ == '__main__':
    run()
