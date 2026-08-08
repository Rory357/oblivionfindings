# Collector outage and revocation

## Trigger and customer-visible symptoms

Trigger on `collector_heartbeat_stale`, `collector_backlog_growth`, `collector_sequence_gap`, `collector_spool_corruption`, `collector_clock_drift`, or `collector_identity_revoked`. Devices behind the remote path show stale or collection-unavailable evidence; they must not be labelled as freshly failed solely because the collector is offline.

## Distinguish the failure

- Collector failure: one collector heartbeat/backlog/gap changes while the Site WAN and central runtime remain reachable.
- Site-path failure: all collectors and direct reachability at one Site fail together.
- Device failure: the collector is current and returns a fresh failed check for one Device.
- Runtime/storage failure: multiple Sites/collectors are affected centrally, or external history/snapshots alone are unavailable.

## Safe read-only diagnosis

```bash
supervisorctl status oblivion-monitoring-checks:* oblivion-monitoring-events:*
php artisan queue:monitor monitoring-checks,monitoring-events --max=1000 --json
```

Review the collector UUID label, Site, status, last heartbeat, backlog items/bytes/age, acknowledged/highest sequence, gap count, corruption count, clock drift, configuration sequence, and certificate expiry. On the collector, run `php bin/oblivion-collector doctor --config=/private/collector.json --identity=/private/collector.identity.json`; do not copy identity or spool keys.

For a packaged Linux collector, also inspect the timer and most recent bounded
cycle without printing its root-readable environment file:

```bash
systemctl status oblivion-monitoring-collector.timer oblivion-monitoring-collector.service
systemctl list-timers oblivion-monitoring-collector.timer
journalctl -u oblivion-monitoring-collector.service --since today
```

The service is a database-free one-cycle `oneshot`; the timer schedules the next
cycle only after the prior cycle is inactive, and the runner lock skips any
unexpected overlapping trigger. Repeated `activating` state indicates a slow
cycle, not permission to start a second process. A failed unit or inactive timer
is collection-unavailable evidence even when the last Device observations were
healthy.

`verify-transport` is release evidence, not read-only diagnosis. Active mode
reserves a bounded set of short-lived nonces in the configured replay store but
does not issue configuration or change any Device, observation, heartbeat, or
collector business state. Use it only in the approved acceptance sequence
below.

## Containment that preserves evidence

For suspected compromise, revoke the collector identity immediately through the audited central workflow. Reject all future requests from that certificate/signing key, preserve the central DLQ/audit and the collector spool/quarantine, and rotate central trust material according to the certificate procedure. Do not delete or resequence buffered frames.

## Recovery and ordered return

Repair connectivity/time/storage, or enrol a replacement collector with a one-use Site-scoped token. Fetch a fresh signed configuration, flush frames in source-sequence order, accept only the exact contiguous acknowledgement prefix, and resolve any poison item through the central DLQ process. A revoked identity is never re-enabled; replacement uses new identity material.

On systemd hosts, stop the timer before a controlled repair, preserve all private
state, and run `doctor` as the dedicated `oblivion-monitoring-collector` user.
After correcting the cause, reset the failed `oblivion-monitoring-collector.service` unit, start one service cycle, confirm current
heartbeat plus ordered acknowledgement, and then start the timer. Rerun
`scripts/monitoring/install-collector-systemd.sh` only to restore the reviewed
root-owned unit/runtime contract; it fails closed on a missing artifact or
identity, or on a client certificate that is expired, not yet valid, fingerprint-mismatched, issued for a different collector UUID, or does not match the restored private key. It never enrols automatically. These local checks do not prove that the identity remains active centrally. Do not delete a lock, checkpoint,
spool, quarantine item, identity, or signed configuration to make the service
appear healthy.

## Validation

Confirm heartbeat is current, backlog trends to zero, and the collector-reported acknowledged/highest source sequences exactly mirror the central collector/checkpoint pair. An empty writable spool is not recovery evidence when either sequence is behind or ahead: the collector stays unavailable, the existing root path correlation remains active, and downstream monitors remain stale. Confirm gaps/corruption clear, configuration sequence never regresses, and downstream monitors recover only after the exact sequence mirrors and contiguous evidence return. Verify unrelated approved Sites and collectors continued normally.

## Deployed acceptance sequence

Complete this sequence against one genuinely remote Site and the approved
load-balanced application endpoint. Before starting, confirm through the
deployment review that every application instance has the dedicated collector
CA and signing material from the secret manager, the reverse proxy validates
client certificates and replaces the verified-certificate header, the legacy
fingerprint header is disabled, and
`MONITORING_COLLECTOR_REPLAY_STORE=redis` resolves to the same shared Redis
deployment on every instance. Run `nginx -t` on each terminating proxy. Do not
print Laravel collector configuration, environment files, CA keys, identity
files, request headers, or Redis keys into the evidence record.

