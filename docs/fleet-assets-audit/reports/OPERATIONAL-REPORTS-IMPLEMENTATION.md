# Operational report builder — implementation handoff

Implemented on branch codex/fleet-personal-reports from 926b4981b0289da08a20baca0995117fb53e413e. The main checkout and the frozen v1/v2 designs were not edited. No production migration, deployment, commit, push, external message or tracker collection change was performed.

## Delivered

The application now has a shared six-panel report studio for Fleet, client trackers, staff safety trackers and a worker's own safety records. It includes 22 authorised sources and 21 starter templates; drag-and-keyboard ordered columns; site/resource selection; grouped AND/OR filters; conditional measures; distinct counts; arithmetic formulas; percentiles; two-dimension pivots with recomputed margins; tables and charts; previous-period comparison; location precision controls; definition import/export; draft recovery and undo/redo; and explicit unknown values.

Saved reports support folders, favourites, immutable versions, conflict detection, restore, duplicate, archive/undo and definition sharing. A shared definition never transfers the author's record access. Generated results remain private to the requester, are encrypted at rest, expire within 24 hours or earlier when source retention requires it, and are reauthorised when read or exported. Daily, weekly and monthly subscriptions create private runs with generic in-app notifications. They start paused and pause again if current access fails.

CSV, JSON, Excel and PDF exports are implemented. CSV can contain grouped measures or source rows. Excel includes Scope, Summary and Source rows sheets. PDF includes organisation branding, measures, rows and provenance. Exported numeric negatives remain numbers; formula-like untrusted strings are neutralised. Personal exports require the existing independent export permission.

## Entry points

- Fleet: /fleet-assets/reports/builder (linked from existing Fleet reports).
- Client: /operations/people-location-reports/client (linked from the client location workspace).
- Staff: /operations/people-location-reports/staff (linked from lone-worker safety).
- Worker self-service: /my-day/safety-reports (linked from My Day).
- Local review preview: http://127.0.0.1:8973/.

The preview bundles the actual reporting component with a clearly labelled synthetic API and layout adapter. Its data, sharing and schedules are demonstrations; it never accesses live personal records. Preview downloads support CSV/JSON; PHP integration tests exercise the real application PDF/Excel exporters. The synthetic adapter is confined to docs/fleet-assets-audit/previews/PKG-09B/implementation and is not imported by the application.

## Source coverage

Fleet sources cover business journeys, vehicle bookings, transport demand, resources, maintenance, fuel purchases, obligations, custody, stocktakes, recorded downtime, finance bills and posted resource costs. Personal sources cover client observations, device signals, confirmed zone breaches, attributable alerts and current authority; staff sessions, attributable alerts, session-bound observations and tracker readiness; and the worker's own safety sessions.

Finance requires both finance permissions and canonical asset/site association. Posted costs, invoices, payments and fuel observations retain their separate meanings. Cost per kilometre uses only costs matched to positive known business distance. Overlapping downtime intervals are unioned rather than double counted. Non-vehicle resources retain the existing asset-access policy. Private or consent-blocked telemetry cannot become an innocuous-looking resource contact status.

## Audit corrections

1. Time-series charts use chronological calendar dates and explicit missing gaps, independently of table ranking and row limits.
2. Server validation rejects malformed imported definitions before replacing a draft, including invalid decimal settings. Formatting is defensive and the builder has an error boundary.
3. CSV/Excel preserve typed negative numbers without permitting formula injection through text.
4. Report telemetry is read in bounded pages using a source watermark instead of silently reusing the map's 500-point cap. More than 100,000 matching source records fails explicitly.
5. Client map/history/export/report paths share the same consent, assignment, collection and retention window. Current authority is checked again before results or downloads leave the server.

## Authority and evidence boundaries

This remains one organisation across approved sites. It introduces no tenant boundary or tenant switching. Existing permissions, approved accounts/sites, canonical ownership and direct-object denial remain authoritative.

Fleet access grants no client/staff report access. Client sources require current location permission and current assignment/consent authority. Staff location history additionally requires the new assets.telemetry.history permission, explicitly granted to an appropriate role. The migration creates this permission; broad admin seeding does not automatically grant it. Staff observations must fit both the authorised safety session and the corresponding device assignment/collection/retention window. Ambiguous device reuse and conflicting assignments are excluded. Permission withdrawal, consent withdrawal and changed assignment graphs invalidate previously generated results.

Client zone reports use canonical versioned breach evidence. They do not infer complete entry/exit/dwell history from intermittent GPS. Worker safety sessions are not attendance, payroll or performance evidence. Location precision is applied before filters and aggregation. Unknown, withheld and unverified records are not substituted with zero or inferred presence. Personal privacy is enforced in the backend, not merely by hiding UI controls.

## Limits and remaining prerequisites

Fleet windows are limited to 366 days; personal windows to 31 days. Definitions support up to 30 columns, 25 rules across at most 8 filter groups, 8 measures and 2 grouping dimensions. Computation rejects excessive source/group cardinality; charts are bounded; the browser previews at most 500 result rows. Full permitted data remains available in exports. PDF is limited to 2,000 source rows and 10 columns; larger extracts use CSV/Excel/JSON.

The audit's conditional future ideas are not claimed as delivered: combined multi-section monthly packs, natural-language report drafting and fuel/EV/battery forecasting. They need further source contracts and acceptance examples. Current telemetry does not establish a defensible continuous utilisation denominator, presence/dwell duration, battery hours remaining or staff attendance. Those values are intentionally not invented. Demand reports do not expose uncategorised free-text care notes as a coded cancellation reason.

