# Stale estate evidence

## Trigger and customer-visible symptoms

Trigger on `monitoring_estate_stale`, `monitor_never_observed`, stale provider cursor, or broad coverage gaps. Users see Devices whose last observation is older than policy, missing discovery/inventory refresh, or an unknown state. Stale is uncertainty, not proof that a Device failed.

## Distinguish the failure

- Individual Device: only one last-observation timestamp is stale and its collection path is current.
- Site path: many Devices at one Site become stale at the same time.
- Collector: remote Devices behind one collector are stale with heartbeat/backlog evidence.
- Provider: imported/provider facts are stale while native checks remain current.
- Runtime/storage: queues/listeners are delayed, or only retained history/snapshots are unavailable.

## Safe read-only diagnosis

```bash
php artisan queue:monitor monitoring-checks,monitoring-discovery,monitoring-provider --max=1000 --json
supervisorctl status oblivion-monitoring-checks:* oblivion-monitoring-discovery:* oblivion-monitoring-provider:*
php artisan schedule:list
```

Review coverage classification, last observation, collection mode, collector heartbeat, discovery last completion, provider cursor completion, maintenance policy, and current Site reachability. Do not infer denied-Site Devices from global totals.

## Containment that preserves evidence

Keep stale rows visible with their timestamps and cause. If noise is material, apply an audited maintenance/suppression policy to the exact Site/path; continue ingestion and preserve existing observations. Do not manufacture healthy observations, edit timestamps, or bulk-remove unmonitored Devices.

## Recovery and replay

Restore the earliest failed dependency: schedule/worker, Site WAN, collector, credential lease, or provider. Run discovery or provider refresh only through its governed bounded workflow. Ordered monitoring delivery and normal confirmation rules determine recovery; never insert a manual healthy sample.

## Validation

Confirm scheduler and specialised queues are current, coverage totals reconcile to visible Devices, last-observation times advance from authentic checks, stale counts fall by the expected Site/path, and active findings/IT incidents keep their existing ownership.

## Escalation, repair rule, and closure evidence

Control Room owns prioritisation; Site/network, collector, provider, and runtime owners take the corresponding cause. Prefer forward repair. Roll back only a new scheduler/policy release with compatible persisted schedule keys. Close with stale count and oldest age before/after, affected Sites/path, root-cause evidence, work item links, recovery timestamps, and confirmation that no synthetic evidence was written.
