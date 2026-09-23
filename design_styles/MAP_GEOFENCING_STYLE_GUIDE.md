# Maps and universal geofencing

User-authorized Rory design amendment, 22 September 2026. Reference inspected: the published Client Location implementation in `resources/js/components/client-location/`, including `location-workspace`, `client-location-map`, `zone-draft-dialog`, `zone-monitoring-dialog`, `types`, and `boundary-geometry`. This guide describes the shared interaction contract; it does not declare a new backend registry implemented.

## Map presentation

- Use greyscale base tiles. Apply the filter to the tile pane only: markers, routes, zones and status overlays retain semantic colours. Apply this consistently to location, trip history, boundary editors and map modals, in both themes.
- Keep a legible source timestamp and location provenance beside the map. Historical, missing and stale observations must not appear live. Motion is an icon with an accessible explanation beside the location information; it describes the observation, not continuous or current movement.
- Preserve attribution, zoom, recenter, layer controls, accessible focus, and a useful unavailable state with permitted coordinates/source times. Never infer vehicle readiness or custody from a position.

## One boundary, separate assignments and monitoring

- Universal geofences are canonical shared boundaries, reusable from sites, houses, clients, vehicles and future maps. Search/select an existing permitted boundary or create it from the current profile with owner context locked.
- Keep boundary identity, geometry version/hash and ownership distinct from a profile assignment's purpose, schedule, response proposal and monitoring authority. Selecting a boundary does not start alerts or tracking.
- Do not silently mutate shared geometry from a profile. A linked boundary is read-only; provide an explicit **Make a custom copy** action. Changes or loss of access to the source require re-selection/review, never silent acceptance of a stale snapshot.
- Reuse Client Location's circle/polygon geometry validation and editing controls: draw, finish polygon, drag whole boundary or handles, keyboard adjustment, undo/redo, clear, radius editing, search/recenter, and right-click **Draw a zone here**. Address search must be permission scoped; previews use clearly labelled synthetic results.
- Editing a monitored assignment requires pausing monitoring first. Pause stops new alerts; it does not close existing alerts or erase prior evidence.
- Universal selection is a cross-profile product requirement. Do not create separate incompatible client, vehicle and site geofence catalogs. Authorization remains roles, approved sites, record ownership and privacy within the single operating organisation.

## Structured assignment flow

Use the shared WizardShell: **Draw/select boundary → Name & purpose → Schedule → Review inactive assignment**. Allow free rail navigation; validate Continue and final save, show retained input and errors, guard dirty cancellation and show explicit success.

Schedules use the site's named timezone (the current NZ examples use Pacific/Auckland), weekdays, start/end times, explicit following-day handling, first/last dates and removable exception dates. Use shared date/time pickers; validate schedule and geometry before save. Review must show the selected source/version, geometry, purpose, profile, schedule, exclusions and inactive monitoring state.

Monitoring is a separate permission-aware action with an approved policy, tracker/source, recipients, instructions and confirmation. Reuse Client Location's separation of draft and monitoring, but do not copy its client-specific inside/outside classifications, Control Room destination or alert priority as vehicle defaults. Domain owners must approve those operational rules.

## Acceptance

Verify greyscale imagery with coloured overlays; keyboard and right-click interactions; valid/invalid geometry; linked-source review; draft retention; schedule exceptions/overnight cases; view-only restrictions; inactive save; and source/permission changes. Prototype evidence does not prove real tracking, alert delivery or production persistence.

## Map interactions and vehicle telemetry — 22 September refinement

- A map right-click opens a compact contextual menu at the clicked point, not a modal. Offer relevant coordinate actions such as centre here, create geofence here and select existing geofence. The subsequent creation workflow may open the shared wizard.
- A vehicle right-click offers vehicle actions: telemetry, trip history, permitted alerts and follow-up. Hover and keyboard focus expose a compact state card with icons for ignition, speed, motion and vehicle voltage. Keep a visible toolbar alternative for keyboard and touch use.
- Fill the map card's available width and height. Invalidate Leaflet size after layout changes. Vehicle trip route panels use a compact landscape map (about 340–400px high on desktop) with a narrow inspector, recorded-point navigation and event selection. Avoid stretching the map to match an oversized inspector; use concise metrics and expandable detail.
- The reported-state inspector uses labelled icon/value tiles. Preserve sample time, source, freshness and historical/current distinction. Unknown values stay unknown; a stale or unplugged tracker cannot prove ignition or motion state.
- Capability-gate by the exact tracker model and validated firmware. GV500CG's OBD connection supplies power only; do not present ECU VIN, diagnostic codes or dashboard odometer as direct CG readings. External diagnostic providers retain their own source identity.
- Separate vehicle supply voltage from the tracker backup battery. Separate calibrated tracker-distance planning estimates from retained dashboard observations. Reconciliation preserves the observation, discrepancy reason, evidence and calibration history; missing samples or newer manual observations require review.
- Potential collision and vehicle-fault signals retain vehicle, device/provider, trip (when available), observed/received times, location and correlation identity. They create a Control Room alert for permitted triage. Maintenance creation follows an explicit assessment decision; alert resolution, work completion and vehicle release remain separate.
- Driving insights preserve trip coverage, calculation method and attribution limits. Missing coverage must not produce a perfect score. A booking assignment alone does not establish the actual driver. Sparse route lines are not verified road paths.