## Verification

- Full targeted PHP acceptance: 16 tests passed, 214 assertions (new reporting suite plus existing personal consent-withdrawal test).
- Subsequent final scope safeguards: 2 tests passed, 24 assertions (asset policy/contact withholding and source query/downtime checks).
- Real encrypted private report generation, CSV, Excel and PDF, other-user denial, withdrawn consent, retention expiry, session limits, stale assignment invalidation, version conflict, safe sharing and schedule reauthorisation were exercised against process-isolated MySQL test databases.
- 601 eligible observations were exported completely while pre-consent observations were excluded; the map cap remains separate. A late authority change released no export bytes.
- Production Vite build passed. TypeScript and targeted ESLint passed. PHP syntax and targeted Pint passed.
- Browser review at 1280 and 1366 widths checked templates, selectors, pivots, conditions, charts, invalid imports, draft preservation, saved versions, stale-result export prevention, calendar fit, keyboard tabs and CSV downloads. No page errors or horizontal overflow were observed.
- Automated axe checks found zero violations for the settled light builder, dark builder and results views. This is scoped automated evidence, not complete accessibility certification.
- Synthetic PDF was rendered and visually reviewed; Excel numeric XML was checked. The final export-label correction passed an additional integration check (1 test, 20 assertions). A subsequent CSS-only spacing adjustment was rendered separately through the actual PDF template; its one-page synthetic report was visually checked including the final purpose row.
- Four pre-existing CanonicalIntegrationEventHistoryTest HTTP 403 failures were reproduced with the untouched baseline versions of the three affected services. Access rules were not weakened to make those older fixtures pass. These remain a baseline test-fixture issue to reconcile separately.

Evidence is stored in docs/fleet-assets-audit/evidence/PKG-09B/implementation. Detailed raw logs and additional screenshots remain in the task's reports-implementation QA directory. The final manifest records hashes, tested source identity and preservation checks.

## Mockup fidelity pass — 28 September 2026

The v2 library and side-by-side builder composition have been carried into the actual reporting component. This includes the library introduction, four focused Fleet entry points, header summaries, searchable starter templates, six compact editor panels beside the report, and Save, History and Export dialogs. At a 1366 px viewport the editor is 294 px wide and the preview is 788 px wide, aligned at the same top edge. Narrow screens stack the editor above the report.

The builder now supports grouped AND/OR conditions, drag and keyboard column ordering, day/week/month date buckets, source-row sorting, distinct counts, threshold indicators with text cues, and correctly reaggregated row/column pivot totals. Live preview waits for a stated purpose, debounces edits and can be paused. Changed definitions cannot download stale results. Library date/site scope carries into a new report.

The match is compositional, not pixel-identical. Purpose, private-run state, access and source-evidence controls remain intentional additions. Header summaries remain unknown until a permitted result is available. The local preview uses comparable navigation chrome and synthetic records; the production component keeps the existing application shell. The v1 and v2 mockups remain frozen.

Final checks for this pass:

- 20 PHP reporting and consent tests passed, 265 assertions, against a process-isolated MySQL test database. New aggregation/filter contracts and existing client consent, staff session, retention, export and ownership boundaries passed. Personal fixtures use their observation dates, and shared-definition assertions tolerate the current Inertia asset version.
- Final production Vite build passed; the built EntityTable chunk includes the final screen-reader heading containment fix. Targeted TypeScript, ESLint, Pint and git diff whitespace checks passed. The existing Vite large-chunk advisory remains.
- Browser checks covered library, data, columns, grouped conditions, measures, weekly charts, pivot totals, save/version restore, CSV download, stale-result prevention, purpose-gated live preview, date selection, and client/staff/self-service report flows.
- Final automated axe checks returned zero violations for the light library, light builder, dark builder, export dialog and mobile builder. Personal mobile flows were checked separately. At 390 px the page has no horizontal overflow; the calendar actions fit the viewport. Desktop review included 1280×800 and 1366×900. No page errors were observed. This is scoped browser evidence, not a complete accessibility certification.
- All 61 files in each frozen v1/v2 manifest and all 15 previously audited main-checkout files retain their recorded hashes.

Final fidelity evidence is in docs/fleet-assets-audit/evidence/PKG-09B/fidelity. Earlier implementation evidence remains available separately. No live deployment, production migration, commit or push was performed.

## Installation after review

The code is ready for review in the isolated checkout; the live installation has not been changed.

1. Integrate the reviewed source change and build assets through the normal deployment process. Run the new migration with the normal migration command; do not reset or reseed production permissions.
2. Ensure the normal Laravel scheduler runs. The report subscription command is registered every five minutes and removes expired payloads. Subscriptions remain paused until enabled by their owner.
3. Use a functioning asynchronous queue for production. GenerateOperationalReport uses the default connection/queue, one attempt and a 180-second timeout. Configure the worker timeout and queue retry_after so an in-flight job cannot be reserved again before it times out. A sync queue remains suitable for tests but makes the request synchronous.
4. Explicitly assign assets.telemetry.history only to the intended authorised staff-history roles. Existing client, staff-safety, personal-export, Fleet and finance permissions remain required independently.
5. Smoke-test approved and denied accounts on deployed data, existing organisation branding, source availability and scheduled processing. Hardware delivery and real tracker continuity were not simulated as production proof.

No new external collector, email delivery or off-session tracking is introduced. Existing source collection and personal privacy controls remain the prerequisite for meaningful reports.
