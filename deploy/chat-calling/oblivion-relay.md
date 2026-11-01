# Oblivion Relay: install and run

Oblivion Relay is Oblivion's own STUN and TURN server for Chat calls. When a browser can't reach another browser directly (guest Wi-Fi that blocks UDP, hospital networks that allow only port 443, carrier NAT), the media goes through the relay. It stays encrypted between the browsers: the relay sees addresses, sizes and timing, never audio or video.

- **Spec:** [docs/chat-experience/clean-room/specs/oblivion-relay.md](../../docs/chat-experience/clean-room/specs/oblivion-relay.md). Section numbers below refer to it.
- **Code:** `relay/` (Node.js 22 or later, built-in modules only, no npm install). Tests: `npm run test:relay`.
- **Who installs it:** Stephan, on the test server that runs main (oblivionfindings.com). The builder and checker sessions install nothing.
- **Not yet:** install only after the clean-room checker has signed it off in `docs/chat-experience/clean-room/REGISTER.md` and Rory has said yes. Until the relay is running, the app's `CHAT_RELAY_*` keys stay empty and calls carry on as today.
- **Version 1 is for Chat calls only.** CCTV live view and IT remote support are deferred: don't set any `RELAY_CCTV_*` or `RELAY_REMOTE_*` setting (the relay refuses to start), and leave the existing `CCTV_TURN_*` and `ENDPOINT_REMOTE_RELAY_*` keys as they are.

## The chosen setup: beside nginx on the test server (shape B, 10.3)

Until LiveKit is removed (C4.10), LiveKit's built-in TURN owns `turn.<domain>`, UDP 3478 and `127.0.0.1:5349` on this host. The relay therefore has its own name and ports:

