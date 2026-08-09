# Runtime or regional outage

## Trigger and customer-visible symptoms

Trigger on application health failure, shared Redis loss, all specialised worker heartbeats missing, all UDP listener heartbeats missing, SD-WAN regional loss, or simultaneous multi-Site collection-unavailable state. Users may see delayed or stale monitoring across many Sites; absence of new evidence must not be presented as widespread Device failure.

## Distinguish the failure

- Regional/Site connectivity: application/Redis are healthy but a group of Site paths is unreachable.
- Runtime: workers/listeners/queues fail across otherwise reachable Sites.
- Storage: collection/current state works but retained history or snapshots are unavailable.
- Provider: one typed integration fails while native monitoring remains current.
- Device/collector: impact is confined to one canonical Device or remote collection path.

## Safe read-only diagnosis

```bash
supervisorctl status
php artisan queue:monitor monitoring,monitoring-events,monitoring-checks,monitoring-discovery,monitoring-provider,monitoring-topology,monitoring-maintenance,monitoring-commands --max=1000 --json
php artisan queue:failed
php artisan schedule:list
```

Check `/up` and the authenticated runtime-health endpoint from the same region, Redis availability, consumed per-queue heartbeat ages, oldest queue ages, listener heartbeats, collector aggregates, Site WAN state, time-series health, and snapshot-store health. A missing command-worker heartbeat is a runtime fault even when no command is awaiting execution. Do not disclose endpoint configuration or restricted-Site counts in incident channels.

## Supervisor deployment contract

Linux application deployments install the eight isolated monitoring workers and
three UDP listeners with `scripts/monitoring/install-supervisor.sh`. The
installer defaults to the Ubuntu Supervisor include path
`/etc/supervisor/conf.d` and the `www-data` application user. A different active
include directory must be supplied explicitly with `--include-directory` or
`MONITORING_SUPERVISOR_INCLUDE_DIR`; a non-standard daemon configuration must be
supplied with `--supervisord-config` or `MONITORING_SUPERVISORD_CONFIG`.

Before Supervisor may start or update any monitoring group, the installer
requires root, the exact application release path used by every command and
working directory, the configured runtime user, the exact log directory, both
source files, all eleven uniquely named programs, a valid complete supervisord
configuration, daemon connectivity, a non-starting include-path discovery probe,
and discovery of every program through the active include path. It stages both
files and rolls them back on pre-update
failure. The final `update` names only the monitoring groups, then requires each
group to reach `RUNNING`; unrelated Supervisor programs are never updated.

`scripts/deploy-server.sh` runs this installer before `queue:restart`. Use
`--skip-monitoring-supervisor` only when the runtime is intentionally managed by
an already-reviewed external deployment system. A skipped or failed install is
not A02 deployment evidence. The path/user/include overrides contain no
credentials and must not be repurposed to transport secrets.

For a direct reviewed Ubuntu installation:

```bash
sudo bash scripts/monitoring/install-supervisor.sh \
  --application-path=/var/www/oblivionfindings \
  --run-user=www-data \
  --log-directory=/var/log/oblivion \
  --include-directory=/etc/supervisor/conf.d \
  --supervisord-config=/etc/supervisor/supervisord.conf
```

Afterward, retain the normal read-only diagnosis above plus
`php artisan monitoring:central-site-readiness --all --json` as deployment
evidence. Do not claim live readiness from configuration installation alone.

For a one-shot V09 runtime/dependency preflight, run
`scripts/monitoring/verify-runtime.ps1` with the session cookie supplied only
through `MONITORING_HEALTH_SESSION_COOKIE` and `-HealthUrl` set to the exact
HTTPS `/security-devices/runtime-health` route on port 443. The verifier rejects URL
userinfo, query strings, fragments and every other path, bypasses caches, and
does not follow redirects while carrying the cookie. A `configuration_only`
result confirms only repository/runtime configuration; that configuration-only result is not runtime evidence. A
`verified` result additionally requires all eight workers, all eight queue
components and all three listeners to be current, both storage surfaces to be
available, the independent heartbeat to be sent, and a fresh UTC observation.
This one-shot result does not replace the sustained A02/L05 observation or the
external watchdog outage/recovery record.

For the sustained A02/L05 release observation, use the read-only verifier from
the deployed release. Supply the authenticated health session through the
environment so it is not placed in shell history or command arguments:

