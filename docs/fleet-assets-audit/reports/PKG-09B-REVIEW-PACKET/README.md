# PKG-09B Reports — frozen review packet

Prepared 2026-09-27T21:46:34.998Z. The existing approved Reports implementation is frozen for Main review on **codex/fleet-personal-reports**, from **926b4981b0289da08a20baca0995117fb53e413e**. The exact review commit SHA is supplied in this chat's final handback; it is the commit containing this packet. No application code was edited during this freeze turn.

Checkout: C:/Users/steph/.codex/worktrees/fleet-personal-reports/oblivionfindings. This packet does not integrate into Main, push, deploy, migrate production, start workers, or change tracker collection. Existing preview processes were left alone. The two preview process logs are deliberately excluded from the commit.

## Actual user scope and amendments

The earlier design-only register is superseded for this Reports work. The following are actual user messages in this chat, with the exact text and IDs retained in [scope-amendments.json](scope-amendments.json):

- **01a0e1d9-72ba-7421-b412-2d8e52e698ca:** “can you please improve this can you also make a report builder fully featured for the fleet please”. This expanded the original Reports v1 design into v2 and a flexible Fleet report builder.
- **01a0e206-fc4d-7972-b6a2-44025f19fbde:** “please audit and do some research of what we can add also can we also do reportsd for client and staff  if the have a personal tracker”. The audit assumed GPS/location safety trackers and proposed Fleet, client and staff sources with separate personal authority.
- **01a0e214-736d-7c22-8cd7-ffc9ac326eeb:** “ok please implement all”. This followed the v2 design and research/audit findings and authorised implementation.
- **01a0e2ac-28fb-7dc0-8967-1e170e995131:** “does it match the mockup?” The response explicitly said only partially and identified the editor/preview composition, library, grouped filters, date buckets, draggable columns and live preview as gaps.
- **01a0e428-30cd-7992-95d0-3989f23121af:** “please continue”. The following pass implemented those visual and interaction corrections while retaining backend permission/privacy checks.

There was no separate literal “approve exact v2” message. The implementation and continuation approvals above are the contextual approval evidence; the original frozen manifests retain their earlier awaiting-approval wording as historical records. The initial implementation handback explicitly disclosed forecasting, natural-language drafting and combined monthly packs as follow-ons. The later continuation addressed the named mockup gaps; it did not supply new source contracts for those conditional ideas.

## Frozen design reference

The target is **Reports v2**, baseline **64995efca1b067814a53f93fae962028e6510eb1**, at http://127.0.0.1:8968/?view=builder. The original design checkout remains read-only: C:/Users/steph/.codex/worktrees/pkg09b-reports-design/oblivionfindings. Rory/Main design references were not modified.

- [v2 freeze manifest](v2-freeze-manifest.json): SHA-256 **53e96b73ca7408eb8eb69d41d1720aa589c92196b7a041320aba919bbd8c521d**; all 61 listed files still match. V2 recorded 21 engine checks and 34 browser checks, with zero page errors.
- [v1 freeze manifest](v1-freeze-manifest.json): SHA-256 **5dbb05cd6b00e3bf8405c851531aa272b9596192140b61f4e0948ecac40071a0**; all 61 listed files still match. V1 remains preserved at port 8967.
- [Frozen v2 library](v2-01-library-1440-light.png) and [frozen v2 builder](v2-02-builder-data-1440-light.png) are unchanged reference copies in this packet.

The implementation matches the main composition and interactions, not every pixel. It uses a 294 px editor beside a 788 px preview at 1366 px, the restored library and focused report navigation, and shared Save/History/Export dialogs. Purpose, authority, source evidence and private-run state remain intentional additions. Header figures stay unknown until a permitted result exists. The synthetic preview uses comparable shell chrome; production retains the application's existing AppLayout. **Genuine browser zoom remains unverified** for both design and implementation. Viewport resizing is not being presented as zoom verification.

## Delivered implementation and source manifest

[Source manifest](source-manifest.json) records the explicit 94-file existing payload: **46 application/test files and 48 documentation, evidence and synthetic-preview files**, with byte counts and SHA-256 hashes. The commit allowlist also includes this review packet. No dependency lockfiles, production environment files, vendor directories, generated production bundles or unrelated work are included.

