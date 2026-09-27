# PKG-03 v9 application implementation — verified locally

Authorised by the user on 27 September 2026: complete the mockup in the actual application and verify it screen by screen. This supersedes the older design-only gate for implementation; frozen previews and design guides remain reference material.

One operating organisation. Keep the existing application shell, routes, source records, permission checks, private storage, approval services and independent maintenance release rules. Do not ship scenario selectors, synthetic records or simulated results in the application.

## Scope and acceptance

- [x] Maintenance work → Cost & evidence: two-column cost context, retained estimates, linked commitments/bills, evidence, notes, next Finance action and independent outcomes.
- [x] Record estimate: supplier/reference/amount/allocation, supporting files, review, version conflict, safe retry and retained revision history.
- [x] Link existing Finance bill: current resource/site scope, review and persistent result.
- [x] Work-to-Finance review: create, evidence preparation, correction/response, review detail/history and source links.
- [x] Finance review queue: clear next action, filter/sort, selection, independent per-record batch decisions and retained partial results.
- [x] Supplier bill: invoice/quote/order comparison, allocation/review links, decision sidebar, posting/payment outcomes and actionable recovery states.
- [x] Review & approve wizard: shared review cards, current source evidence, confirmation, stale reload, retry and receipt.
- [x] Finance event: canonical journal and source bill/work links, retained reversal/correction relationships and separate payment state.
- [x] Work completion selection: per-record outcome/retry, preserving the existing repair and release checks.
- [x] Browser verification of actual production components and backend permission/recovery checks; record any unverified signed-in/staging boundaries explicitly.
- [x] Match the work Cost & evidence header to the mockup's Estimate / Committed / Invoice / Posted / Paid summary.
- [x] Verify complete pages in the actual application shell against the frozen mockup, including responsive and dark-theme presentation.
- [ ] Signed-in acceptance against a running application and its real service configuration.

## Completion pass — 27 September 2026

The remaining local implementation and visual alignment work is complete. The Finance tab now shows the work title, cost-stage meters and its estimate action in the shared page header. Other work tabs retain their operational controls. Source links, evidence, notes and independent outcomes use the actual application shell; safety restriction review is directly accessible beside Finance and payment outcomes.

Cost-stage totals use server-side decimal arithmetic. Linked purchase orders count once, unapproved orders and cancelled invoices are excluded from their respective totals, and reversed journals do not appear as posted spend. Inaccessible linked bills make the financial totals explicitly incomplete. A confirmed zero-value posting remains distinguishable from an absent posting. The stages describe the same spend and are never added together.

Full-page testing found and fixed phone-width overflow in the bill workspace, including its invoice and linked-review content. Finance review requests now use the shared selectable record cards on phones and the shared table on desktop. Work actions and the estimate, approval and batch wizard footer buttons have 44-pixel touch targets on phones. These changes retain the shared wizard geometry and completeness rail required by the current popup guide; they do not copy a separate modal shell from the mockup.

The final browser verifier serves `resources/js/app.tsx`, the real Inertia pages, navigation, application shell and styles from this worktree. It supplies synthetic Inertia responses and a local PDF; there are no layout or Inertia-library replacements. Work, bill, queue and journal pages were inspected at desktop and phone widths, with light and dark theme checks. The bill approval PDF rendered inside the scrolling wizard, its footer stayed accessible, cancelling restored focus, and phone-card selections opened the intended batch records. Source navigation from work to queue and journal to work was verified. Journal lines retain the shared ledger table with scrolling contained within the table. No real financial records were changed.

The final checks cover 35 UI tests, four cost-summary unit tests (five assertions), TypeScript, scoped ESLint, PHP formatting, whitespace checks and a 5,366-module production client build. The build retains the existing large-chunk warnings. The compiled work chunk was checked to contain the final zero-value-posting logic. Prior backend integration results below remain applicable; the backend integration suite was not rerun for this visual completion pass.

Authoritative completion screenshots use `*-application-*.png`, plus `approval-full-shell-dark.png`. They capture the actual application viewport. Full-page screenshot stitching produced distorted captures in this browser, so those captures are not used to establish layout acceptance. Completion logs, the full-page verifier, source hashes and results are retained beside the earlier evidence.

Signed-in acceptance is still unverified: `https://oblivionfindings.test` refused connections, and a running local/staging URL was requested. Local implementation completion does not claim deployment or production throughput acceptance.

## Existing implementation

Review/approval services, bill evidence, PDF renderer, correction/resubmission, reviewer assignment, reminders and command recovery already exist from the earlier implementation passes. Reuse and connect them. Maintenance completion and journal reversal already have canonical services; their rules must not be replaced with the mockup's simulated decisions.

## Verification

**Earlier completion correction:** the initial functional checks below did not establish full visual parity. The work header and component-only capture were insufficient. The completion pass above addresses those findings with the cost-stage header and actual application-shell verification; the earlier completion wording remains recorded here as too broad.

