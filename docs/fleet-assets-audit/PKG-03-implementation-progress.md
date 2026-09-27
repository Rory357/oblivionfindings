# PKG-03 production workflow implementation

Authorised by Stephan: “ok do all please”, following the seven findings and four feature additions in this chat. This extends the earlier two-gap implementation; it does not deploy, merge, publish or alter frozen v1–v9 previews or protected design guides.

One operating organisation across approved sites. Roles, explicit central Finance permissions, canonical record ownership and privacy are the access boundary. Existing organisational schema remains unchanged. Desktop application work; one writer.

Verified turn: `01a0dfcc-67fb-7612-a2ca-e8cce3894aad`, `gpt-6-astra`, `xhigh`, workspace `C:\Users\steph\.codex\worktrees\dcf0\oblivionfindings`.

## Implementation checklist

- [x] Transactional cancellation, bill site access on reads/mutations, site selection for bill entry.
- [x] Durable approval receipt and recovery of the original result after a lost response.
- [x] SQL bill summary totals and bounded duplicate-invoice warning lookup.
- [x] Evidence preparation/finalisation, reviewed evidence snapshot and retained decision evidence.
- [x] Return for correction, requester response/resubmission and history.
- [x] Explicit reviewer assignment and due date; durable in-app notices and due reminders with scoped recipients and deduplication.
- [x] Production Finance review and bill approval wizards: draft/busy guards, reload/retry and persistent success.
- [x] Private bill-owned evidence upload, scan/retry states and protected PDF/image preview.
- [x] Traceable links from review to source bill, journal and payment history, preserving permissions.
- [x] Focused backend, UI, concurrency and volume tests; lint/types/style; browser verification against this checkout.

## Decisions

- Existing review requests remain readable. New requests with selected uploads are not ready for a decision until their evidence is finalised.
- Assignment and due dates are explicit user choices. No inferred business SLA or silent permission grants.
- Notifications remain in-app. They must persist delivery intent transactionally and recheck recipient access on delivery.
- Duplicate invoice detection is a warning, not a new accounting prohibition.
- Unassigned legacy bills require explicit central Finance access and must receive an approved site before operational approval.
- File access continues to require clean scan state and fresh record authorisation; paths are never public.

## Verification record

Earlier two-gap evidence remains in `evidence/PKG-03/implementation-validation.json`; it does not certify this larger change. New evidence is in `evidence/PKG-03/production-workflow/`.

The four-file Finance/Fleet regression run passed 48 tests and 846 assertions. Separate independent-process MySQL tests passed both approval/cancellation and duplicate-approval races (13 assertions). The final Fleet run passed 17 tests and 523 assertions, including bill-site privacy after a site change and notification delivery beyond 200 users. The final bill run passed both withdrawal/audit and Auckland calendar-date checks (34 assertions).

The 22 focused UI tests, full repository TypeScript check, scoped ESLint and Pint passed. The full production frontend build passed (5,362 modules); it reports application chunks above 500 kB. A final small upload-recovery message/close-handler refinement was separately checked by its 10 UI tests and ESLint. Test runs report environment warnings; earlier detailed output identified the missing worktree `.env` warning. Database tests used disposable per-process MySQL schemas, automatically removed after completion.

The worktree's shared `node_modules` was not modified. Verification resolved the new PDF dependency through an isolated local installation; normal installation from the updated lockfile is required in a fresh checkout or release environment.

Browser verification used the actual React components and a synthetic invoice on an isolated local harness. It verified inline PDF rendering, decision selection, protected unsaved notes and persistent success. Save responses were fixtures; no live Finance record was modified. This is component browser verification, not a signed-in production acceptance test. The protected backend endpoints are covered by feature tests.

All 311 files recorded by the v1–v9 freeze manifests retain their hashes. The frozen mockups are unchanged and are separate from this production implementation.

## Additional recovery and privacy fixes

- A completed approval remains recoverable if its earlier Governance evidence expires; current bill, site and action permissions are still checked.
- An uncertain multipart retry preserves its original bytes, metadata and idempotency key. A failed attachment upload retries against the existing review request.
- Vehicle-linked bill views, bill-source labels and linking searches respect the bill's current approved site after a site change.
- Failed bill evidence can be withdrawn once with a retained actor audit; original bytes stay private. Decided review evidence cannot be replaced or archived.
- PDF rendering uses a local worker, fonts, character maps and image decoders. It renders one bounded page at a time and provides explicit retry/download recovery.
- Bill due-date summaries and overdue labels use the Auckland calendar day, including the UTC date boundary.

## Release prerequisites and limits

Local-main integration is being prepared under Stephan's explicit request; the exact status is recorded in `evidence/PKG-03/local-main/`. This work has not been remotely pushed or deployed. Deployment must install the updated lockfile and build assets, apply `2026_09_27_000100_complete_finance_review_workflow.php`, and restart long-lived queue workers. PDF.js requires Node 22.13+ or 24+ for installation/build.

The existing scheduler must run every minute and a queue worker must consume `DeliverFinanceReviewNotice`. The registered `finance:review-notices` command recovers pending delivery; `finance:review-reminders` records due reminders at 08:30 Pacific/Auckland. Delivery rechecks permissions and site access. No emails or external messages are introduced.

Private local-disk evidence requires persistent storage accessible to every application instance and scanner that handles these records. The existing malware-scanner configuration must be operational; unavailable scans stay blocked with retry/withdraw recovery. Monitor overdue undelivered outbox rows, failed jobs and unavailable scan states through operational tooling.

Unassigned legacy bills require an explicit central Finance user to assign their approved site before approval. Existing organisational columns remain untouched. The migration retains financial evidence and deliberately refuses destructive rollback; rollback requires a reviewed forward migration.

