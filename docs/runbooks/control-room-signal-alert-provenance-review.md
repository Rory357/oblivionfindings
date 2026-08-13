# Control Room signal-to-alert provenance review

This runbook covers rows created in `control_room_signal_alert_provenance_reviews`
while the native one-signal/one-origin-alert constraint is introduced. The migration
never deletes, closes, resolves, merges, or hides an operational alert. When legacy
JSON provenance is ambiguous it deterministically selects the active alert first,
then the lowest alert ID, solely so future processors have one safe canonical owner;
every candidate remains live and is recorded for review.

## Review boundary

- Treat `origin_signal_id` as immutable creation provenance. Do not move it between
  alerts merely because a later correlated signal became the most recent context.
- Treat `control_room_signals.alert_id` as an origin link and
  `correlated_alert_id` as a grouped follow-up link.
- Do not delete, close, acknowledge, reassign, or merge an alert during evidence
  collection. Existing operational ownership and lifecycle state take priority.
- Work Site by Site. Confirm the signal, alert, client, asset and device relationships
  all belong to the same canonical Site before changing any link.
- Do not copy raw payload or private client narrative into review notes. Record only
  IDs, lifecycle facts, timestamps and the reconciliation decision.

## Deterministic review

1. List pending rows ordered by `signal_id`, then `alert_id`. Group all rows for the
   same signal before making a decision.
2. Compare typed links first: the signal's `alert_id`, `correlated_alert_id`, and each
   alert's `origin_signal_id`. Typed evidence outranks mutable JSON context.
3. Verify matching Site/client/asset/device provenance and compare `triggered_at`,
   `processed_at`, alert creation audit, queue entry, SLA/playbook state, notification
   outbox rows, acknowledgement, escalation, assignment and closure history.
4. If one alert has the complete creation trail, retain it as the origin. Other alerts
   stay operational until their owners decide whether they are genuinely distinct or
   require a separately authorised lifecycle reconciliation.
5. If evidence remains ambiguous, leave the review `pending`. Do not guess or move the
   selected link. Escalate to the Control Room operational owner with the candidate IDs.
6. Once proved, update the review row to `resolved`, record the reviewer, timestamp and
   minimum-necessary resolution note. Any correction to typed provenance must occur in
   its own transaction after rechecking the unique constraint and both record lifecycles.

Useful read-only query:

```sql
SELECT review.id,
       review.signal_id,
       review.alert_id,
       review.selected_alert_id,
       review.reason,
       review.status,
       signal.status AS signal_status,
       signal.alert_id AS signal_alert_id,
       signal.correlated_alert_id,
       alert.origin_signal_id,
       alert.status AS alert_status,
       alert.site_id,
       alert.client_id,
       alert.triggered_at
FROM control_room_signal_alert_provenance_reviews AS review
LEFT JOIN control_room_signals AS signal ON signal.id = review.signal_id
LEFT JOIN control_room_alerts AS alert ON alert.id = review.alert_id
WHERE review.status = 'pending'
ORDER BY review.signal_id, review.alert_id, review.id;
```

The database unique key `cr_alerts_origin_signal_uq` is the final authority: a second
operational alert cannot claim the same normalized origin signal.
