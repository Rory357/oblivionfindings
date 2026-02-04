# Missing Items Checklist (Generated)

## Control Room (highest priority)
- [x] Control Room module scaffolding (routes, controller, UI shell).
- [x] Control Room data model for alerts + triage status.
- [x] Fleet signal -> Control Room signal processing.
- [x] RBAC permissions for Control Room access.
- [x] Signal ingestion + rules engine (queue assignment, dedup, playbook attach, SLA attach).
- [x] SLA breach checks + auto escalation jobs.
- [x] Alert queue history model + auto queue escalation job.

## Fleet Signal Delivery
- [x] Implement outbox delivery to Control Room (replace stub).
- [x] Error handling + retries.

## Maps Cost Controls
- [x] Usage dashboard page (basic counts by context/user).
- [x] Reverse geocode implementation or explicit "not implemented" UI.

## Fleet Logic Enhancements
- [x] Geofence enter/exit/dwell event types.
- [x] Trip segmentation / idle detection.
- [x] Driver behaviour scoring (basic).

## Retention & Compliance
- [x] Asset telemetry retention (snapshots/history).
- [x] Consent enforcement audits (reporting).

## UX
- [x] Live updates (polling or websockets).
- [x] Filters/search in Fleet dashboard.
