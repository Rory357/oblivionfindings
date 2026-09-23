# PKG-02B build checkpoint

## 23 September 2026 (evening): every v13 view rebuilt to the mockup

Stephan rejected the slice (a) delivery because it did not match the approved v13 mockup, and asked for every view to be rebuilt section for section, with real backends and no stubbed buttons, then re-reviewed for gaps. That is done for all 19 views (Overview ×4, Service & compliance ×5, Checks ×2, Maintenance ×2, Map ×4, Trip history, Calendar). The slice plan below is superseded.

- The mockup's stylesheets are ported by `scripts/port-pkg02b-mockup-css.cjs` into `vehicle-workspace/mockup-port.css` (scoped to `.vehicle-studio`, tokens only), so the build uses the mockup's own structure.
- New backends and migrations: calendar & bookings (`000200`), Finance links and review requests (`000300`), trip history (`000400`), obligation reminders (`000500`), tracker distance feed (`000600`), geofence assignments (`000700`), versioned checks (`000800`), plus Driving insights and Alerts & Control Room.
- Side-by-side re-audit: `MOCKUP-FIDELITY-AUDIT.md`. Summary for Main, decisions to review and test results: `SLICE-A-MAIN-SUMMARY.md`.
- Not merged to `main` and not pushed.

## 23 September 2026: build taken over by Claude (Claude Code), slice (a) delivered

Stephan paused the ChatGPT/Codex Designer and approved Claude to finish the build in four slices, each tested and merged to `main` on its own: (a) I1 readiness, Overview, and Service & compliance with documents and reminders; (b) Checks & inspections and Maintenance; (c) Calendar & bookings; (d) Map, trips, telemetry and exports. The Codex worktree and Main's uncommitted records were not touched. Codex's paused work was committed unchanged as `e9432132d`.

- `5891b62e3`: I1 finished (readiness B01/B02 plus the RUC lower bound, odometer, compliance validation, booking rules, the legacy evidence guard) and the workspace records backend (migration `2026_09_23_000100`, private documents, reminders, schedules, catalogue, presenter).
- Slice (a) closing commit: the rebuilt vehicle page (`pages/fleet-assets/vehicles/show.tsx` plus `components/fleet-assets/vehicle-workspace/`), JSON-aware versioned vehicle update, prefixed document-reminder errors, a workspace rollback test and model tests.
- Full summary for Main, including the decisions to review: `SLICE-A-MAIN-SUMMARY.md`.
- Next: slice (b), Checks & inspections and Maintenance, against v13 and the I2/I3 notes in this folder.

The historical Codex checkpoint follows unchanged for reference.

---

## 22 September 2026: Codex checkpoint (historical)

22 September 2026. **PAUSED at Stephan's explicit request to conserve tokens. Not complete, not integration-approved.** Same Designer task 01a0c2bb-fcff-7cb1-8bab-882d84477c6c, worktree 5b0a, branch codex/pkg-02b-vehicle-profile-design. Integration baseline 5307692ec59be84f3503c06354419b7da95be805; latest application checkpoint 71dfa1a90.

## Pause checkpoint — resume here

The user explicitly requested a pause during root's frontend increment. Backend worker was interrupted; do not restart it or continue implementation until the user resumes. No Main update or integration/publication was performed.

- Backend checkpoint `71dfa1a90` contains 20 I1 backend/migration/route files. Isolation preflight and PHP lint passed; VehiclePageContractTest passed 3 tests/26 assertions. I1 focused tests and remaining regressions are still incomplete.
- Worker explicitly returned application/test writer custody to root after that commit. Root owns custody at pause. Worker subsequently performed read-only verification only. BookingSitePrivacy suite has 4 compatibility failures among 9 tests/129 assertions: old overlap expectation versus retained pending requests, missing canonical compliance/current driver prerequisites, and a downstream stale-lifecycle expectation. Preserve failures and update explicit fixtures/approved expectations, not safety guards.
- Root's uncommitted frontend foundations are saved under `resources/js/components/fleet-assets/vehicle-workspace/`: typed source DTOs, List/Cards collection, searchable selector, recoverable idempotent JSON command, three-step compliance evidence editor with direct dates entry, compliance panel and mileage panel. **They are not yet wired into the production vehicle page or browser-verified.** No prototype store or synthetic records are imported.
- `tsconfig.pkg02b.json` scoped TypeScript passed after the final frontend edits. Scoped ESLint passed (`i1-ui-lint-final.log`). Four command-recovery tests passed (`i1-ui-command-tests.log`) using `vitest.pkg02b.config.mjs`, native config loader and a worktree-local cache. Earlier sandbox startup failure and the resolved single lint warning are retained. Whole-app type check still has the 63 baseline missing generated route modules; route generation and full recheck remain required.
- Next backend completion, after explicit custody return, must finish focused I1 tests, the scoped paginated compliance/odometer read DTOs specified in the frontend types, and a manual Auckland wall-time adapter reusing MaintenanceLocalTime::toUtc with optional DST offset. Root requested preparation only; no grant was made for these remaining writes.
- `I2A-CONTRACT.md` is prepared for private/versioned documents, profile photo and persistent catalogues. It is not a custody grant. Other I2/I3/I4 preparation and browser plan remain in the implementation folder. Full workspace scope and final consolidated Main review still apply.

