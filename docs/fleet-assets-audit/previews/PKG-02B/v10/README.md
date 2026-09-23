# PKG-02B v10 — contextual calendar actions

Open http://127.0.0.1:4345/PKG-02B/v10/#/fleet-assets/vehicles/14/calendar

Right-click an empty date/time to request a booking, schedule service or inspection, add a reminder, mark the vehicle unavailable, or view the day. Past dates also open trip history filtered to that date. Past-time creation actions are disabled.

Entry actions follow the owning record and status: bookings support edit, approval/decline, checkout, return and cancellation; service entries open Maintenance, manage appointments and upload work evidence; follow-up reminders support edit, snooze, completion and activity; restrictions link to their source, Maintenance and release requirements. Source-generated compliance/service due reminders offer acknowledgement and linked follow-up without changing the obligation's due date.

Service scheduling creates one Maintenance work order or updates a selected open record while preserving its source and evidence. Unavailable appointments check booking conflicts. Reminder snooze preserves the recurring schedule anchor. Declined bookings retain history and stop reserving calendar time. Restrictions still prevent approval/checkout; all mutations use existing review workflows and permission checks.

Synthetic isolated mockup only. Main has not been notified. v9 and earlier candidates remain frozen. No provider messages, live integrations or production changes.

## Restart

`C:/Users/steph/.hermes/node/node.exe --use-system-ca docs/fleet-assets-audit/previews/PKG-02B/v10/serve.mjs`

Localhost port 4345. GET/HEAD only, no application APIs. The bounded report-image proxy uses public OSM tiles for synthetic Wellington routes. TLS remains verified. Operational records and files reset on reload; catalogue choices remain local to this preview origin.

## Verification

TypeScript and Vite build pass (existing chunk-size and Leaflet import warnings). Twelve targeted source-executed workflow/action checks pass. Browser verification covers menus across all five calendar views, reminder create/snooze/complete, Maintenance appointment creation and linked sample evidence, booking request/decline, unavailable blocks, trip-date navigation, restricted approval, view-only and report-only menus. Final rebuilt calendar has no new browser console errors. Inherited unrelated workflows were not exhaustively rerun.
