# False-alert storm

## Trigger and customer-visible symptoms

Trigger on `monitoring_alert_rate_spike`, `correlation_fanout_spike`, repeated state flapping, or an unexpected surge in linked IT incidents. Users may receive excessive notifications or see many apparent failures even though a shared Site path, collector, dependency, or faulty policy is the actual cause.

## Distinguish the failure

- Root Site-path/collector failure: many downstream monitors become stale or collection-unavailable together.
- Dependency storm: one root monitor fails and symptoms should be suppressed but remain inspectable.
- Device failure: one Device has fresh, independent failed evidence.
- Runtime/storage failure: collection or retained evidence is unavailable; it must not be presented as confirmed Device failure.
- Policy defect: confirmation, hysteresis, maintenance, or dependency configuration changed immediately before the surge.

## Safe read-only diagnosis

```bash
php artisan queue:monitor monitoring-events,monitoring-checks --max=1000 --json
supervisorctl status oblivion-monitoring-events:* oblivion-monitoring-checks:*
php artisan queue:failed
```

Compare reported versus effective state, root monitor, dependency confidence/review state, suppression reason, maintenance window, collection path, and one-to-one Control Room/IT correlation. Preserve raw operational evidence in its governed store; do not copy it into tickets or chat.

## Containment that preserves evidence

An authorised operator may apply an audited maintenance policy that pauses notification and ticket automation for the affected scope. Observation ingestion, inbox/outbox, Device Events, correlations, and retained history must continue. Never bulk-delete observations, findings, tickets, or dead letters to make the dashboard quiet.

## Recovery and replay

Correct the dependency/policy record or restore the collection path. Wait for the configured recovery confirmations and duration. Remove the maintenance policy through the audited workflow, then replay only automation items that were intentionally held and remain actionable. Never auto-resolve technician-owned IT work solely because the monitor recovered.

## Validation

Confirm one root cause is counted, suppressed symptoms remain inspectable, alert creation rate returns to baseline, fresh recovery evidence is present, and existing IT incidents retain ownership/status. Verify denied-Site and privacy-restricted counts remain concealed.

## Escalation, repair rule, and closure evidence

Control Room leads triage; the monitoring policy owner validates correlation; IT service management owns technician work. Prefer forward correction of policy/dependency records. Roll back only the specific recent ruleset when its previous reviewed version is known. Close with rate graphs, root/symptom counts, maintenance audit ID, policy diff, affected Site scope, held/replayed automation IDs, and confirmation that observations were not deleted.
