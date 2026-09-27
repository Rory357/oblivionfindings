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
