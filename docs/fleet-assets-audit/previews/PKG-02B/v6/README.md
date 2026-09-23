# PKG-02B v6 — vehicle profile mockup

Review: http://127.0.0.1:4341/PKG-02B/v6/#/fleet-assets/vehicles/14/trips

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
- Compact map and vehicle context menus, hover/focus stats, icon-based reported state, and a map that fills its card. The trip explorer now has a compact 360px landscape map, narrow inspector, recorded-point replay, per-trip speed/events/coverage and transparent illustrative scores. This supersedes the earlier square-map layout.
- Overspeed episodes now have measured speed/duration, rule evidence, trip events and Control Room routing. Driving analytics include daily trends, rates, eligibility and a score breakdown; personal scores remain withheld pending confirmed attribution.
- Fault or potential-collision signal → synthetic Control Room record → triage decision → optional Maintenance assessment. Trip, source, device/provider, location and correlation are retained. Delivery failure/retry, duplicates and independent resolution/release are demonstrated.

GV500CG is the confirmed model. Its OBD connection is power-only: ECU VIN, dashboard odometer and diagnostic codes are not claimed as direct CG data. An explicitly separate diagnostic-source example demonstrates the same fault-triage route. See the linked research note in the evidence folder.

## Booking journey

Choose times, driver, purpose and keys location, then select the approval route. **Approval required** stays pending for coordinator review. **Approval not required** needs a reason or file, coordinator authority, readiness/driver review and passing conflict/readiness checks. Report-only staff remain pending for verification. Both paths continue through keys, checkout, condition and return; return updates mileage and a concern creates Maintenance follow-up. Neither route releases a restriction. These are example interactions, not approved live policy.

## Run after a restart

From the repository root:

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v6/serve.mjs`

The prebuilt bundle is included. The server binds to 127.0.0.1:4341, accepts GET/HEAD only and blocks application API connections. Public map imagery uses synthetic coordinates. Forms, photos, files, geofences and workflow changes live in browser memory and reset on reload; the mockup files are saved on disk.

## Verification

See `../../../evidence/PKG-02B/v6/REVISION-REVIEW.md`, `browser-evidence.json` and `manifest.json`. The inherited v5 package covers all profile tabs. This revision checks the five calendar views and targeted changed workflows. TypeScript and Vite build pass. Bundle-size and Leaflet import warnings remain. No final-bundle errors were captured during final map lifecycle/navigation checks.

## New v6 workflows and exports

- Confirm actual drivers and handovers by recorded point. Review, dismiss or dispute events, retain the original and recalculate reviewed scores. Versioned illustrative policy and minimum samples gate personal scores.
- Assigned coaching has acknowledgement/completion and a shared calendar reminder. Control Room has acknowledgement deadlines, demonstration clock/backup escalation and triage-gated linking to existing Maintenance work.
- Mapped speed-source scenarios and evidence-backed manual limits have direction, effective window, review and expiry. Unknown road limits remain unknown; fleet thresholds are separate. No actual speed-limit provider is connected.
- Trip date/driver/event filters, pagination, retained filters and timed recorded-point replay. Shared calendar controls are used for dates and speed observations.
- Real browser-generated PDF and Excel reports use inclusive dates/current filters across pages. Branding includes the organisation ring logo and purple styling, with optional greyscale route pictures and event review outcomes. Excel has typed dates/numbers, filters, totals and embedded maps.

The read-only report-image proxy allows only public OSM PNG tiles around the synthetic Wellington examples. System certificate trust is enabled; TLS verification stays enabled. Maps need connectivity; failed loading offers retry or generation without images. Trip filters persist in session storage; operational changes and uploads reset on reload.

Saved report examples are in the evidence directory. PDF pages were rendered and reviewed. Workbook data, totals, PNGs and anchors were verified; native Excel UI was not tested. In-app download event detection timed out despite a valid report link; byte-identical report copies are supplied for direct opening. The PDF standard font transliterates macrons; Excel preserves Unicode. These are design-review artifacts, not production reporting acceptance.
