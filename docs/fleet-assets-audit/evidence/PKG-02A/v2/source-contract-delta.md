# V2 source findings and implementation decisions

Baseline 2302ca33a95616e442a78e957ddce82d8db98669. Read with the frozen v1 source-contract.md; existing privacy, assignment, export, job, realtime and cache boundaries continue to apply.

## Reuse and gaps

- AssetGeofence supports canonical circle/polygon geometry, alert_config and time_rules. Keep shared boundary identity/version separate from Alex's purpose, schedule and authority. A local proposal must not overwrite other uses of the same boundary.
- FleetGeofenceService evaluates asset-associated active zones and enter/exit/dwell events. The inspected evaluation path does not apply time_rules. A schedule editor alone therefore cannot demonstrate schedule enforcement. Existing trigger naming also needs reconciliation in the later backend contract.
- ClientController currently derives one eligible house/site geofence for its client-location response. GeofenceStatusService evaluates one circle/polygon against a point and handles missing geometry/location, but it is not a full timestamp/accuracy/schedule/privacy policy evaluator. Multiple client-specific zones need an explicit read/evaluation contract.
- Existing Fleet geofence creation and GeofenceDrawMap provide reusable geometry concepts; their presence does not establish client-specific weekly schedules, exceptions or temporary outing authority.
- WF-07's evidence quality, ordered observations, server evaluation, deduplication/outbox and existing Control Room response ownership remain the architectural direction. WF-08's collection/viewing/sharing separation remains intact. No new alert lifecycle or monitoring service is authorised here.

## Decisions required before activation

Define permitted client purposes, rule approval/versioning and shared-boundary impact checks; circle/polygon validation and map scale; source age/accuracy/confirmation/dwell thresholds; overlap precedence and attention-area interaction; weekly and overnight semantics, date bounds, exceptions, DST and timezone; temporary rule expiry; missing-arrival/return evidence; authorised response ownership and escalation. Do not adopt synthetic defaults or infer that any zone proves safety.

The UI must revalidate exact client/site/assignment/collection authority and staff capability before reading or changing plans. A schedule or outing is not consent, recipient sharing or an automatic alert exemption. Keep governed history/export separate; clear protected map, selections, activity and drafts on loss of relevant access. Every applicable query, command, job, realtime delivery and cache needs the canonical privacy boundary. Prototype state changes do not prove these controls.

## Mockup limits

Fictional SVG geometry only: map units do not claim real metres; polygon minimum-point validation is illustrative, not full topology validation. Drafts live only in memory and reset on reload or review-state change. Weekly timeline uses fixtures, not a scheduler. Activity uses one synthetic day and event-type filtering; production history date ranges must be preserved. No real map provider, device request, database write, notification or external connection occurs. Desktop only.