On resume: verify actual root Astra/xhigh continuation and HEAD, inspect worker's interrupted status/results, preserve the uncommitted files, and continue from this checkpoint. Do not restart the design or send routine Main pings.

## Authority and invariants

Stephan approved exact v13 candidate 56781fb5239a1b5a65fe69be015f782e15f4b665ea7a3c203bb00ca355090181 and explicitly said to start the build without continuous Main/build back-and-forth. Main build release revision 1, Designer handoff revision 6 and programme register revision 90 were accessible and inspected. Their durable scoped copies and hashes are in main-context-20260922/ and context-manifest.json. Latest build release supersedes historical design-only text. Source copies are reference snapshots, not competing programme records.

Actual current Designer turn verified before writes: 01a0c7b1-dfe4-7f23-b9da-ead60b63e045, 2026-09-22T05:58:38.374Z, gpt-6-astra/xhigh, including collaboration configuration. Reverify every continuation before design writes. Main owns final pre-integration review. Do not send intermediate success/status/model pings. One consolidated final packet, or a genuine material boundary conflict, only.

One organisation; role/site/direct-object/privacy boundaries. Keep legacy storage compatibility without propagating new tenant semantics. Protected guides, frozen v1–v13, Main's dirty checkout, canonical master and Main-owned programme files are read-only. No operational database, live tracker/provider action, invented policy, paid external service or private-coordinate disclosure. Synthetic fixtures stay in isolated test/browser storage. Missing operating configuration blocks affected activation, not unrelated build work.

## Full approved scope — no tab may silently disappear

1. Profile identity/photo, readiness with reasons/actions, vehicle details, private versioned documents/renewal sets and canonical Finance references.
2. Service/compliance: source-owned applicability/outcome/date/RUC ranges, compatible day and calendar-month service schedules, service completion/history, reminders through shared Tasks/Calendar, retained manual odometer and tracker-estimate reconciliation.
3. Universal checklist library: typed ordered questions, required evidence, assignment/publication/retirement/versioning; original submitted snapshots; canonical Maintenance create/link/retry and independent release rules.
4. Maintenance current/history/detail/evidence/appointment handoff, existing canonical lifecycle and return context. Repair, release, custody, Finance and alert closure remain distinct.
5. Shared five-view calendar; booking request with explicit approval route and reason/evidence; conflicts/current driver authority/custody/return; reminders/source actions and right-click keyboard alternatives.
6. Greyscale map, motion/reported state, shared AssetGeofence selection/assignment/history with monitoring authority separate. No competing registry or unrelated Client/Site redesign.
7. GV500CG capability-gated telemetry, actual trips/per-trip and driver insights, versioned scoring/coverage/disputes, source-correlated Control Room receipts/triage/create-or-link Maintenance. Unknown road limits stay unknown; provider and policy prerequisites remain explicit.
8. Branded real PDF/Excel trip exports over actual date ranges with map/source/coverage and Unicode fonts, scoped private downloads and pagination.

All applicable approved v13 premium uploads, searchable persistent Add new catalogues, date/time/wizard/recovery/focus rules, List/Cards and specialised map/calendar/trip layouts must be wired to real persisted server contracts. No imports of prototype stores, fixed clock, DEMO IDs, synthetic policy or browser catalogue storage as production persistence.

## Current custody and progress

