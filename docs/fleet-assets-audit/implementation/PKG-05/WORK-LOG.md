# PKG-05 implementation work log

## Initial implementation

- Exact v6 approval and implementation direction received in this task.
- Fetched origin/main and created isolated `codex/pkg-05-transport-workspace` at f7d517359.
- Preserved all frozen preview artifacts and existing preview servers.
- Verified canonical demand (ClientTransportBooking), actual journey (FleetResidentTransport), reservation (FleetVehicleBooking), key log and shift handover ownership.
- Confirmed additive gaps: request→booking linkage; booking driver versus requester in journey scope/create; distinct arrival/accountability observations; actual item receipt versus key-safe placement.
- Implemented the six persisted Transport views, scoped request lifecycle, canonical Fleet allocation, actual journey observations, key storage and item receipts, optional linked handovers, source navigation, private evidence and PDF exports.
- Reused the approved header/navigation components, shared calendar views and source permission/readiness rules.
- Frontend model/navigation tests: 30 passed. Focused lint and expanded Transport/vehicle-calendar/handover TypeScript checks passed. Production builds passed, with the existing bundle-size warning.
- Verified all 105 frozen v6 manifest entries unchanged.
- The full isolated schema bootstrap exceeded the original five-minute import limit. Added an explicit test-only timeout override while retaining the existing default and disposable database isolation.
- The first actual backend assertion exposed an unset initial event version. Request creation now writes version 1 explicitly. A guarded reusable disposable schema then ran 60 backend tests successfully (892 assertions), with browser fixtures seeded only afterward.
- The browser audit found an All-sites validation expression error and a direct calendar-link range mismatch. Focused regressions passed for all six tabs and scoped exports (161 assertions), and all five calendar periods including Sunday and month boundaries (170 assertions). Total: 62 distinct backend tests, 1,223 assertions. Concurrent subprocess cases were excluded from the shared-schema run.
- Real browser checks saved an assessment, allocated a vehicle and separate driver, received required items, stored keys at a Site room, and completed a passenger journey. Receipts and storage cleared return work independently of passenger completion. Current records, menus, searches, graph drilldowns, shared calendar views and source links were inspected.
- Visual corrections include compact hero date filters, full-width meters, chart initial dimensions, quick-view spacing, rounded wizard progress and adaptive planner date/time fields. PDF exports were downloaded through the real UI, rendered and inspected; the overview summary and source records use separate pages.
- Final desktop layout and same-build confirmation are recorded in the review packet. No operational data, deployment or publication performed.

This is initial implementation, not a correction attempt. No new worker or issue-history reset.

## First technical correction cycle — 27 September 2026

- The user authorised Main coordination and local/GitHub-main publication, then reported missing calendar right-click/drag and noncanonical modals. Publication remains subject to Main's existing integration gate.
- Added shared blank-slot and entry context menus, keyboard alternatives, permitted Month dragging and Week/Day movement/resizing with a reviewed save and reviewed Undo. Added the canonical calendar date anchor and removed duplicate calendar range controls.
- Rebuilt structured quick views with the shared wizard shell and named sections; single-section notes/information use simple dialogs. Added shared review/success components, free section navigation and explicit dirty-draft retention/discard.
- Committed the application and byte-preserved frozen artifacts at `f1330fb0d`, then merged the actual Main parent `ba5bff2e8` in `a285d4fe7`. The sole conflict retained Main's exact `tests/TestCase.php`. All 105 v6 manifest entries match committed Git blob bytes.
- Main's substantive review of `a285d4fe7` requested changes T05-01 through T05-03. The same owner corrected them; no new worker, guide edit or review-history reset.
- T05-01: Fleet source pages now hand linked bookings to the authorised current Transport record and hide incompatible old forms. Server checks preserve current UUID/version/key requirements; unlinked cancellation remains compatible.
- T05-02: a bounded `reschedule_only` path copies untouched values from the locked canonical booking and request. Planner updates carry existing canonical fields and notes. Move, repeated-save, stale-save and reviewed-Undo regression checks preserve distinct source values.
- T05-03: moves use canonical elapsed duration, then explicitly resolve Auckland wall times and offsets. Ambiguous/nonexistent times cannot silently change duration; the wizard offers offset selection for repeated hours. Shared time-grid callbacks identify move versus resize.
- Final focused frontend suite: 38 tests passed. TypeScript includes the changed Fleet booking source page; focused lint and PHP formatting passed. Three additional backend regressions passed with 71, 9 and 14 assertions. Initial fixture failures were corrected by removing a nonexistent Fleet booking `client_id`; Client ownership remains on the linked Transport request.
- Final build and browser evidence are consolidated in `INTEGRATION-AND-CORRECTIONS.md`. No operational database or deployment is part of this work.
