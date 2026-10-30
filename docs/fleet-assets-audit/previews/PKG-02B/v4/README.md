# PKG-02B v4 — vehicle profile mockup

Review: http://127.0.0.1:4339/PKG-02B/v4/#/fleet-assets/vehicles/14/overview

Isolated desktop mockup for Stephan's review. Previous versions are preserved. No production application or shared component was edited. This revision has not been sent to Main; exact mockup and implementation-scope approval remain pending.

## Revision

- Compact readiness dashboard, next action, next service and compliance summary without nested scrolling. Canonical Event Horizon header and navigation retained.
- Service cards with date/distance progress, ownership and PDF/image uploads. Descending service-history timeline with search and outcome filters. Compact Maintenance domain tabs and collapsible notes/activity.
- Inspection templates, recent checks and next requirement in one workspace. Files attach during submission or subsequently to the owning check; original answers and versions remain intact.
- Vehicle profile photo upload from the header or Vehicle details, including preview, validation and draft protection.
- Actual Leaflet basemap and historical trail with a right-side motion/status inspector patterned on Client Location. Dedicated vehicle Trip history with filters, route, stops, driver and source evidence.
- Shared-geofence selection and a drawing wizard to create and link boundaries. Universal registry architecture is preserved for the later Main handoff.
- Canonical Month, Week, Day, Agenda and Timeline. Right-click a date/time to request a booking; right-click an entry for source and permitted workflow actions.

## Booking journey

Choose times, driver, purpose and keys location, then select the approval route. **Approval required** stays pending for coordinator review. **Approval not required** needs a reason or file, coordinator authority, readiness/driver review and passing conflict/readiness checks. Report-only staff remain pending for verification. Both paths continue through keys, checkout, condition and return; return updates mileage and a concern creates Maintenance follow-up. Neither route releases a restriction. These are example interactions, not approved live policy.

## Run after a restart

From the repository root:

`C:/Users/steph/.hermes/node/node.exe docs/fleet-assets-audit/previews/PKG-02B/v4/serve.mjs`

The prebuilt bundle is included. The server binds to 127.0.0.1:4339, accepts GET/HEAD only and blocks application API connections. Public map imagery uses synthetic coordinates. Forms, photos, files, geofences and workflow changes live in browser memory and reset on reload; the mockup files are saved on disk.

## Verification

See `../../../evidence/PKG-02B/v4/REVISION-REVIEW.md`, `browser-evidence.json` and `manifest.json`. All profile tabs and five calendar views checked. Targeted TypeScript and Vite build pass. Bundle-size and Leaflet import warnings remain. No final-bundle errors were captured during final map lifecycle/navigation checks.