```bash
export MONITORING_HEALTH_SESSION_COOKIE='authorised-cookie-value'
bash scripts/monitoring/verify-central-runtime.sh \
  --application-path=/var/www/oblivionfindings \
  --supervisord-config=/etc/supervisor/supervisord.conf \
  --health-url=https://oblivion.example/security-devices/runtime-health \
  --samples=15 \
  --interval-seconds=60
unset MONITORING_HEALTH_SESSION_COOKIE
```

The verifier is read-only and refuses a token sample. Every sample must find
the exact eight worker groups and three listener groups fully running, the
authenticated runtime operational, a fresh UTC runtime observation that
advances between samples, all listeners current, the independent heartbeat
sent, and every configured direct monitor at each operational Site to have current durable central-runtime evidence
with no collector. A single healthy monitor cannot conceal another configured
direct monitor that is stale or has never produced durable evidence. The exact
opaque direct-monitor roster must remain stable, and by the final sample every
member of that roster must have produced new durable evidence in both halves of
an observation covering at least two cycles of the slowest configured check.
At completion, every member must also remain within its configured interval plus
the bounded release grace; an early advance followed by a later SD-WAN outage
therefore cannot pass merely because a longer policy stale window still labels
old evidence fresh. The opaque roster binds each check's interval, so disabling,
replacing or slowing a check cannot make the observation pass. The request
explicitly bypasses intermediary caches and rejects
stale or replayed health payloads. It prints only aggregate counts and
timestamps; retain that result with the external watchdog's separately
captured alert and recovery timeline. Never retain the cookie or the detailed
Site readiness payload in release evidence.

## Independent total-outage signal

Configure an independently hosted dead-man monitor outside the Oblivion Findings application, database, Redis, SD-WAN, and primary hosting region. Set `MONITORING_EXTERNAL_HEARTBEAT_ENABLED=true`, put the provider's unguessable heartbeat URL in `MONITORING_EXTERNAL_HEARTBEAT_URL`, and allowlist only its exact lowercase public host in `MONITORING_EXTERNAL_HEARTBEAT_ALLOWED_HOSTS`. The target must be public HTTPS on port 443, use a non-root path, return a 2xx response without a redirect, and must not use query-string credentials. Treat the URL path as a secret: do not copy it into tickets, screenshots, logs, or incident channels.

The scheduler-direct check runs every minute and withholds its heartbeat unless all eight isolated workers and the SNMP-trap, syslog, and flow listeners are current. It does not send Site, Device, queue, credential, or customer data. Configure the independent monitor to alert after five missed minutes so a total application, scheduler, database, Redis, worker, or listener outage is visible from outside the failed system without turning a short restart into a false incident.

Before production acceptance, prove the integration in a non-production environment. Observe a successful delivery, stop the scheduler and confirm the independent alarm, restore it, then separately stop one worker and one UDP listener and confirm each condition withholds its heartbeat. Record timestamps and external alert/recovery evidence without recording the URL. Re-enable every process and confirm the authenticated runtime-health surface reports a current delivery before closing the exercise.

## Containment that preserves evidence

Declare stale/collection-unavailable state and pause notification/ticket automation only through an audited maintenance policy when necessary. Keep listener spools, Redis data, inbox/outbox, checkpoints, DLQ, MySQL, time-series, snapshots, and audit logs. Do not route specialised queues through `default` as an emergency shortcut.

## Recovery and replay

Restore dependencies in order: network/DNS/time, MySQL and Redis, application, orchestration worker, specialised workers, UDP listeners, collectors/providers, then retention/downsample. Recover expired delivery leases once and replay reviewed dead letters individually. Allow confirmation/hysteresis to create authentic recovery; technician-owned IT incidents are not auto-resolved.

## Validation

Confirm health endpoints, scheduler, every Supervisor group, queue depth/age, listener heartbeat, outbox/inbox/checkpoint continuity, collector gaps, provider cursors, storage health, and representative allowed/denied Site views. Verify one root finding per outage scope and no duplicate Control Room/IT lifecycle.

## Escalation, repair rule, and closure evidence

The incident commander coordinates Control Room, network/SD-WAN, platform, database/Redis, storage, security/privacy, and Site owners. Prefer forward repair. Fail over or roll back only to a tested compatible runtime and intact data generation. Close with outage timeline/region, dependency sequence, health and queue snapshots, replay IDs, gap/duplicate checks, Site privacy proof, recovery time, and remaining follow-up work.