The tests establish functional, permission, race and bounded-volume behaviour. Production throughput, multi-node deployment, disaster recovery and a signed-in staging acceptance run remain release validation; no production-scale benchmark is claimed.

## Follow-up implementation audit — 27 September 2026

User authorisation: “look for any other gaps … please start implementation”. This pass fixes confirmed recovery and permission gaps in the production source; the frozen mockups stay unchanged.

- Finance bill approvals, evidence actions, review creation/decisions/submission, assignments and record links now validate the endpoint's saved-result shape. An incomplete or mismatched approval receipt, missing review record, empty upload response or false save acknowledgement keeps the command uncertain, its form locked and its original retry identity intact.
- An uncertain retry preserves the HTTP method as well as the original URL, body and idempotency key, even if a caller passes different values.
- Queued due reminders recheck the current deadline and assigned reviewer. Postponed, cleared or reassigned reminders are consumed without notifying; the scheduler can record the current version on the same day without duplicating that version. A correction notice is suppressed after the request has moved out of the correction state.
- A selected vehicle document that no longer belongs to the request's vehicle blocks submission and decision, instead of silently disappearing from the required evidence.
- The submit-evidence control follows the requester's current Fleet manage permission; the backend continues to enforce the same permission.
- Browser verification found and fixed nested paragraph markup in the bill approval receipt, removing React hydration warnings while retaining the shared wizard success pane.

Verification: 21 Finance/Fleet feature tests passed with 543 assertions, against an automatically removed disposable MySQL schema. The runner reported environment warnings as in the earlier runs. All 29 focused UI tests passed, including lost/malformed response recovery, wrong-bill receipts, upload recovery, queue decisions and PDF previews. Full repository TypeScript, scoped ESLint, PHP style and whitespace checks passed. The final receipt markup was subsequently checked by the full focused UI suite, lint and browser verification. No dependencies or build configuration changed in this pass; the earlier full production build is not claimed as a fresh build of these follow-up changes.

Browser verification used the actual edited approval component at `http://127.0.0.1:8766/.pkg03-gap-browser.html`, served explicitly from this worktree with a synthetic invoice and a fixture endpoint. Its first response deliberately omitted the receipt fields; the second accepted only the same submission identity and body. The dialog showed safe retry and then the retained receipt, with no console warnings or errors after the markup fix. This was not a live financial transaction or signed-in staging acceptance run. The temporary browser tabs, server and isolated dependency installation were removed after verification.

Evidence is saved alongside the prior records as `gap-validation.json`, `gap-source-manifest.json`, `backend-gap-regression.log`, `ui-gap-regression.log`, `types-gap.log`, `lint-gap.log`, `pint-gap.log`, `approval-gap-recovery.png`, `approval-gap-success.png` and `frozen-gap-verification.json`. All 311 frozen v1–v9 files retain their hashes. The release prerequisites and production-scale validation limits above still apply.

## Mockup implementation — local completion verified

The remaining PKG-03 v9 screens and interactions are now connected to the application: work Cost & evidence and estimate history/uploads, bill/quote/order comparison, the shared approval wizard, Finance queue batch decisions, journal/source outcomes and work completion selection. They use canonical services and permissions rather than the mockup's simulated records. See `PKG-03-mockup-implementation.md` for the route mapping and acceptance record.

A subsequent visual comparison corrected the initial completion claim. The final completion pass now supplies the Estimate / Committed / Invoice / Posted / Paid header, its header estimate action, decimal-safe server totals, direct safety-release context, selectable phone cards for the Finance queue and mobile bill-overflow fixes. Work actions and wizard footer buttons use 44-pixel phone targets. The actual Inertia entry point and application shell were inspected at desktop and phone sizes in light and dark themes, using synthetic server fixtures. The earlier compressed component capture is not the acceptance evidence.

Verification covers 32 UI tests, full TypeScript, scoped ESLint/Pint, a fresh production build and browser checks of the actual components with synthetic fixtures. The final backend run exercised 72 cases with 1,365 assertions and one worker-startup error; both concurrency cases passed on focused rerun with 13 assertions. The complete results and screenshots are under `evidence/PKG-03/mockup-implementation/`. The frozen mockups remain unchanged. Signed-in staging acceptance, deployment and production-scale validation remain release work.

The completion pass extends the UI coverage to 35 cases and adds four pure cost-summary unit tests (five assertions). TypeScript, scoped lint/style checks and the 5,366-module production client build pass. All 311 frozen files remain unchanged. The `completion-*` logs and `*-application-*.png` screenshots record the latest verification. The real local Herd host refused connections, so signed-in acceptance remains explicitly pending a running local/staging URL; no production deployment or load-test result is claimed.

## Local-main integration verification

The subsequent reconciled candidate passes 36 UI tests and a complete 76-case backend run with 1,399 assertions and no failures/errors; environment warnings remain. TypeScript, scoped lint/style and a fresh 5,371-module client build pass. The CLI concurrency workers now set actor context without invoking interactive login listeners, and both races pass in the complete run. Main's current evidence-source denial contract is retained and tested for unchanged files and decision evidence.

The previous signed-in local verification gap is now addressed at a dedicated loopback Laravel instance with a disposable synthetic database and the compiled production components. Normal login, a persisted note, protected PDF preview, bill approval and receipt, balanced journal/source links, independent unpaid/open outcomes, self-review denial and phone-width fit were verified. Target-environment scanner, queue/scheduler, storage, monitoring and scale checks remain release work. The exact candidate, base, source hashes, test logs and screenshots are in `evidence/PKG-03/local-main/REVIEW-HANDOFF.md`.
