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

The single offline root event pins the exact sorted enabled-monitor roster at the
outage boundary, its count, affected Device count, and a value-free SHA-256
fingerprint. Those internal monitor IDs are correlation provenance only; never
substitute targets, protocol configuration, or credentials in the event or
export them into an evidence attachment. Recovery must fail closed if the
pinned roster evidence is missing or malformed, a pinned monitor has moved or
been disabled, or an enabled monitor has been added. Restore the exact roster
relationship before recovery or escalate the conflicting change; never edit
the append-only event to force closure. Planned roster changes occur after the
original correlation has recovered.

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
   value-free v2 JSON binds the response contracts to opaque SHA-256 references
   for the collector UUID, request-signing public key and complete mTLS/signing
   identity generation, plus exact UTC observation bounds. Compare those
   references to the central enrolment/audit record without copying the raw UUID,
   key or certificate into the attachment. Separately
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
   leave stale state. A zero-item heartbeat with a lagging local checkpoint, or
   with no newly persisted canonical observation after the outage boundary for
   every monitor in the pinned outage roster, must keep the same root path
   correlation open rather than record recovery. Confirm the recovery event
   reuses the original roster fingerprint and affected counts. Disable, add,
   or move one monitor during a controlled negative check and confirm recovery
   remains fail-closed even after every candidate monitor has a post-boundary
   canonical observation; restore the original roster before completing the
   acceptance sequence. `checks_executed` alone is not recovery evidence. Then
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
   sequence. The revoked result must have the same collector, signing-key and
   identity-generation references as the initial active result; a result from a
   different collector or identity cannot be substituted.

5. Issue one replacement enrolment for the exact revoked collector UUID and
   consume the token once through the approved secret injector. `enrol`
   generates fresh request-signing and mTLS material; do not restore the old
   key or certificate. A general Site enrolment token, including one issued
   before revocation and still inside its validity window, cannot reactivate an
   existing collector UUID; only the collector-specific replacement enrolment
   can do so. Fetch a fresh signed configuration, run one bounded collection
   cycle, repeat the active five-sample transport proof, confirm its collector
   reference matches the pre-revocation evidence while its signing-key and
   identity-generation references are both different, confirm a current
   heartbeat and contiguous zero-backlog checkpoint, then restart the timer.
   Confirm a second use of the replacement token and an attempted use of a
   still-valid general Site token against the revoked UUID are both denied, and
   the central audit shows ordered revoke, replacement issue, consumption, and
   restored service evidence.

## Protected combined release evidence

The five deployed steps above form one exercise. Individual transport JSON
documents are diagnostic inputs, not interchangeable release proof. Before the
exercise, a release owner installs the exact duplicate-free JSON authority at
`/etc/oblivion/monitoring-collector-release-authority.json`. It must be a stable
regular non-symlink file owned by root, not group/other writable, valid for no
more than 24 hours, and contain only:

- `schema_version=1` and
  `evidence_class=monitoring_collector_release_authority_v1`;
- one opaque `AUTHORITY-` reference, the exact reviewed 40-hex `origin/main`
  revision and an opaque environment SHA-256;
- opaque SHA-256 commitments to the genuinely remote Site and approved
  load-balanced endpoint; and
- the SHA-256 of the independent Ed25519 public key authorised to sign the
  combined exercise, plus exact UTC `not_before` and `not_after` bounds.

There is no command-line or environment override for that trust root. The
signed `monitoring_collector_release_evidence_v1` document must link the exact
authority hash/reference, environment, revision, remote Site, load balancer and
the byte hashes of the initial-active, revoked and replacement-active v2
transport documents. It records only opaque commitments and bounded counts. It
must prove all application instances were reviewed during this exercise; the dedicated CA, proxy,
verified-certificate-header replacement, disabled legacy header, shared Redis,
`nginx -t`, load-balancer routing and cross-instance replay evidence; and the
single pinned-roster outage/recovery, unrelated-Site and roster-drift negative
check. It also binds one post-recovery accepted credentialed protocol lease with a clean
plaintext scan, the ordered central revocation/replacement audits, replacement
and general-token reuse denials, current replacement heartbeat and zero
backlog.

The verifier requires the same collector reference across all three transport
documents, the old signing key and identity generation across initial-active
and revoked evidence, and fresh signing and identity-generation references in
the replacement-active evidence. Every transport phase is exactly five
samples. Deployment review, credential observation, revocation, revoked
transport, replacement issue/consumption, replacement transport, reuse denials
and restored-service evidence must form one ordered interval inside the
authority window. The outage must contain buffered evidence, exactly one root
correlation, the complete pinned monitor roster returning after its boundary,
matching non-zero acknowledged/highest sequences, zero final gap/corruption,
and downstream recovery before revocation.

Retain the three transport JSON documents, signed combined document, detached
signature and public key in a private external directory outside the checkout,
then run from the exact deployed release:

```bash
/usr/bin/php8.4 scripts/monitoring/verify-collector-release-evidence.php \
  --active-transport=/private/evidence/collector-active.json \
  --revoked-transport=/private/evidence/collector-revoked.json \
  --replacement-transport=/private/evidence/collector-replacement.json \
  --evidence=/private/evidence/collector-exercise.json \
  --signature=/private/evidence/collector-exercise.sig \
  --public-key=/private/evidence/collector-exercise.pub
```

The verifier accepts only stable regular external files that are not
group/other writable, a clean exact `HEAD == origin/main` checkout, and one
protected authority, environment, revision, remote Site and load balancer
before and after verification. It emits a single value-free result and fails
closed on mixed runs, substituted signers, incomplete rosters, weak deployment
review, chronology drift or authority/revision replacement. This executable
gate makes the deployed evidence coherent; running it with local fixtures or
without the real remote collector, load balancer, shared Redis, controlled
outage, credential lease and revocation/re-enrolment exercise does not itself
close A03.

## Escalation, repair rule, and closure evidence

Control Room owns service impact; the Site/network owner checks reachability; the security owner handles compromise; the runtime owner handles ordered ingestion. Use forward replacement for revoked identities. Roll back only a bad signed configuration while it remains valid and never roll back sequence counters. Close with collector/Site references, the value-free active/revoked/re-enrolled transport results, CA/proxy/shared-Redis deployment review, revocation and replacement-enrolment audit IDs, outage/recovery correlation references, credentialed-protocol evidence, backlog/gap before/after, last accepted sequence, configuration version, recovery time, and unrelated-Site validation. A local probe, configuration review without the real endpoint, or same-instance-only replay sample does not close deployed A03 evidence.
