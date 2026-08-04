# Queue backlog and dead-letter recovery

## Trigger and customer-visible symptoms

Trigger on `monitoring_queue_lag`, `monitoring_oldest_job_age`, `monitoring_dead_letter_created`, or a missing worker heartbeat. Users may see delayed monitoring state, discovery results, topology, provider sync, or maintenance work; delayed data must be labelled stale rather than failed.

## Distinguish the failure

- Queue/runtime failure: pending and oldest-age values rise for one or more runtime queues.
- Collector or Site-path failure: central queues remain normal while a collector reports backlog/gaps.
- Device failure: fresh checks complete and only the Device state fails.
- Storage failure: queue processing may be current while time-series or snapshot health is unavailable.
- Regional failure: all workers, listeners, Redis, and application health degrade together.

## Safe read-only diagnosis

```bash
php artisan queue:monitor monitoring,monitoring-events,monitoring-checks,monitoring-discovery,monitoring-provider,monitoring-topology,monitoring-maintenance,monitoring-commands --max=1000 --json
supervisorctl status
php artisan queue:failed
```

Use the authenticated `/security-devices/runtime-health` response to compare pending count, oldest age, DLQ count, consumed per-queue heartbeat age, worker/listener state, and storage state. Command queue counts remain visible only to authorised all-Site operators. Do not open envelope bytes or raw exception payloads.

## Containment that preserves evidence

Stop only the affected worker group if jobs are repeatedly poisoning the queue. Keep Redis, inbox/outbox, checkpoints, failed jobs, and dead letters intact. Scale within the approved Supervisor process bounds; do not merge specialised queues into `default` or the orchestration queue.

## Recovery and replay

Correct the underlying dependency or code fault, restart the affected Supervisor group, and recover expired delivery leases once:

```bash
php artisan monitoring:recover-delivery
```

Review each dead letter by bounded reason code and Site scope. Replay individually with an accountable actor and reason; discard only when the evidence proves the item is permanently invalid and the resolution is approved.

## Validation

Confirm queue depth and oldest age decline, checkpoints advance without gaps, one inbox effect exists per message/idempotency identity, and no duplicate DeviceEvent, Control Room correlation, or IT incident is created. Confirm unrelated queues and Sites were not delayed by containment.

## Escalation, repair rule, and closure evidence

Control Room owns impact communication; the runtime on-call owns queues; the relevant capability owner owns poisoned data. Prefer forward repair and idempotent replay. Roll back a release only when queue contracts and stored envelopes remain backward-compatible. Close with queue snapshots before/after, worker status, failed/dead-letter IDs, actor/reason audit, checkpoint continuity, duplicate check, and recovery time.
