# Fleet Management Build Checklist

Scope: Full Fleet module + integrations (Asset Mgmt, Control Room signals-only, Google Maps), vendor-agnostic ingest (Queclink first), UI, and tests.

## Planning & Design
- [x] Define Fleet module boundaries and ownership rules (signals-only, no alerts).
- [x] Inventory existing Asset telemetry, geofences, trackers, and permissions.
- [ ] Confirm consent rules, trip thresholds, and Google Maps API limits with production values.

## Data Model & Migrations
- [x] Fleet telemetry, signals, trips, driver sessions, state snapshots tables.
- [x] Fleet integration cursors + reports tables.
- [x] Data retention/archival policy migration or scheduled job.

## Core Services
- [x] Vendor-agnostic telemetry adapter interface.
- [x] Queclink adapter (baseline mapping).
- [x] Generic adapter fallback.
- [x] Telemetry ingest service (idempotent, consent-aware).
- [x] Signal emission service (no alerts).
- [x] Trip detection service (start/stop).
- [x] Offline detection scheduled job.

## Controllers & Routes
- [x] Fleet dashboard route + controller.
- [x] Fleet vehicle detail route + controller.
- [x] Fleet trip playback route + controller.
- [x] Driver session start/end routes.
- [x] Telemetry ingest endpoint wired to Fleet service.

## UI / UX (Web)
- [x] Fleet dashboard with live list.
- [x] Vehicle detail page (status + recent telemetry + signals).
- [x] Trip view + playback map (Google Maps).
- [x] Minimal geofence editor integration.

## Google Maps Integration
- [x] JS SDK loader + map component.
- [x] Live vehicle map + trip playback map layers.
- [x] Reverse geocode throttling policy (config + stub).
- [x] Cost monitoring dashboard hooks.

## RBAC & Permissions
- [x] Fleet view permissions in RBAC seeder.
- [x] Fleet manage driver sessions permission.
- [x] Fleet signals view permission.

## Testing
- [x] Telemetry ingest idempotency test.
- [x] Consent masking test.
- [x] Trip start/stop test.
- [x] Geofence signal emission test.

## Operational
- [x] Queue jobs added where required.
- [x] Scheduling of offline detection + retention.
- [x] Scheduling of offline detection job.
- [x] .env.example updates for Fleet & Maps.

## Integration Contracts
- [x] Fleet signals are stored and emitted as events.
- [x] Control Room subscriber stub (outbox + dispatch job).
