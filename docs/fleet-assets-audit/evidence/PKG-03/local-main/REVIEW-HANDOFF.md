# PKG-03 local-main integration candidate

Stephan's direct request: “can you let main know can you get this on main locally”. Local integration only; no remote push or operational deployment is authorised by this request. Main retains technical review and the serial integration slot. This chat owns the actual local merge after that review, preserving Main's existing dirty programme files.

## Exact candidate

- Branch: `codex/pkg03-local-main`.
- Candidate application/tests: `20281ab99d4f7cb6db01e823cebe84eac5c6c2e9`.
- Actual integration base: `ba5bff2e8b6c22796369443f1cdac918039950dd`.
- Original implementation: `feca8ad57`; reconciliation merge: `7afb83403`; test reconciliation: `c7d6b3cdc`; final service-race harness correction: `20281ab99`.
- `source-manifest.json` contains the exact 72 changed source/test/build files and their working-file SHA-256 values. Later evidence-only commits do not change these source hashes.
- Application ownership remains one organisation, roles, approved sites and canonical record privacy. No new tenancy boundary.

## Reconciliation

Four content conflicts were resolved. `VehicleDocumentService` keeps Main's source-aware authorisation before replay and PKG-03's immutable Finance evidence check for a new mutation. The shared command hook retains the original method, URL, body and idempotency key after an uncertain response. Both multipart regression tests were retained. Finance test imports were combined. Main's newer Maintenance transition, navigation, route and test-harness changes are retained; the shared `TestCase.php` is unchanged from Main.

Main now rejects attempts to archive evidence on a decided source with HTTP 422 before reaching the older 409 guard. The regression asserts the canonical source validation response and verifies both the file and retained decision manifest are unchanged.

The initial integrated races still hit their startup barrier. The CLI workers used interactive `Auth::login`, which runs unrelated login/session/audit writes against the same user. They now establish actor context with `Auth::setUser`; the real browser login remains separately verified. Worker startup, process and database waits remain bounded, and the shared lock barrier, posting, receipt and contradictory-outcome assertions remain intact. No application safeguard was relaxed.

## Final verification

- Backend: **76 tests, 1,399 assertions, zero failures/errors/skips**, exit 0. The 72 feature cases report environment warnings. See `integration-backend-complete.xml` and `.log`.
- Both approval/approval and approval/cancellation independent-process races pass in that complete run.
- Frontend: **36 tests across seven files**, exit 0.
- Full TypeScript, scoped ESLint and affected PHP formatting pass.
- Fresh production client build: **5,371 modules**, exit 0. Existing large-chunk warnings remain.
- All **311 frozen v1–v9 files** retain their hashes. Original mockups remain in this worktree, outside the application commit.

The first integrated backend run and subsequent startup-only failure are retained as `integration-backend.*` and `integration-backend-final.*`; neither is represented as a clean run. The complete run uses the repository's supported `MYSQL_TEST_SCHEMA_PATH` and bounded schema timeout, loading a cache containing only table structure and migration ledger from the disposable browser database. No user or Finance rows were copied into the test schema, and the normal per-process database isolation/cleanup remains enabled.

## Signed-in actual application check

The actual Laravel application and compiled production components run at `http://127.0.0.1:8772` from dcf0, using only `oblivion_findings_pkg03_browser_dcf0_20260927`. Normal Fortify sign-in and session/CSRF processing are used with a synthetic reviewer. There are no Inertia response fixtures, layout substitutions or authentication bypass routes.

Verified through the browser:

- Work Cost & evidence reads the seeded canonical estimate and bill.
- A work note saves through the real endpoint and remains after navigation.
- The protected quote endpoint renders the PDF inside the approval wizard.
- Approval posts the synthetic $575 bill and returns its retained journal receipt.
- The bill reloads as approved; journal JNL-000001 has balanced $575 debit/credit lines and source links.
- Returning to work shows the posted journal while work remains open, Finance review remains pending and payment remains $0.
- The requester's attempt to decide their own Finance review is blocked by the real application.
- The work and journal fit a 390-pixel viewport without page overflow (375-pixel document width with the scrollbar).

`signed-in-readback.json` independently confirms the persisted note, approved bill, posted journal, single receipt, open work and zero payment. The quote's clean state was seeded for PDF testing; a live malware scanner, workers/scheduler and operational storage are not certified by this check. No operational records were used.

Temporary dev-server dependency optimisation caused stale React modules, and the initial built-asset router omitted the JavaScript MIME type for `.mjs`. These verifier issues were corrected before final acceptance: browser checks use the compiled production assets, and the quote renders successfully. The minimal temporary manifest maps those exact generated entry assets; it is not a replacement production build configuration.

## Remaining release boundaries

Apply the dependency lockfile, build assets and migration through the normal release process. Validate long-lived workers, scheduler, durable private storage, scanner, monitoring, expected load and backup restoration in the target environment. Legacy unassigned bills still need explicit approved-site assignment. The migration deliberately retains financial evidence and requires a reviewed forward migration for rollback.

Local-main integration and Main's review decision are recorded separately after this candidate is accepted. No remote push or deployment has occurred.