The 32 focused UI tests pass across seven files, including estimate validation after an upload, per-record batch review, uncertain command retry, approval receipts and PDF preview recovery. The full repository TypeScript check, scoped ESLint, PHP formatting and whitespace checks pass. The production build passes with 5,366 modules; it retains the existing application chunk-size warnings.

Browser verification of the actual edited components passes at 1366- and 1280-pixel desktop widths, including inline quote PDFs, the shared approval wizard, retained results and maintenance release blockers. The final browser session reported no warnings or errors. Screenshots, the fixture harness, logs and source hashes are retained in `evidence/PKG-03/mockup-implementation/`. All 311 frozen v1–v9 files retain their recorded hashes. The temporary browser tab and server were closed and the viewport override reset.

The final-source backend run exercised 72 tests with 1,365 assertions. It had no assertion failures and one error: an independent approval worker missed the test's startup deadline. Both concurrency cases then passed on a focused rerun, with 13 assertions and no errors or failures. All 72 cases are covered successfully across those runs; this is not a claim that the original full run was entirely clean. Machine-readable results are retained as `backend-full-results.xml` and `backend-concurrency-results.xml` because the temporary launcher did not emit a console summary.

The new tests verify estimate revisions, safe retries, stale versions, quote changes invalidating approval, protected file access, scan-state blocking, canonical Fleet/Finance permissions, source ownership and receipt recovery after removal of an asset record. During verification, the quote-file fixture was corrected to use the shared canonical staff-profile helper. Quote-file access now reuses the vehicle document policy without widening Finance access.

## Application mapping

- **Work**: `/fleet-assets/maintenance/work-orders/{id}?tab=finance` renders the Cost & evidence workspace. Estimates are retained maintenance actions with optimistic versions and idempotency keys. Actual costs remain Finance-owned. New quotes use the existing private document upload and scan service; prior estimate revisions retain their file identities.
- **Queue**: `/finance/vehicle-reviews` has status, preparation/correction, assignment and overdue filters, server sorting/pagination, next-action/owner context and per-record selection. Batch resolution invokes the canonical decision endpoint independently for each selected record. It retains partial results and the identity of any unconfirmed submission. A stale or denied record must be reopened and reviewed before a new submission.
- **Bill**: `/finance/bills/{id}` compares the invoice, linked quote, purchase order and supporting files, with allocation, linked reviews, payment history and a decision/outcome sidebar. The approval wizard uses shared review cards and previews actual PDFs. Its signed snapshot includes the linked quote and evidence state, and approval locks the asset before the bill to serialize maintenance quote/link changes with posting.
- **Completed approval recovery**: a retained receipt does not depend on the continued presence of its linked asset record. Current bill, Site and action permissions still apply; the asset lock is required only for a new posting. Normal asset retirement keeps its record and history.
- **Finance event**: `/finance/journals/{id}` remains the canonical posted accounting entry. It links to an authorised source bill/work record, shows payment separately, and retains original/reversing journal relationships. Existing accounting correction/reversal rules remain authoritative; no synthetic correction event or parallel ledger was introduced.
- **Completion**: `/fleet-assets/maintenance/work-orders` supports selection in both table and card layouts. Each completion calls the existing transition service independently and retains its own result. Repair, retest and hold/release rules continue to apply.
- **Review corrections and file preparation**: the work sidebar opens the existing review detail/history and response wizard. Further supporting files and scan recovery remain available in the canonical vehicle Finance workspace. They are not duplicated into a second evidence service.

## Verification boundaries

The earlier `browser-harness.zip` replaces the outer application layout and Inertia transport and remains evidence of component behaviour only. The later `completion-browser-harness.zip` uses the real application entry point, shell and Inertia library with fixture server responses. Its `*-application-*.png` captures supersede the earlier captures for page-layout acceptance at 1366, 1280 and 390 pixels. Both use synthetic records and a local PDF; neither establishes signed-in service acceptance. No real bill was approved or paid during browser verification.

Backend verification used disposable MySQL schemas. An initial run repeatedly reinitialised the schema without producing a result. It was stopped and a fresh run used a temporary longer schema-loader timeout. The original test harness was restored byte-for-byte after that process loaded it. After the fixture failure, reruns used a task-local copy of the harness with the longer import timeout and an explicitly named reusable test schema; the repository harness remains unchanged. Reuse required all 1,104 migrations to be present. The exact harness changes and launcher are retained in `backend-harness.patch` and `backend-harness.zip`. The disposable schemas and temporary verification files were removed after verification; no production data was used.

Deployment, signed-in staging acceptance and production throughput/multi-instance validation remain outside this local implementation. The existing migration, worker, scheduler, private storage and malware-scanner release prerequisites in `PKG-03-implementation-progress.md` still apply. No additional migration was required for retained estimate actions or this screen integration.
