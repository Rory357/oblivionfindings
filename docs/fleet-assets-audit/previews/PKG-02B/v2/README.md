# PKG-02B v2 — vehicle profile mockup

Local preview for Stephan's review. This revision has not been sent to Main and is not an implementation approval. The frozen v1 remains intact.

Open http://127.0.0.1:4337/PKG-02B/v2/#/fleet-assets/vehicles/14/map

## Revision

- Added a working Map destination using the shared Leaflet component and OpenStreetMap imagery. Only the basemap is greyscale; vehicle/site markers and the optional observation trail retain semantic colours. Location, timestamps and trails are fictional, with no live tracker request. The no-tracker and unavailable-imagery states retain useful context.
- Replaced the calendar list with the canonical Month, Week, Day, Agenda and Timeline components, Event Horizon date header, source filters, search, date navigation, jump picker and Today rail. Active restriction context persists independently of calendar navigation and filters. Estimates, internal plans, reminders and busy-only bookings retain distinct meanings.
- Reworked simple dialogs with the approved icon header, 480px detail and 720px standard widths, scrollable body and fixed footer. Structured check details and forms use the shared 1100px WizardShell. Detail sections use section titles without a completeness meter.
- Form rails allow free navigation. Continue and submit validate required fields; dirty drafts prompt before dismissal. The existing shared date/time and range components are reused. Maintenance ranges now have selection instructions, incomplete-range feedback, a readable announced summary, and matching review text.

## Run locally after a restart

From the repository root, run the existing Node runtime with this file:

`C:/Users/steph/.hermes/node/node.exe docs/fleet-assets-audit/previews/PKG-02B/v2/serve.mjs`

The prebuilt bundle is included. The server binds only to 127.0.0.1:4337, accepts GET/HEAD only and disables application API connections. Map images are fetched from OpenStreetMap; no application records or real coordinates are sent. Preview form changes live only in browser memory.

## Validation

See `../../../evidence/PKG-02B/v2/REVISION-REVIEW.md` for browser checks, screenshots and limits. No tracked application or shared-component files were changed. No commit, push or message to Main was made for this revision.