- Shared Fleet/client/staff/self-service studio with 22 permitted sources and 21 starter templates; source scope, site/resource or client/session selection, ordered columns, grouped AND/OR rules, conditional/formula/distinct/percentile measures, calendar buckets, source sorting, charts/tables/pivots, period comparison and explicit unknowns.
- Purpose-gated debounced live preview, stale-export protection, location precision before aggregation, draft recovery, undo/redo, import validation, folders/favourites, immutable saved versions, conflict handling, restore/duplicate/archive, definition sharing and paused-by-default subscriptions.
- Private CSV, JSON, Excel and branded PDF exports. CSV supports source rows or grouped summaries. XLSX has Scope, Summary and Source rows sheets. PDFs include provenance and purpose. Negative numeric values remain numeric; untrusted formula-like strings are neutralised.
- Entry points: /fleet-assets/reports/builder, /operations/people-location-reports/client, /operations/people-location-reports/staff and /my-day/safety-reports. Existing Fleet, client-location, lone-worker and My Day pages link to them.

The synthetic browser preview at http://127.0.0.1:8973/ bundles the actual reporting component with synthetic data and adapters confined to docs. Its CSV/JSON downloads, sharing and schedules are demonstrations. PDF/XLSX and authoritative permissions were verified against the PHP application, not inferred from that preview. The source manifest records the frozen preview files; no new preview was launched for this handback.

## Authorization, export and privacy boundaries

This is **one operating organisation across approved sites**. No tenant selector, transport, queue or SaaS boundary was added. Roles, approved accounts/sites, canonical ownership, direct-object denial and existing privacy authority govern records.

- Fleet report access does not grant client/staff sources. Vehicle/resource candidates retain their canonical site/asset policy. Finance additionally requires finance.assets.view and finance.ap.view, with finance site intersection and canonical asset association. Posted costs, invoices, payments and fuel observations remain distinct. Overlapping downtime is unioned.
- Client reports require an authorised client and current linked tracker assignment/consent. The shared report window clips to consent, assignment, collection and retention boundaries. Conflicting assignment evidence is rejected. Map/history/export/report paths use the shared window.
- Staff location/readiness reports require a selected authorised lone-worker session, matching worker/site/assignment/collection evidence and retained observations. Historical staff location additionally requires **assets.telemetry.history**, which is not auto-granted to admin. Ending a session does not restart collection. Self-service is scoped to the requesting worker's safety sessions.
- Run payloads use Laravel encrypted:array, are owned by the requesting user and have a 24-hour expiry. Reads/exports recheck current source permissions, owner, consent/session/assignment fingerprints and the advancing retention boundary. Earlier source-retention expiry blocks access even before the run's 24-hour cleanup. The scheduled cleanup clears payloads at run expiry; do not interpret this as physically purging every payload at an earlier source boundary.
- Personal exports additionally require assets.telemetry.export. Permission/authority is checked after rendering and before response bytes are returned; successful export requires audit logging. Definition sharing clears person/resource/site selection, rules and conditional values and never transfers record access or private results.
- Missing observations remain unknown. GPS movement is not proof of attendance, productivity, continuous presence or safety. Client zone reports require canonical breach evidence; intermittent points do not establish complete dwell/entry/exit history. No live collector, off-session tracking or external email delivery was introduced.

## QA carried with the frozen source

Current application and test bytes match the recorded QA source hashes. No application/test change was made in this packaging turn, so the existing acceptance run was retained rather than repeated. The four differences from older manifests are the final generated synthetic app.js/app.css/build-inputs.json and updated implementation handoff, all recorded with current hashes here.

