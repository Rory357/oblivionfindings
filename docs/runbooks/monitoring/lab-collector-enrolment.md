# A03 lab collector enrolment (Herd / STEPHAN-PC)

Ship a one-collector identity against the existing Herd checkout. This is **lab
evidence**, not production release evidence.

`verify-transport` is a response-contract probe. A matching JSON document from
this sequence does **not** prove that a production reverse proxy required or
validated mTLS, that the response originated in Laravel rather than the proxy,
or that nonce replay crossed application instances. Pair any later production
run with the approved proxy configuration in
`docs/runbooks/monitoring-collector-reverse-proxy.md`.

Do not put the enrolment token on the command line. Use
`OBLIVION_COLLECTOR_ENROLMENT_TOKEN` or `--token-fd`. The systemd installer
still does not enrol.

## What this sequence covers

Discovery token → enrol → heartbeat → `verify-transport --expect=active` →
revoke → `verify-transport --expect=revoked` → replacement token + re-enrol.

It does not cover credential leases, UniFi/Milesight/Queclink live traffic,
the A02 watchdog, Hikvision/Gallagher, or rewriting the collector as Laravel.

## Prerequisites on STEPHAN-PC

- Herd serving this checkout (typically `https://oblivionfindings.test`)
- PHP 8.4 with curl, openssl, sockets, sodium (Herd CLI is enough)
- Redis for `MONITORING_COLLECTOR_REPLAY_STORE=redis`
- Operator session that can `securityDevices.integrations.manage`
- An active Site id

Docker Desktop is optional. Prefer the local PHP collector next to Herd; use
`collector/docker/compose.lab.yml` only when Docker is installed.

## 1. Bootstrap lab CA and TLS pin

From the repo root:

```powershell
cd C:\Users\steph\Herd\oblivionfindings
php scripts\monitoring\lab-collector-bootstrap.php
composer install --working-dir=collector --no-interaction
```

This writes gitignored files under `collector/lab-state/`:

- `ca.crt.pem` / `ca.key.pem` — dedicated collector CA for issuance and lab mTLS
- `proxy.crt.pem` / `proxy.key.pem` — loopback TLS certificate the collector pins
- `tls-pin.txt` — `sha256//…` pin
- `central.env.snippet` — values to copy into Herd `.env`
- `collector-id.txt` — UUID reused for replacement re-enrolment

Copy the snippet into `.env` (do not commit it). Restart Herd PHP. Confirm
`MONITORING_COLLECTOR_ALLOW_PROXY_FINGERPRINT_HEADER=false`.

## 2. Start the lab mTLS proxy

Keep this process running in its own terminal:

```powershell
php scripts\monitoring\lab-collector-proxy.php
```

Default bind is `127.0.0.1:8443`, forwarding collector API routes to
`https://oblivionfindings.test`. Override with `OBLIVION_LAB_CENTRAL_ORIGIN` if
the Herd hostname differs. The proxy is lab-only and binds loopback only.

Optional Docker substitute (proxy + collector image), after bootstrap:

```powershell
docker compose -f collector\docker\compose.lab.yml up --build proxy
```

Set `MONITORING_COLLECTOR_TRUSTED_PROXY_CIDRS` so it includes the address Herd
sees (`127.0.0.1/32` for the PHP proxy; also `172.16.0.0/12` if Docker nginx
forwards).

## 3. Issue a discovery token

In the UI: `/security-devices/discovery/collectors` (or `?tab=collectors`).
Issue a one-time token for the chosen Site. Copy it once. Do not paste it into
shell history as an argv flag.

```powershell
$env:OBLIVION_COLLECTOR_ENROLMENT_TOKEN = 'ofc_enrol_…'   # paste once
$pin = (Get-Content collector\lab-state\tls-pin.txt -Raw).Trim()
$id = (Get-Content collector\lab-state\collector-id.txt -Raw).Trim()
```

## 4. Enrol

```powershell
php collector\bin\oblivion-collector enrol `
  --identity=collector/lab-state/collector.identity.json `
  --collector-id=$id `
  --central-url=https://127.0.0.1:8443 `
  --tls-public-key-pin=$pin `
  --state-directory=collector/lab-state
```

Expect `enrolment: complete`. Relative identity paths (`state_directory=.`,
`collector.crt.pem`, `collector.key.pem`) are intentional so the same state
directory works on Windows and in the Docker volume.

## 5. Heartbeat

```powershell
php collector\bin\oblivion-collector heartbeat `
  --identity=collector/lab-state/collector.identity.json
```

Expect `heartbeat: complete`. This reports spool health over mTLS plus the
Ed25519 request signature. It does not fetch signed configuration. `run`
still needs executable collector work (monitors, discovery, or commands) and
is out of this A03 sequence.

Confirm the collectors UI shows a current heartbeat for that UUID.

## 6. Active transport probe

```powershell
php collector\bin\oblivion-collector verify-transport `
  --identity=collector/lab-state/collector.identity.json `
  --expect=active `
  --samples=5
```

Keep the value-free JSON. It is a response-contract match, not proxy-mTLS
proof.

## 7. Revoke, then revoked probe

In the UI, revoke the collector with a reason of at least 10 characters.
Preserve `collector/lab-state` (do not delete the identity).

```powershell
php collector\bin\oblivion-collector verify-transport `
  --identity=collector/lab-state/collector.identity.json `
  --expect=revoked `
  --samples=5
```

Every sample must match the generic authentication-denial contract. A 422
validation body fails this step.

## 8. Replacement token and re-enrol

Use **Re-enrol** on the revoked collector (not a fresh Site token). A general
Site token cannot reactivate the existing UUID. Consume the replacement token
once, **same UUID**, fresh keys and certificate:

```powershell
$env:OBLIVION_COLLECTOR_ENROLMENT_TOKEN = 'ofc_enrol_…'   # replacement token once
php collector\bin\oblivion-collector enrol `
  --identity=collector/lab-state/collector.identity.json `
  --collector-id=$id `
  --central-url=https://127.0.0.1:8443 `
  --tls-public-key-pin=$pin `
  --state-directory=collector/lab-state
php collector\bin\oblivion-collector heartbeat `
  --identity=collector/lab-state/collector.identity.json
php collector\bin\oblivion-collector verify-transport `
  --identity=collector/lab-state/collector.identity.json `
  --expect=active `
  --samples=5
```

The new active JSON must keep the same collector reference and change both
the signing-key and identity-generation references. A second use of the
replacement token must fail.

## Docker collector commands (optional)

```powershell
docker compose -f collector\docker\compose.lab.yml run --rm collector enrol `
  --identity=/var/lib/oblivion-lab-collector/collector.identity.json `
  --collector-id=$id `
  --central-url=https://host.docker.internal:8443 `
  --tls-public-key-pin=$pin `
  --state-directory=/var/lib/oblivion-lab-collector
```

Run `heartbeat` and `verify-transport` the same way. Enrol inside the
container (or via the portable relative identity paths) so certificate paths
match the volume.

## Honest limits

- Lab CA material in `collector/lab-state/` is local and gitignored. Rotate it
  by rerunning bootstrap; never copy it to production.
- The PHP/nginx lab proxy is not the production Nginx contract.
- `verify-transport` does not prove proxy mTLS by itself.
- `heartbeat` is not a collection cycle and is not recovery evidence for an
  outage exercise.