1. With the enrolled identity active, run five transport samples as the
   dedicated service user:

   ```bash
   sudo -u oblivion-monitoring-collector /usr/bin/php8.4 \
     /opt/oblivion-monitoring-collector/current/bin/oblivion-collector \
     verify-transport \
     --identity=/var/lib/oblivion-monitoring-collector/collector.identity.json \
     --expect=active \
     --samples=5
   ```

   The command succeeds only when every first signed request over pinned HTTPS
   matches the deliberate invalid-checkpoint response contract and its byte-
   identical replay matches the generic authentication-denial contract. The
   aggregate JSON proves only those observed response contracts. Separately
   retain sanitized proxy evidence that the requests were forwarded to the
   collector application after verified mTLS, plus runtime evidence that every
   application instance resolves the replay store to the approved shared Redis
   deployment. For a multi-instance deployment, retain value-free upstream
   routing evidence that at least one accepted/replayed pair crossed different
   application instances. A same-instance pair proves replay rejection only;
   it does not prove the replay backend or cross-instance sharing.

2. Stop the collector timer without deleting state. Record the outage start,
   allow the heartbeat stale policy to run, and confirm exactly one
   collector/path finding while affected Device evidence becomes stale rather
   than newly failed. Confirm an unrelated Site continues collecting. Restore
   connectivity, run `doctor`, start one service cycle, and confirm backlog
   drains in source-sequence order until the collector-reported acknowledged
   and highest source sequences exactly match both central mirrors,
   gaps/corruption are zero, one recovery is recorded, and downstream monitors
   leave stale state. A zero-item heartbeat with a lagging local checkpoint must
   keep the same root path correlation open rather than record recovery. Then
   restore the timer.

3. The restored signed configuration must contain only the approved Site,
   networks, Devices and protocols. Exercise at least one approved
   credentialed remote protocol (SNMPv3, read-only SSH, or approved WinRM)
   through the secret broker. Confirm the short-lived identity-bound lease is
   accepted, a fresh canonical observation returns after recovery, and no
   plaintext or reusable credential appears in the identity, configuration,
   spool, journal, proxy log, application log, or evidence attachment. Protocol
   breadth beyond this collector recovery proof remains the protocol release
   gate.

4. Stop the timer, preserve the old private identity, and revoke that identity
   through the audited reasoned operator workflow. Before issuing replacement
   material, confirm requests made with that preserved identity remain denied:

   ```bash
   sudo -u oblivion-monitoring-collector /usr/bin/php8.4 \
     /opt/oblivion-monitoring-collector/current/bin/oblivion-collector \
     verify-transport \
     --identity=/var/lib/oblivion-monitoring-collector/collector.identity.json \
     --expect=revoked \
     --samples=5
   ```

   Every sample must match the exact generic authentication-denial response
   contract. The probe does not prove why authentication was denied or that the
   matching body originated in the application. Pair it with the central
   revocation audit, the verified-mTLS proxy configuration, and sanitized
   upstream routing evidence showing the request was forwarded to the
   collector middleware. A controller validation response fails the revoked
   sequence.

5. Issue one replacement enrolment for the exact revoked collector UUID and
   consume the token once through the approved secret injector. `enrol`
   generates fresh request-signing and mTLS material; do not restore the old
   key or certificate. Fetch a fresh signed configuration, run one bounded
   collection cycle, repeat the active five-sample transport proof, confirm a
   current heartbeat and contiguous zero-backlog checkpoint, then restart the
   timer. Confirm a second use of the enrolment token is denied and the central
   audit shows ordered revoke, replacement issue, consumption, and restored
   service evidence.

## Escalation, repair rule, and closure evidence

Control Room owns service impact; the Site/network owner checks reachability; the security owner handles compromise; the runtime owner handles ordered ingestion. Use forward replacement for revoked identities. Roll back only a bad signed configuration while it remains valid and never roll back sequence counters. Close with collector/Site references, the value-free active/revoked/re-enrolled transport results, CA/proxy/shared-Redis deployment review, revocation and replacement-enrolment audit IDs, outage/recovery correlation references, credentialed-protocol evidence, backlog/gap before/after, last accepted sequence, configuration version, recovery time, and unrelated-Site validation. A local probe, configuration review without the real endpoint, or same-instance-only replay sample does not close deployed A03 evidence.