- **20 tests passed, 265 assertions**: [final acceptance log](../../evidence/PKG-09B/fidelity/acceptance-verified.log). Includes consent withdrawal, complete 601-observation export beyond the separate 500-point map cap, pre-consent exclusion, no bytes after late authority changes, staff session/retention boundaries, other-user denial, safe sharing, schedule reauthorisation, source scoping, formulas, grouped/date comparisons, distinct/null handling and recomputed pivot margins.
- Final [Vite production build](../../evidence/PKG-09B/fidelity/production-build-final.log), targeted TypeScript, ESLint, Pint and authored-file whitespace checks passed. The full staged whitespace check flags preserved raw test-log formatting and generated preview-bundle licence comments; those evidence bytes were not rewritten to silence it. The standard Vite large-chunk advisory remains. No new Composer/npm dependency was introduced.
- [Final fidelity evidence](../../evidence/PKG-09B/fidelity/fidelity-evidence.json) covers 1366×900, 1280×800 and 390×844, drag/keyboard columns, grouped filters, date buckets, pivot totals, saves/history, CSV, import rejection, stale result guards and live preview. Final sampled states had no horizontal overflow or page errors.
- Recorded axe results: zero violations for light library/builder, dark builder, export dialog and mobile builder; client/staff/self mobile flows were also checked. This is scoped automated evidence, not a full accessibility certification. Genuine browser zoom and deployed authenticated-shell browser smoke tests remain outstanding.
- Real PDF output was rendered and visually inspected, including final layout spacing. Excel ZIP/XML preserved negative numeric cells. [Additional exporter check](../../evidence/PKG-09B/implementation/reports-export-final.log): 1 test/20 assertions. [Additional scope checks](../../evidence/PKG-09B/implementation/reports-scope-tests.log): 2 tests/24 assertions. These are historical targeted runs, not extra tests added to the final 20-test total.
- **Known baseline failures:** four CanonicalIntegrationEventHistoryTest cases still return 403 where older fixtures expect 200: client history, resident history, portal history and narrow history window. [Baseline reproduction log](../../evidence/PKG-09B/implementation/reports-baseline.log) documents reproduction with untouched baseline versions of the affected services. Access checks were not weakened. Main should reconcile these separately; this is not a claim that the entire repository test suite is green.

## Migration and runtime requirements

The additive migration **2026_09_27_230000_create_operational_reporting_tables.php** creates operational_reports, operational_report_versions, operational_report_runs and operational_report_subscriptions, plus the separate assets.telemetry.history permission. No production migration was run. Its down method drops the report tables and does not delete the permission. Production RBAC must not be reset or broadly reseeded.

Integration prerequisites:

1. Reconcile against current Main, keeping later approved shared-service/UI changes. Existing fleet/site/finance/consent/assignment/lone-worker source contracts are required. Review shared changes in ClientLocationHistoryService, PersonalTrackingLocationExportService, IntegrationEventHistoryService, SecurityDevicesAccessService, VehicleBookingAccessService and extracted LoneWorkerSessionScope; also preserve later shared PageHeaderRail, EntityTable and BrandedWorkbook changes.
2. Build through the normal pipeline and apply the additive migration normally. Dependencies are the repository's existing Laravel/Carbon/DomPDF stack, PHP ZipArchive for XLSX, React/Recharts/Radix and current shared UI. Retain the app encryption key and normal private headers/audit infrastructure.
3. The existing Laravel scheduler invokes reports:run-schedules every five minutes with withoutOverlapping. It queues due subscriptions and removes expired run payloads. No scheduler or worker was started for this handback.
4. Use an asynchronous queue in production. GenerateOperationalReport uses the default connection/queue, one attempt and a 180-second timeout. Align worker timeout and retry_after to avoid overlapping reservations. Sync queues used in tests do not prove production queue operation. Subscriptions begin inactive and pause if current authority fails; notifications are generic database/in-app notices only.
5. Explicitly assign assets.telemetry.history to intended roles; maintain separate client/staff safety/export/finance permissions. Smoke-test permitted and denied users, organisation branding, source completeness, schedule processing and actual hardware evidence after integration.

## Remaining scope and limits

Conditional future ideas remain **not implemented**: combined multi-section monthly packs, natural-language report drafting and fuel/EV/battery forecasting. They need explicit source contracts and acceptance examples. Continuous utilisation, presence/dwell, battery hours remaining and attendance must not be invented from intermittent telemetry. Free-text care notes are not repurposed as a coded transport-cancellation reason.

Boundaries: Fleet dates up to 366 days, personal dates up to 31; 30 columns, 25 rules across 8 groups, 8 measures and 2 dimensions; bounded groups/charts and 500 browser rows; over 100,000 source rows fails explicitly rather than silently truncating. PDF allows at most 2,000 source rows and 10 columns. Full eligible source rows can be exported in the other formats within the source bound. Production scale, live device continuity, deployment/queue setup and full-browser zoom remain unverified.

The earlier [implementation handoff](../OPERATIONAL-REPORTS-IMPLEMENTATION.md) retains its chronological no-commit statement from implementation completion. This packet and the final commit handback supersede that statement only for the local review commit. Main has not been edited by this chat and no merge/push was performed.
