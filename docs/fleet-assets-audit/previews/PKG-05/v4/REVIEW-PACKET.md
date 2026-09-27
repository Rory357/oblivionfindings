# Transport v4 — search, quick views, key custody and shared calendar

Status: ready for Stephan's exact design review. This is an isolated synthetic desktop preview. Application implementation is not released by this artifact.

Open [Returns & handovers](http://127.0.0.1:4398/#/fleet-assets/transports/returns), [Charlie’s journey](http://127.0.0.1:4398/#/fleet-assets/transports/journeys/J-609?from=journeys), or [Planner](http://127.0.0.1:4398/#/fleet-assets/transports/planner), then choose **Transport calendar**. Reload resets synthetic mutations. Existing v1, audit-v1, v2 and v3 artifacts remain unchanged.

## Calendar decision

Use one Fleet booking calendar with different views. Transport offers the passenger-planning view; Fleet provides the fleet-wide view; the vehicle profile shows that vehicle's bookings. The same booking identity and availability decision must appear in all three places. Users should not have to reconcile separate reservations.

V4 uses the existing Month, Week, Day and Agenda components from `resources/js/pages/sites/calendar/_parts.tsx`. Calendar entries derive directly from the same synthetic booking objects used by allocation. Saving Elliot’s allocation creates a booking visible immediately in the calendar, and opening it retains that exact identity. Date navigation, vehicle filtering, search, entry selection and right-click work. An optional other-Fleet-activity layer demonstrates a source-owned workshop restriction. Unallocated requests remain in **Allocate requests**. Date grids are scroll-contained and begin around 6 am, rather than displaying a long blank midnight-to-midnight page. No independent drag/reservation engine is introduced.

## Changes from the screenshots

- **Search is practical:** returns match passenger, vehicle/name/reference, driver/receiver, receipt, key, site point, handover, exception and history text. Queries support multiple words and ignore macron differences. History has its own event/person/reference/observation search and source filter. Clear and empty-result behavior are visible.
- **PDF downloads are real:** export the filtered queue, a full record, filtered history, or the permitted vehicle-trip results. A scope dialog shows what will be downloaded. Unicode fonts preserve Kōwhai, arrows and fuel fractions. A local-only renderer produces the synthetic PDF in memory; there are no operational writes. Stale or denied source states cannot export. List exports follow the current result filters; a full-record export is explicitly a separate action.
- **Quick views retain context:** selecting a row opens a record modal with Summary, Keys & site and History. It has the appropriate next action, Export PDF and Open full record. Opening a physical-receipt/storage form temporarily replaces the quick view; save or cancel returns to the same record. Full records retain their originating workspace. Existing visible menus and right-click remain equivalent.
- **Vehicle history is connected:** Charlie’s example links two explicit vehicle movements, outward and return, to passenger journey J-609 and booking BK-209. The source history is searchable and exportable. Passenger journey and vehicle movement remain distinct records. A manually recorded journey can have no tracker match. Vehicle-source access is checked separately, including hiding linked trip IDs/counts from roles without it.
- **Keys have a site plan and actual custody:** the panel shows key number, current holder or storage point, collection point, return point, assigned driver, receiving worker and the collection/delivery arrangement. Delivery requires a named approved worker and instructions. Site key-point details explain the office storage and reception handover points. No access codes are displayed.
- **Receipt and storage are separate:** recording keys received puts them with the receiving worker. A subsequent physical observation confirms their secure storage. The **Keys to store** queue and next step keep this work visible. Passenger completion remains separate. A planning change cannot move actual custody; a collected booking’s plan cannot be rewritten.
- **History remains truthful:** the latest vehicle key event determines current custody. Opening Charlie’s older completed receipt correctly shows Nia holding the key for a newer journey. An old receipt cannot overwrite that newer holder, and later receipts preserve the original observations/disputes.

## Three improvement passes

1. **Find and inspect:** replaced generic linked-record lists with compact source links, added useful search and scoped PDF exports, and connected passenger records to a separately authorised vehicle history view.
2. **Receive and store:** added quick-action modals, site point and delivery arrangements, then found and fixed the gap between receiving keys and storing them. Guarded actual giver/current holder, stale custody, explicit placement, secure destination, role/site access, original plans and retry integrity.
3. **Plan and verify:** reused the existing full calendar, proved new allocations retain their Fleet identity, corrected calendar scrolling/colour styling, checked desktop layouts and source access, and rendered the exported PDFs. Visual/text inspection caught a Windows UTF-8 decoding problem and an incorrect request-status label in full-record PDFs; both were fixed and verified.

## How to review

- Returns: search **RC-310**, **KEY-014**, **OFV-014**, **Ben Carter**, or **office**. Open Casey’s row, review Keys & site, search History for **dispute**, then use Open full record. Export the RC-310 result and confirm only Casey is included.
- Journey: open Charlie, search the banner for **departed**, export the matching event, then open vehicle trip history. Search **VT-902** for the return leg. Switch to Ava to see separate vehicle-source access enforced.
- Calendar: Planner → Transport calendar. Try Month, Week, Day and Agenda, a vehicle filter, a date change, right-click and the other Fleet activity switch. Allocate Elliot in the other planner view, then inspect the same booking in the calendar.
- Key plan: open Taylor’s booking BK-210 as Mia. Plan key collection, choose delivery, name Ben and record the meeting arrangement. Saving the plan does not claim that the keys have moved.
- Actual custody: open Morgan’s booking BK-212 as Nia. Complete the vehicle check, collect from Ben with physical confirmations, depart, then return to Ben. Open Returns as Ben, choose **Keys to store**, and confirm actual storage. Original receipt and storage event remain distinct. The passenger still needs separate accountability/completion.

The View as and Preview scenarios controls are design tools. Source roles, time, readiness and records are synthetic. They are not production permission controls.

## Source contract audit and remaining implementation gaps

The following were inspected in the isolated source checkout, without modifying application code:

- `FleetKeyLog` and `FleetAssets/KeyController`: existing key identity, asset/booking/Site, holder/transfer user and a string location are available. Current writes use Fleet-manager authority and locking. A stable Site/room/storage-point relation is not established by those fields. V4's named site points are proposed mappings, not a new source schema or a claim of existing integration.
- `VehicleBookingController`: current key receive logic labels a return as `key_safe`. V4 deliberately demonstrates a separate receiving-worker receipt and physical storage observation. The source command contract and scoped staff authority must be explicitly designed and approved before implementing that distinction. Equipment custody remains a separate item concern.
- `SiteRoom`: room identity exists, but the exact secure storage point and its configuration/maintenance owner still require an approved mapping. The unconfirmed Kōwhai point fails closed. The preview contains only confirmed Aurora fixtures and does not invent a durable storage catalogue or safe access policy.
- `VehicleTripHistoryController` and `VehicleTripHistoryService`: vehicle trip history already owns searching, filtering, permission/privacy enforcement and PDF/Excel export. Booking attribution exists, but an authoritative stable passenger-journey-to-vehicle-trip link was not verified. V4 uses explicit synthetic cross-links; production must not infer them silently from nearby times or expose personal/restricted movement.
- Vehicle profile Trips/Calendar routing and existing shared calendar components were inspected. Actual Fleet/profile navigation remains a bounded source handoff here; this preview does not open or change production pages. The future adapter must preserve booking/vehicle/date context and respect separate source permissions.

Production persistence, source concurrency, audit logging, export limits, real availability/readiness, staff permissions, file handling, notification delivery and complete source deep-link adapters remain implementation work after exact design approval. No duplicate booking engine, tracker history, key catalogue, approval store or tenant boundary is authorised. One organisation, approved sites, roles and source ownership remain the application boundary.

## Verification and limits

- 62 passing browser assertions: 17 search/history/source tests, 10 custody-flow tests, 22 calendar/layout/access tests and 13 final interaction/source/network tests.
- 59 passing synthetic transition assertions: the 34 prior domain cases and 25 new custody cases.
- 12 passing PDF checks across five actual downloads, including filtered-record exclusion, source-leg filtering, original receipt plus storage history and Unicode. Six rendered pages were visually inspected.
- 3 local renderer/server contract checks. PDF POST requires the exact preview origin; malformed reports are rejected; static preview remains accessible.
- Zero preview or imported-source TypeScript errors. No runtime errors were observed. Network evidence contains local static GETs and local synthetic PDF POSTs only.
- Desktop widths 1024, 1280, 1440 and 1920 checked. No page-wide horizontal overflow; quick views fit within the viewport. Actual browser zoom and mobile layouts are not claimed.
- 232 prior frozen files verified unchanged: v1 62, audit-v1 21, v2 92, v3 57. Canonical-source hash comparisons are retained. Preview tests do not establish production readiness, backend security or passing repository CI.

The PDF checks initially exposed Windows decoding corruption. The final PDFs use explicit UTF-8 decoding. One verification selector still expected the old generic action name and was updated to the observed accessible name. Evidence logs contain the final successful runs. `review-*` images are iteration snapshots; use `final-*` for the final layout. One-off `integrate`/`refine` scripts record authoring steps and must not be rerun against the final source.

## Provenance and review boundary

Same pinned Astra/xhigh chat and isolated checkout, HEAD `4ea64c547ed85a5b7504e59599db351f6eba7deb`. Effective model/effort verified from the session metadata before writing and again in `context-manifest.json`. Canonical Revision10 SHA-256 remains `c4837ab675f9dffdb6a8597636f49d5761da114e6c155dc08e6bb8a209d63fd0`.

Read-only Main rules revision32/register101 and related dependency/amendment/navigation updates introduce People Locations design. They leave this Transport design-only gate and the eight Fleet & Assets destinations unchanged. Published source context remains `f7d517359da6ffdf90de2f259111fe5e8a1133f2`, as reported by Main; no new fetch/rebase/publication was performed. Existing hosted CI failures are not reclassified by this preview.

All writes are in this v4 evidence/preview directory. No app/backend/schema/shared component, protected guide, Main file or sibling workspace was changed. No new agent, task, coordination message, production mutation, upload, notification, push, merge or deployment occurred. The PKG-05 Designer brief still says to stop at exact mockup approval; this packet is the reviewable result at that boundary.

Runtime identity, file-set hash and final manifest are recorded in `FREEZE.txt`, `verification.json` and `artifact-manifest.json`. Further feedback should produce a new version rather than rewriting this candidate.
