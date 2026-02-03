# Missing Items Checklist (Generated)

## Control Room (highest priority)
- [x] Control Room module scaffolding (routes, controller, UI shell).
- [x] Control Room data model for alerts + triage status.
- [x] Fleet signal → Control Room alert conversion.
- [x] RBAC permissions for Control Room access.

## Fleet Signal Delivery
- [x] Implement outbox delivery to Control Room (replace stub).
- [x] Error handling + retries.

## Maps Cost Controls
- [x] Usage dashboard page (basic counts by context/user).
- [ ] Reverse geocode implementation or explicit “not implemented” UI.

## Fleet Logic Enhancements
- [x] Geofence enter/exit/dwell event types.
- [ ] Trip segmentation / idle detection.
- [ ] Driver behaviour scoring (basic).

## Retention & Compliance
- [x] Asset telemetry retention (snapshots/history).
- [ ] Consent enforcement audits (reporting).

## UX
- [ ] Live updates (polling or websockets).
- [ ] Filters/search in Fleet dashboard.
