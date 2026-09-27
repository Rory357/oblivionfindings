# PKG-05 Transport implementation

## Scope and approval

The user approved the frozen v6 Transport design and explicitly requested implementation. This change implements the real Laravel/Inertia workspace in `codex/pkg-05-transport-workspace`. The initial base was `f7d517359da6ffdf90de2f259111fe5e8a1133f2`; the current comparison base is the actual merged Main parent **`ba5bff2e8b6c22796369443f1cdac918039950dd`**, incorporated by `a285d4fe7dd1139e02a9bfb48c227946b754e199`. The user subsequently authorised local-main and GitHub-main publication after Main's substantive review. No deployment or operational data changes are authorised by this packet.

The six views are Overview, Requests & approvals, Planner, Calendar, Journeys, and Returns & handovers. All reuse PageHeader/PageHeaderRail and TierTwoTabs. Each heading sits directly below the hero. The Overview derives stage and planned-movement graphs from scoped records, with keyboard-accessible values and drilldowns. Search, quick views, full records, action menus, history, source evidence, and scoped PDF exports use persisted application records.

The visual planner guides vehicle/time selection, driver/escort and Site key arrangements, and a final review. Changes retain the same Fleet booking identity. Calendar reuses Site Calendar's Month, Week, Day, Agenda and Timeline components with Fleet's booking/unavailability feed. It is a dedicated Transport view of the shared source calendar.

## Source ownership

- ClientTransportBooking owns the passenger request, assessment and explicit link to one FleetVehicleBooking.
- FleetVehicleBooking retains allocation, overlap, readiness, independent approval, checkout and vehicle return rules.
- FleetResidentTransport retains actual passenger journeys and clinical-source completion checks. Arrival, passenger accounting and completion remain distinct observations.
- FleetKeyLog records actual receipt and later placement at a SiteRoom. The planned collection/return locations do not prove physical custody.
- Required-item receipts and immutable request events preserve the actor, save identity and version. Shift handover remains optional and retains named incoming-worker acceptance/dispute.
- Client and vehicle booking pages link to the authorised Transport record. The vehicle trip-history link opens the vehicle's existing trip source; no GPS trip is inferred from overlapping times.

Legacy unlinked journeys remain discoverable through All journey records. Legacy free-text bookings are not automatically declared allocated, and historical completion is not invented as an actual linked journey. No automatic backfill is included.

## Access and mutation boundaries

This remains a single-organisation application. Existing roles, permitted Sites, current Client ownership, Fleet booking scope and journey scope govern lists, counts, options, records, mutations and exports. Foreign and missing request IDs are concealed before action payload validation. No new frontline custody permission or clinical authority is introduced.

Transport commands use current authorization, immutable command receipts, payload fingerprints and expected versions. The canonical booking transaction owns allocation/link changes, and source actions recheck the assessed plan before approval/checkout. Staff assignments are serialized and checked for the whole window; equipment, seating, accessibility and Site rooms are explicit. A passenger journey completing does not return a vehicle or its keys.

## Migration and release

`2026_09_27_120000_add_transport_workspace_contracts.php` adds nullable links, assessment fields, versioning, provenance and the request-event table. Existing records are retained. Deploy the migration with the application code using the normal repository release procedure; this task only runs it in disposable verification storage.

The migration deliberately refuses destructive rollback. After operational use, an application rollback must retain added columns and evidence. Removing the data contract requires a separately reviewed preservation/migration plan. No new permissions are silently granted by this migration.

Production still depends on the organisation's real vehicle readiness configuration, current staff eligibility, Site rooms and source permissions. Unknown readiness remains unresolved. This implementation does not invent missing eligibility policy, GPS mappings or medication actions.

## Verification record

- Frontend model/navigation/calendar: 38 tests passed, including Auckland clock-change duration and ambiguous/gap handling in UTC, Auckland and Los Angeles browser timezones.
- Focused Transport lint: no diagnostics.
- TypeScript: Transport pages and imported dependencies passed; expanded checks including the changed vehicle calendar and handover page passed. No whole-repository TypeScript claim.
- Production frontend build: passed; existing bundle-size warnings retained.
- Frozen v6: all 105 manifest files verified unchanged. Manifest SHA256 `8bc1e7f92e9aba1df4911313f091943100a584ba1e92c3dfdd6cfa54606c21c9`.
- Backend: 60 source/workspace tests passed (892 assertions), followed by two focused browser-discovered regressions (161 and 170 assertions), and three Main-review regressions (71, 9 and 14 assertions). Total: 65 distinct tests and 1,317 assertions across these staged runs. This covers scoped views/options/exports, stale and repeated saves, atomic allocation, driver ownership, journey completion, independent custody observations, linked handover replay, existing Fleet-page handoff, unrelated-field preservation through move/Undo, current key requirements and unlinked-booking compatibility. The two concurrent subprocess cases were excluded; this is not a concurrency certification or a claim that all tests were rerun after the final corrections.
- Real browser: assessment to planner, saved Fleet allocation, missing-item receipt, Site-room key storage and passenger completion passed through the actual UI with synthetic records. Search and empty results, current-record context menus, graph drilldowns, quick/full records and the shared calendar were exercised. See `BROWSER-VERIFICATION.md` for the final layout and artifact evidence.
- Final layout: all six tabs passed at 1280, 1366, 1440 and 1920 pixels (24 combinations), with canonical heading/second-row placement, no document overflow and no browser errors or console warnings.
- PDF: record and overview exports were downloaded through the application, rendered with Poppler and visually inspected. Text, tables, page breaks and source-derived counts were checked. The overview summary and detailed records occupy separate pages.

The verification databases use task-specific disposable names. The initial schema import exceeded the repository's five-minute import limit before assertions ran. The merge retains Main's canonical `MYSQL_TEST_SCHEMA_TIMEOUT` handling and exact `tests/TestCase.php` blob (`38496aeb7b6621f62d820791717f58bb054beb7a`); the earlier Transport-specific timeout variant was discarded. No application environment file or operational database was copied or used.

## Audit passes

1. Source and access audit: preserved canonical ownership, explicit links and scoped options/exports; removed requester-as-driver assumptions.
2. Workflow audit: addressed stale/repeated saves, declined-plan edits, independent item/storage queues, date changes, full-window staff conflicts and source action menus.
3. Browser and export audit: corrected All-sites validation, direct calendar period boundaries, narrow planner controls, quick-view spacing and PDF pagination. Rechecked the real workflows and final desktop layout rather than relying on the frozen preview.

The review app runs from this exact checkout at `http://127.0.0.1:8765/fleet-assets/transports/overview?from=2026-10-01&to=2026-10-01&site=all`, with a real login and a guarded disposable database. Its sample records are for 1 October 2026. The production migration has not been applied. The frozen v6 preview on port 4400 remains unchanged.

This packet is for the repository's normal technical review and integration gates. Build/test evidence is not a deployment or full-system certification.

## Main technical review corrections

Main requested changes on `a285d4fe7`; that candidate was not approved for integration. The same owner corrected T05-01 (linked Fleet source actions), T05-02 (preserve canonical fields during rescheduling/planning), and T05-03 (actual duration across Auckland daylight-saving changes). See `INTEGRATION-AND-CORRECTIONS.md` for the consolidated correction and final browser record. Frozen preview bytes and the Main-owned guides remain unchanged.
