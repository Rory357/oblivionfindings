# Oblivion Findings remote collector

This is the optional database-free PHP 8.4 collector for Sites that the central Oblivion Findings runtime cannot reach through the normal SD-WAN path. It is not a second application server: it has no Laravel dependency, database client, SQL store, secondary ownership partition, or device-command channel.

The collector accepts only Ed25519-signed configuration for one collector UUID and one canonical Site. Each check must match the signed network, Device, protocol, and exact configuration record. ICMP, TCP, DNS, HTTP/HTTPS, and TLS use fixed in-process or fixed-argument implementations. SNMP, SSH, and WinRM additionally require a matching unexpired credential lease; reusable material stays in memory and is never written to the spool.

## Commands

```text
php bin/oblivion-collector version
php bin/oblivion-collector doctor --config=/path/collector.json [--identity=/path/collector.identity.json]
php bin/oblivion-collector enrol --identity=/private/collector.identity.json --collector-id=<uuid> --central-url=https://... --tls-public-key-pin=sha256//... --state-directory=/private/state
php bin/oblivion-collector run --identity=/private/collector.identity.json --config=/private/collector.json
```

The one-time enrolment token is read from `OBLIVION_COLLECTOR_ENROLMENT_TOKEN` or a numeric `--token-fd`; it is never accepted as a command-line value. Enrolment returns a central-issued client certificate and the central Ed25519 configuration key. The collector writes the certificate, private key, request-signing key, spool key, and checkpoints only to private local state. Every runtime request uses mTLS plus a fresh nonce and an Ed25519 signature over the method, path, timestamp, nonce, and body hash. The central service accepts the verified certificate fingerprint header only from configured trusted reverse-proxy addresses.

`run` fetches the latest central configuration and verifies it before atomically replacing the local copy. If configuration transport is unavailable, it can use only an existing still-valid signed copy. It flushes old frames first, executes scheduled checks only while the spool is writable, and accepts only an exact ordered-prefix central acknowledgement before deleting buffered frames.

## Durable state

The private state directory contains:

- `checkpoint.json`: atomically replaced accepted configuration and upload checkpoints.
- `collector.crt.pem` and `collector.key.pem`: the enrolled mTLS identity, written with restrictive permissions.
- `spool.key`: the local secretstream key, created with restrictive permissions.
- `spool.bin`: ordered length-prefixed XChaCha20-Poly1305 secretstream frames.
- `quarantine/`: authenticated frames that could not be decoded.

The spool fsyncs before local receipt is acknowledged. It never silently evicts old data. Item, byte, or age caps change the collector to `buffer_full`: scheduled checks stop, while upload, heartbeat, and control traffic continue.

## Verification

```powershell
composer validate --strict
vendor\bin\pest
php bin/oblivion-collector doctor --config=tests/Fixtures/collector.json
```

The included doctor fixture is signed test data only. It is not an enrolment identity or production credential.

## Linux systemd deployment

The production Linux contract runs `run` as a bounded one-cycle systemd
`oneshot`. A timer starts a new cycle 60 seconds after the prior cycle becomes
inactive, and the runner also takes a non-blocking lock. A slow cycle is never
overlapped or killed merely to maintain cadence. A failed cycle receives a
bounded service restart and remains visible in the journal and collector health
state; the timer provides the next normal attempt.

The checked-in deployment artifacts are:

- `ops/systemd/oblivion-monitoring-collector.service`;
- `ops/systemd/oblivion-monitoring-collector.timer`;
- `ops/systemd/oblivion-monitoring-collector-run`;
- `ops/systemd/monitoring-collector.env.example`; and
- `scripts/monitoring/install-collector-systemd.sh`.

The release pipeline must place a prebuilt production collector, including
`vendor/autoload.php`, below `/opt/oblivion-monitoring-collector/`. Prepare a
root-owned mode-0600 environment file from the example. It contains paths only:
the PHP 8.4 binary, artifact directory, identity file, and signed configuration
file. Never put an enrolment token, credential lease, private key, password, or
reusable secret in that environment file.

The installer deliberately does not enrol a collector. Its first run may create
the dedicated `oblivion-monitoring-collector` non-login account and private
`/var/lib/oblivion-monitoring-collector` directory, but it exits non-zero without
installing or enabling the units while the identity is absent. Enrol separately
through an approved secret injector using `OBLIVION_COLLECTOR_ENROLMENT_TOKEN` or
an already-open numeric `--token-fd`; never place the token value on the command
line. Then rerun the installer:

```bash
sudo scripts/monitoring/install-collector-systemd.sh \
  --environment-file=/root/monitoring-collector.env
```

The installer fails closed unless it finds an active systemd host, PHP 8.4 with
curl, JSON, OpenSSL, sockets and Sodium, the complete prebuilt artifact, and an
identity whose certificate, key and state directory remain inside the private
collector state path. It installs the root-owned environment, runner, service
and timer idempotently, reloads systemd, and enables the timer. It never creates
a CA, configures a reverse proxy, obtains an enrolment token, or enrols an
identity.

After the first successful cycle, verify the retained signed configuration and
spool without exposing identity material:

```bash
sudo -u oblivion-monitoring-collector /usr/bin/php8.4 \
  /opt/oblivion-monitoring-collector/current/bin/oblivion-collector doctor \
  --config=/var/lib/oblivion-monitoring-collector/collector.json \
  --identity=/var/lib/oblivion-monitoring-collector/collector.identity.json
sudo systemctl status oblivion-monitoring-collector.timer oblivion-monitoring-collector.service
sudo systemctl list-timers oblivion-monitoring-collector.timer
sudo journalctl -u oblivion-monitoring-collector.service --since today
```

For controlled recovery, stop the timer before repairing connectivity, time,
the read-only artifact, or private file ownership. Preserve the spool,
checkpoint, identity and quarantine. Run `doctor`, reset a service start-limit
only after correcting its cause, start one manual cycle, confirm ordered upload
and heartbeat recovery, then restart the timer. A revoked identity is never
re-enabled; use the audited central replacement-enrolment workflow with new
identity material.