- Setup and baseline committed `0744e5ffd`; reviewed I1 contract committed `622836e0d`. Root granted sole I1 application-writer custody to `/root/vehicle_backend` after that commit. Root is now read-only on application source while retaining its own evidence/source-mapping documentation. Worker must commit exact I1 scope and explicitly relinquish custody before root frontend writes.
- One backend-only worker `/root/vehicle_backend` launched with gpt-5.6-sol/high. Actual rollout source binds the worker to this root: session `01a0c7b5-813e-70e0-bcb0-2b404b315942`, turn `01a0c7b5-81d2-70f1-acb3-61cf98a8d0de`, 2026-09-22T06:02:38.094Z, model `gpt-5.6-sol`, effort `high`, including collaboration settings. **I1 custody active** under `I1-CONTRACT.md`. No frontend/guide/frozen edits or further workers.
- Root same-turn settings reverified at 2026-09-22T06:10:43.681Z: `gpt-6-astra/xhigh`, including collaboration settings.
- Root continuation settings reverified at 2026-09-22T06:41:42.223Z: same turn, `gpt-6-astra/xhigh`, including collaboration settings. Root has made documentation-only changes while I1 custody remains with the worker. `I2A-CONTRACT.md` now defines the next bounded private-document/catalogue increment; it is preparation, not an application-writer grant.
- Read-only I1 integration observations sent to the initial implementer before stable handoff: current-time/overdue custody and driver checks; non-vehicle Maintenance release compatibility; manual versus trusted-source odometer provenance and future observations. These are initial implementation requirements, not claimed final defects or reset correction counts. Root requested a committed implementation checkpoint and explicit custody return so frontend foundations can proceed while read-only backend verification runs.
- Local locked offline Composer install completed with scripts/plugins disabled. No shared dependency or lock-file modification. No .env/.env.testing/cached config; node_modules is a read-only junction to Main.
- phpunit.pkg02b.xml forces package database base oblivion_findings_pkg02b_5b0a_test; inherited process tokens/URLs must be absent. Existing TestCase appends actual PID and only prunes dead numeric siblings of that exact base. Preflight verification must pass before any Laravel bootstrap/tests/DDL. Browser storage will use a different package-specific schema.
- Preflight passed (`isolation-proof.json`). Unmodified application baseline passed: `VehiclePageContractTest` and `VehicleBookingSitePrivacyTest`, 12 tests / 179 assertions, PHP 8.4.16 / PHPUnit 12.5.23, 3:43.610, evidence `baseline-vehicle-booking.log`. No application source changes at this checkpoint.
- Root frontend baseline `tsc --noEmit --pretty false` exited 2 with 63 TS2307 missing-module errors, all missing generated route modules and none in Fleet page/component paths (`baseline-types.log`). No TypeScript writes occurred. This is checkout generation/setup evidence, not a claimed code defect or a waived final type check. Generate routes under isolated configuration at root's next application custody boundary, then recheck.

## Planned sequential increments

I1: canonical compliance/observed mileage/readiness access and transition gates, including B01/B02 regressions. Worker proposes exact additive contract; Designer records compatibility/migration/rollback before writer release.
I2: service/check/template/document/reminder persistence and source ownership, durable file security/recovery and canonical catalogue creation.
I3: booking/custody/calendar time, privacy, concurrency, idempotency and shared Tasks/SiteCalendar integration.
I4: existing geofence/device/trip/Control Room/Finance adapters, governed insights and exports.
I5: exact Astra-owned frontend implementation and wiring against those contracts; may interleave at explicit serial custody handoffs.
I6: combined scoped QA, actual application/browser identity, desktop widths/theme/keyboard/focus/resizing/genuine zoom, native transfer and report rendering, migrations/rollback, correction ledger and final Main packet.

These are internal work increments, not repeated approval gates. Initial implementation is distinct from at most two corrections per underlying worker issue; preserve issue identity and count. Commits and custody transfers must be explicit. Do not call a partial shell complete.

## Required QA and open activation prerequisites

Server tests: permission/site/direct-object/privacy, immutable evidence/version/date/month/timezone, unknown/failed/expired/RUC across every use decision, current checkout reading, both approval paths, duplicate retries/conflicts/concurrent transitions/current-authority withdrawal, checklist-to-Maintenance-to-retest-to-independent-release, reminder identity, safe upload/replacement/private download, durable Control Room/Finance handoff and driver attribution/coverage.

Frontend: render actual implemented source against v13; verify all tabs and workflows at approved desktop widths, keyboard, focus, failure/draft recovery, List/Cards, maps/calendar/trips, and genuine zoom. No viewport substitution for zoom. Preserve baseline failures and do not poll whole CI. Native file-picker/Excel rendering and PDF Unicode limitation remain verification work, not assumed green.

Operating rules/grants, actual template content, compliance applicability/accepted mileage policy, Coordinator/backup mappings, telemetry/scoring thresholds, precise hardware/firmware/protocol and approved road-limit/private geocoder configuration remain explicit prerequisites to affected live activation. Earlier Maintenance/Client Location acceptance remains open.
