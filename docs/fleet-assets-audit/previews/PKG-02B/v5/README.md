# PKG-02B v5 — vehicle profile mockup

Review: http://127.0.0.1:4340/PKG-02B/v5/#/fleet-assets/vehicles/14/overview

Isolated desktop mockup for Stephan's review. Previous versions are preserved. No production application or shared component was edited. This revision has not been sent to Main; exact mockup and implementation-scope approval remain pending.

## Revision

- Compact readiness dashboard, next action, next service and compliance summary without nested scrolling. Canonical Event Horizon header and navigation retained.
- Service cards with date/distance progress, ownership and PDF/image uploads. Descending service-history timeline with search and outcome filters. Compact Maintenance domain tabs and collapsible notes/activity.
- Inspection templates, recent checks and next requirement in one workspace. Files attach during submission or subsequently to the owning check; original answers and versions remain intact.
- Vehicle profile photo upload from the header or Vehicle details, including preview, validation and draft protection.
- Actual Leaflet basemap and historical trail with a right-side motion/status inspector patterned on Client Location. Dedicated vehicle Trip history with filters, route, stops, driver and source evidence.
- Shared-geofence selection and a drawing wizard to create and link boundaries. Universal registry architecture is preserved for the later Main handoff.
- Canonical Month, Week, Day, Agenda and Timeline. Right-click a date/time to request a booking; right-click an entry for source and permitted workflow actions.
- Universal checklist editor with question types, ordering, evidence requirements and immutable submitted versions. Service types and month/distance intervals use searchable catalogues with explicit custom options.
- Redesigned mileage and reminder workflows, including correction evidence, calendar links and recurring follow-up. Calibrated GV500CG distance updates planning separately from dashboard observations; manual changes and missing samples require reconciliation.
- Compact map and vehicle context menus, hover/focus stats, icon-based reported state, and a map that fills its card. The trip explorer has a 1:1 map, recorded-point navigation, per-trip speed/events/coverage and transparent illustrative scores.
- Overspeed episodes now have measured speed/duration, rule evidence, trip events and Control Room routing. Driving analytics include daily trends, rates, eligibility and a score breakdown; personal scores remain withheld pending confirmed attribution.
- Fault or potential-collision signal → synthetic Control Room record → triage decision → optional Maintenance assessment. Trip, source, device/provider, location and correlation are retained. Delivery failure/retry, duplicates and independent resolution/release are demonstrated.

GV500CG is the confirmed model. Its OBD connection is power-only: ECU VIN, dashboard odometer and diagnostic codes are not claimed as direct CG data. An explicitly separate diagnostic-source example demonstrates the same fault-triage route. See the linked research note in the evidence folder.

## Booking journey

Choose times, driver, purpose and keys location, then select the approval route. **Approval required** stays pending for coordinator review. **Approval not required** needs a reason or file, coordinator authority, readiness/driver review and passing conflict/readiness checks. Report-only staff remain pending for verification. Both paths continue through keys, checkout, condition and return; return updates mileage and a concern creates Maintenance follow-up. Neither route releases a restriction. These are example interactions, not approved live policy.

## Run after a restart

From the repository root:

`C:/Users/steph/.hermes/node/node.exe docs/fleet-assets-audit/previews/PKG-02B/v5/serve.mjs`

The prebuilt bundle is included. The server binds to 127.0.0.1:4340, accepts GET/HEAD only and blocks application API connections. Public map imagery uses synthetic coordinates. Forms, photos, files, geofences and workflow changes live in browser memory and reset on reload; the mockup files are saved on disk.

## Verification

See `../../../evidence/PKG-02B/v5/REVISION-REVIEW.md`, `browser-evidence.json` and `manifest.json`. All profile tabs and five calendar views checked. Targeted TypeScript and Vite build pass. Bundle-size and Leaflet import warnings remain. No final-bundle errors were captured during final map lifecycle/navigation checks.