| What | Value |
|---|---|
| DNS name | `relay.oblivionfindings.com` (A record to the server's public IPv4) |
| TURN over UDP | UDP 3479, public |
| TURN over TLS | TCP 443 through nginx's SNI router, which passes it with a PROXY header to `127.0.0.1:5350` |
| Relayed media | UDP 61000–64999, public |
| Health | `127.0.0.1:9478`, never opened |
| Control channel | `127.0.0.1:9479`, never opened |
| App URLs | `turn:relay.oblivionfindings.com:3479?transport=udp`, `turns:relay.oblivionfindings.com:443?transport=tcp` |

There is no plain TURN over TCP in this shape: nginx needs TLS's SNI name to route port 443, and TURN over TLS on 443 already covers strict networks.

## Install steps (run as root, in order)

### 1. Check the host first

```bash
node --version
```

It must be v22 or later.

```bash
sysctl net.ipv4.ip_local_port_range
```

The upper bound should be below 61000 (Linux's default is `32768 60999`). If it reaches into 61000–64999, stop and tell Rory: the range must move.

```bash
ss -lunp | awk '$5 ~ /:(3479|6[1-4][0-9][0-9][0-9])$/'
```

Nothing should be listening on UDP 3479 or in 61000–64999.

```bash
nginx -T 2>/dev/null | grep -n -A8 '^stream'
```

The relay's TLS line belongs in the existing `stream` map that routes port 443 by SNI (LiveKit's `turn.` entry is there). **If there is no `stream` block and 443 is served by the `http` block alone, stop:** adding a stream router changes how main itself is reached and needs its own plan and test first (10.3).

### 2. Users, group and directories

```bash
useradd --system --no-create-home --shell /usr/sbin/nologin oblivion-relay
```

```bash
groupadd --system oblivion-relay-logs
```

```bash
install -d -o root -g oblivion-relay -m 0750 /etc/oblivion/relay /etc/oblivion/relay/tls
```

```bash
install -d -o oblivion-relay -g oblivion-relay-logs -m 2750 /var/log/oblivion-relay
```

The setgid bit (2750) gives every new log file the `oblivion-relay-logs` group; the relay writes them as 0640. `/var/lib/oblivion-relay` is created by systemd (`StateDirectory`). The app checkout under `/var/www/oblivion/current` must be readable by `oblivion-relay` (it only reads `relay/`).

Add the people who investigate security incidents to `oblivion-relay-logs` (see "The allocation log" below). Stephan is the first:

```bash
usermod -aG oblivion-relay-logs stephan
```

### 3. Secrets

Two secrets, different from each other and from everything else: the chat credential secret (shared with the app's `CHAT_RELAY_SECRET`) and the control secret (shared with `CHAT_RELAY_CONTROL_SECRET`).

```bash
umask 077; openssl rand -base64 48 | tr -d '\n' > /etc/oblivion/relay/chat.secret
```

```bash
umask 077; openssl rand -base64 48 | tr -d '\n' > /etc/oblivion/relay/control.secret
```

```bash
chown root:oblivion-relay /etc/oblivion/relay/chat.secret /etc/oblivion/relay/control.secret && chmod 0640 /etc/oblivion/relay/chat.secret /etc/oblivion/relay/control.secret
```

```bash
for f in deny chat-previous.secret control-previous.secret; do install -o root -g oblivion-relay -m 0640 /dev/null /etc/oblivion/relay/$f; done
```

The deny file (3.5) and the two previous-secret files start empty. The previous files are filled only during a rotation; an empty file means "no previous secret", so rotating the chat secret never needs a restart. Secrets never go in the repository, a ticket or a chat message; copy them to the app's `.env` directly on the server.

### 4. The relay's settings

```bash
install -o root -g oblivion-relay -m 0640 /var/www/oblivion/current/deploy/chat-calling/oblivion-relay.env.example /etc/oblivion/relay.env
```

Edit `/etc/oblivion/relay.env`: set `RELAY_EXTERNAL_IP` to the server's public IPv4. If the provider maps that address one-to-one onto a private interface address (common in clouds), set `RELAY_LISTEN_IP` to the private address. Keep `RELAY_TOTAL_MBPS` at 100, or half the server's link speed if that is lower. Everything else in the example is the test server's value.

### 5. DNS and certificate

1. Add an A record: `relay.oblivionfindings.com` → the server's public IPv4.
2. Issue the certificate with the server's existing Let's Encrypt setup (HTTP-01 over the app's port 80), for example `certbot certonly --webroot -w /var/www/oblivion/current/public -d relay.oblivionfindings.com`.
3. The relay runs as `oblivion-relay` and can't read `/etc/letsencrypt`, so a deploy hook copies the files and reloads it. Save this as `/etc/letsencrypt/renewal-hooks/deploy/oblivion-relay.sh` and make it executable (`chmod 0755`):

```bash
#!/bin/sh
# Copy the relay's certificate where it can read it, then reload: calls carry on.
case "$RENEWED_DOMAINS" in *relay.oblivionfindings.com*) ;; *) exit 0 ;; esac
install -o root -g oblivion-relay -m 0640 "$RENEWED_LINEAGE/fullchain.pem" /etc/oblivion/relay/tls/fullchain.pem
install -o root -g oblivion-relay -m 0640 "$RENEWED_LINEAGE/privkey.pem" /etc/oblivion/relay/tls/privkey.pem
systemctl reload oblivion-relay 2>/dev/null || true
```

Run it once by hand for the first copy:

```bash
RENEWED_DOMAINS=relay.oblivionfindings.com RENEWED_LINEAGE=/etc/letsencrypt/live/relay.oblivionfindings.com /etc/letsencrypt/renewal-hooks/deploy/oblivion-relay.sh
```

### 6. nginx: one map line

In the existing `stream` map that routes port 443 by SNI (step 1), add the relay's line beside LiveKit's `turn.` entry:

```
map $ssl_preread_server_name $oblivion_voice_destination {
    # existing entries stay, including LiveKit's turn.<domain> 127.0.0.1:5349;
    relay.oblivionfindings.com 127.0.0.1:5350;
}
```

The `listen 443; ssl_preread on; proxy_protocol on;` server block stays as it is. The relay ends TLS itself; nginx only reads the SNI name.

```bash
nginx -t && systemctl reload nginx
```

At C4.10 only LiveKit's `turn.` line is removed; the relay keeps its name.

### 7. Firewall

Open UDP 3479 and UDP 61000–64999, and nothing else new. 443 is already open for nginx. 5350, 9478 and 9479 stay on loopback and are never opened. With ufw:

```bash
ufw allow 3479/udp comment 'Oblivion Relay'
```

```bash
ufw allow 61000:64999/udp comment 'Oblivion Relay media'
```

### 8. The systemd unit

```bash
install -o root -g root -m 0644 /var/www/oblivion/current/deploy/chat-calling/oblivion-relay.service.example /etc/systemd/system/oblivion-relay.service
```

```bash
systemctl daemon-reload && systemctl enable --now oblivion-relay
```

```bash
journalctl -u oblivion-relay -n 20 --no-pager
```

A `start` line lists the listeners. A `config_error` line names the setting that is wrong (and never a secret's value); the relay exits with code 78 until it's fixed.

```bash
curl -s http://127.0.0.1:9478/health
```

`"status":"ok"` with `classes.chat.enabled: true`.

### 9. The app's `.env`

On the same host, in the app's `.env` (the values from steps 3 and 4):

```
CHAT_RELAY_URLS=turn:relay.oblivionfindings.com:3479?transport=udp,turns:relay.oblivionfindings.com:443?transport=tcp
CHAT_RELAY_SECRET=<contents of /etc/oblivion/relay/chat.secret>
CHAT_RELAY_CREDENTIAL_SECONDS=3600
CHAT_RELAY_HEALTH_URL=http://127.0.0.1:9478
CHAT_RELAY_CONTROL_URLS=http://127.0.0.1:9479
CHAT_RELAY_CONTROL_SECRET=<contents of /etc/oblivion/relay/control.secret>
CHAT_RELAY_CONTROL_KEY_ID=k2026a
```

Then refresh the app's cached configuration the usual way (`php artisan config:cache`). Settings › Chat › Service health now has a "Calling relay" row.

### 10. The checks

Run the acceptance checks in spec section 12 during stage 5 network testing. Checks 3, 10 and 14 load the host that serves main: run them at a quiet time. Check 8 probes the host's own ports from outside; check 11 offboards a test person in a relayed call.

## Rotating secrets

**The chat credential secret (3.4).** No call drops.

1. Copy the current secret into `/etc/oblivion/relay/chat-previous.secret`, write the new one to `/etc/oblivion/relay/chat.secret`, and `systemctl reload oblivion-relay`. The relay now accepts both.
2. Put the new secret in the app's `CHAT_RELAY_SECRET` and refresh its configuration.
3. Wait five hours (the longest credential plus grace). Empty the previous file (`: > /etc/oblivion/relay/chat-previous.secret`) and `systemctl reload oblivion-relay`. An emptied file counts as cleared.

**A leaked chat secret.** Put a new secret in `chat.secret`, empty the previous file, reload, then update the app. Every allocation made with the old secret ends at once.

**The control secret (3.7).**

1. Copy the current secret into `/etc/oblivion/relay/control-previous.secret` and write a new one to `control.secret`. In `relay.env`, set `RELAY_CONTROL_PREVIOUS_KEY_ID` to the old key id and `RELAY_CONTROL_KEY_ID` to a new one, then `systemctl restart oblivion-relay`. Key ids are plain settings, so this one step needs a restart, which makes relayed calls reconnect: do it at a quiet time.
2. Switch the app's `CHAT_RELAY_CONTROL_SECRET` and `CHAT_RELAY_CONTROL_KEY_ID` to the new values.
3. The app signs every request, including queued retries, at the moment it sends, so nothing in its queue uses the old key. Empty `control-previous.secret` and reload.

## The control channel

The app ends a person's relay use at once (offboarding, leaving or losing a call, suspension, or `php artisan chat:relay-revoke {user} --call= --reason=hand`). It signs each request with the control secret; the relay accepts it once, within 30 seconds, from the listed sources only. Bodies hold only opaque ids, times and a reason word.

- **Beside the app (this shape):** loopback, `127.0.0.1:9479`. Nothing to set up.
- **On a server of its own (shape A):** listen on the relay's address on a private network it shares with the app server (a cloud private network, or a WireGuard tunnel such as `10.0.0.5`), with `RELAY_CONTROL_ALLOWED_SOURCES` set to the app server's private address and the firewall allowing TCP 9479 from that address only. Where there's no private network, use an SSH tunnel that ends on loopback, for example on the app server `ssh -N -L 127.0.0.1:9479:127.0.0.1:9479 relay-host` run as a service. The port is never opened publicly.

If the app can't reach the relay, the person's action still succeeds; each node gets a queued retry (5 s, 15 s, 30 s, then every minute until nothing could still be relaying). Service health shows "Relay: N revocations waiting", and the scheduler logs an error for each one waiting more than five minutes. An operator who can reach the relay host can end someone's use at once with the deny file: add their opaque id (from the app, never a name) to `/etc/oblivion/relay/deny` and reload.

Held revocations survive restarts in `/var/lib/oblivion-relay/revocations.json`. If that file is damaged the relay refuses to start and names it; move it aside to start without it.

## The allocation log

Rory chose fuller logs on 1 November 2026: every allocation's start and end, with the client's IP and port (from the PROXY header behind nginx), so abuse relayed through the server can be traced (RFC 8656 21.3.2). It holds no credentials, keys, nonces, payloads or peer addresses, and ids appear only as a 12-character hash.

- **Where:** `/var/log/oblivion-relay/allocations-YYYY-MM-DD.jsonl`, one file per UTC day, mode 0640, group `oblivion-relay-logs`.
- **Retention:** 14 days (`RELAY_LOG_RETENTION_DAYS`, 1–90). The relay deletes older files at start and every hour. Exclude `/var/log/oblivion-relay` from host backups, or keep backups of it no longer than 14 days. Don't forward the files anywhere that doesn't keep the same limits.
- **Who can read it:** root, the relay, and members of `oblivion-relay-logs` only. The app's user (`www-data`) is not a member. Membership is limited to the people who investigate security incidents and is agreed with the organisation's privacy officer. Current members: Stephan. Record each addition here with the date and the privacy officer's agreement.
- **When:** only to investigate abuse or a security incident. Someone with app access can recompute a person's id for a call and match it to the hash, so the log is personal information.

Lines are JSON: `time`, `level`, `event` (`allocation_start`, `allocation_end`, `revoked`, `auth_limit`, `rate_limit`), and where they apply `class`, `id`, `client_ip`, `client_port`, `transport`, `relay_port`, `lifetime`, `duration_s`, `bytes_in` (client to peers), `bytes_out` (peers to client), `packets_in`, `packets_out`, `reason`, `ended` and `source_ip` (the control channel's caller).

The operations log goes to the journal (`journalctl -u oblivion-relay`). It never carries an IP address, an id or an id hash: `start`, `stop`, `reload`, `listener_error`, `config_error`, `capacity`, `auth_limit` (control-channel refusal counts), and at most one `refusals` summary a minute.

## Ports and certificates in both shapes

| | Shape B (test server, chosen) | Shape A (own server, later) |
|---|---|---|
| Name | `relay.oblivionfindings.com` | `turn.<domain>` |
| Public inbound | UDP 3479, UDP 61000–64999 (443 already open for nginx) | UDP 3478, TCP 3478, TCP 443, UDP 49152–65535, SSH from admin addresses |
| TLS | nginx SNI route on 443 → `127.0.0.1:5350` with PROXY | `tcp+tls` on 443: TLS and plain TURN on one port |
| Health | `127.0.0.1:9478` | `127.0.0.1:9478` on the relay host |
| Control | `127.0.0.1:9479` | private address or tunnel, TCP 9479 from the app server only |
| App reads status | `CHAT_RELAY_HEALTH_URL` | signed `GET /control/status` (leave `CHAT_RELAY_HEALTH_URL` empty) |
| Certificate | Let's Encrypt HTTP-01 via the app's port 80; deploy hook copies and reloads | Let's Encrypt HTTP-01 (TCP 80 open briefly) or DNS-01; same hook |
| Memory caps | `MemoryHigh=384M`, `MemoryMax=512M`, no swap | may be higher |

## Signals and exit codes

- `systemctl reload oblivion-relay` (SIGHUP): re-reads secret files, the certificate and the deny list. Allocations stay, except those whose secret was removed or whose id is now denied. A file that can't be read is reported as `config_error` and the old value is kept.
- `systemctl stop` (SIGTERM): ends every allocation and exits within 5 seconds.
- Exit 78: a setting is wrong (the journal names it). Exit 70: an unexpected error (systemd restarts it).
