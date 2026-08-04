# Provider outage

## Trigger and customer-visible symptoms

Trigger on `monitoring_provider_queue_lag`, `provider_cursor_partial`, `provider_rate_limited`, or a provider connection state of `error`. Users may see stale imported inventory, delayed provider events, or a partial capability status. Native checks and unrelated providers must remain available.

## Distinguish the failure

- Provider failure: one typed provider manifest is partial, rate-limited, or unavailable while its Site path and native checks remain current.
- Runtime failure: several providers and native queues are delayed together.
- Site-path or collector failure: only Devices behind one Site path are stale.
- Device failure: one canonical Device has fresh failed observations.
- Storage failure: current state may update while retained history or snapshots report unavailable.

## Safe read-only diagnosis

```bash
php artisan queue:monitor monitoring-provider --max=100 --json
supervisorctl status oblivion-monitoring-provider:*
php artisan queue:failed
```

Review Security & Devices → Integrations for manifest version, advertised capabilities, cursor scope count, partial scopes, latest completion, and bounded exception codes. Do not inspect or copy credential values, raw cursors, or provider payloads.

## Containment that preserves evidence

Keep the provider cursor, exception records, queue items, and integration audit trail. If provider traffic is worsening the incident, an authorised integration manager may disable only the affected connection through the audited integration workflow. Do not delete cursor, exception, inbox, outbox, or DeviceEvent records.

## Recovery and replay

Correct credentials or provider-side access through the normal credential rotation/test workflow. Respect the typed manifest page, backfill, and minimum-interval bounds. Restart only the provider workers when required, then allow the scheduled pull to continue from its persisted cursor. Replay a dead letter only after its cause is corrected:

```bash
php artisan monitoring:replay-dead-letter <id> --actor=<user-id> --reason="Provider incident corrected"
```

## Validation

Confirm the provider queue returns below threshold, partial scopes reach zero, latest completion advances, no duplicate canonical Devices appear, and unrelated providers/Sites remain current. Reconcile imported, unassigned, duplicate-candidate, and exception counts with their drill-down rows.

## Escalation, repair rule, and closure evidence

Control Room owns customer-impact triage; the IT platform owner owns runtime recovery; the integration owner coordinates the vendor. Prefer forward repair and bounded replay. Roll back only a newly deployed adapter when its prior manifest remains compatible with stored cursors. Close with alert times, affected capabilities/Sites, queue and cursor evidence, action audit IDs, replay IDs, recovery time, and confirmation that no credential or raw payload was recorded.
