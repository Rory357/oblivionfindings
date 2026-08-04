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
identity and never enrols automatically. Do not delete a lock, checkpoint,
spool, quarantine item, identity, or signed configuration to make the service
appear healthy.

## Validation

Confirm heartbeat is current, backlog trends to zero, acknowledged equals highest seen, gap/corruption states clear, configuration sequence never regresses, and downstream monitors recover only after contiguous evidence returns. Verify unrelated approved Sites and collectors continued normally.

## Escalation, repair rule, and closure evidence

Control Room owns service impact; the Site/network owner checks reachability; the security owner handles compromise; the runtime owner handles ordered ingestion. Use forward replacement for revoked identities. Roll back only a bad signed configuration while it remains valid and never roll back sequence counters. Close with collector/Site references, revocation or enrolment audit IDs, backlog/gap before/after, last accepted sequence, configuration version, recovery time, and unrelated-Site validation.
