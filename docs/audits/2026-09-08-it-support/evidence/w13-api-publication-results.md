# W13 — atomic API command publication

Status: Implemented and locally Verified for this bounded publication slice. Continuation 11 September 2026. Final Feature14tests218assertions and standalone1test39assertions passed, each with complete isolation postflight. No active owned runtime remains. The previous goal turn made verified W12 progress; this turn preserved the 584-entry working tree and revalidated the bounded W13 prerequisite findings. Full W13/E12 and the goal remain incomplete. Intermediate handles below are historical; final outcome follows at the end.

## Scope and changes

Plan W13/E12; audit feature20 and A10, failed authorized intent replay and safe diagnostics. Extends the existing service identity, API request and canonical domain records for the single organisation and approved Sites. No UI/design/provider changes or production communications.

- `app/Http/Middleware/RecordItApiRequest.php`: one governing transaction commits the API request, domain mutation, minimal completion receipt and required audit together. Ordered user then identity locks serialize even an absent idempotency row. A nested savepoint rolls back returned or thrown command errors before recording a failure. Only a same-hash, currently authorized, proven rolled-back 5xx can reattempt the same receipt. Legacy NULL outcome/pending receipts remain uncertain and cannot be blindly rerun. Database errors and lost transaction/PDO state do not become false rollback receipts. Successful replay reprojects current authorized records.
- `app/Http/Middleware/AuthenticateItServiceIdentity.php`: rechecks the supplied secret against the refreshed identity, retaining only its hash as request-local evidence for publication fencing.
- `app/Domain/It/Services/ItApiWorkItemService.php`: uses current locking identity/user reads within command transactions.
- `app/Domain/It/Services/ItServiceIdentityCredentialService.php`: orders involved actor locks for create/revoke before identity work.
- `app/Models/ItApiRequest.php` and `database/migrations/2026_09_11_000023_add_it_api_publication_outcomes.php`: nullable outcome, actual attempt count and last attempt time on the existing record. Legacy states are not backfilled as known outcomes. Down migration refuses to erase recorded outcomes. Migration not applied to the working database.
- `tests/Feature/It/ItApiPublicationTest.php`: required receipt/audit fault rollback, returned/thrown failure savepoints, same-key recovery, no duplicate replay, changed payload, legacy uncertainty, revoked/stale credential denial.

Two authorized Terra High workers provided read-only backend review and a bounded test implementation; parent reviewed, fixed the test fault-toggle capture, extended coverage and owns production changes and guarded execution. Worker concurrency tests are being prepared separately; source presence is not passing evidence.

## Verification and remaining criteria

Initial Feature96236/tokenit_5e66b70f02e04167 exited0:14 tests215assertions,185s, including the existing8-test secure API regression. All14 postflight checks passed and exact disposable schema absence was confirmed. Evidence: `w13-api-publication-feature-initial.txt` and token diagnostic JSONL. Source review identified lost error-monitoring visibility; parent added bounded log categories/IDs/counts with no Throwable, request body or secret. Legacy5xx replay now returns a generic reconciliation-required outcome instead of retained exception content. Tests assert both changes. Scoped Pint passed. Final Feature23617 is active; standalone real-commit/two-worker proof pending.

New `tests/Concurrency/It/ItApiPublicationConcurrencyTest.php` and `tests/Support/It/api-publication-concurrency-worker.php` are source-only until their separate guarded run completes. Parent review corrected a nested-commit listener to fire only after the outer durable commit, used per-test record deltas, strengthened exact token/schema/barrier isolation and marked each worker's attempt at the actual user FOR UPDATE query. Both workers must remain live at that held mutex before release. Credentials pass only through process environment; emitted evidence contains no reusable credential.

Final Feature23617/tokenit_7b4e95543e62418f exited0:14 tests218assertions, complete14-check postflight and exact schema absence. `w13-api-publication-feature-final.txt` and token diagnostic JSONL are authoritative. These repeat the initial suite, not 28 unique tests. The final scoped source hashes are in `w13-api-publication-source-hashes.json`; all10 protected design files match their original hashes. Standalone concurrency37441 is active and must finish its own postflight before any concurrency claim or further source edit.

No browser claim: this slice changes API publication, and the API identity editor/Operations lifecycle remains later W13 work. Full RBAC/profile/Site evidence serialization, canonical intake/comment adaptation, versioned update/link, rotation/transient secret UI, operational error/recovery views and W11/W12 adapters remain incomplete. Existing live-provider dependencies do not block this isolated API slice.

## Standalone initial result and correction

Concurrency37441/tokenit_472c31e2aebe42d6 exited1 (Pest2). The actual two-worker case passed27 assertions: both workers reached the held user lock, then one ticket/receipt/creation audit/request audit committed and the other request replayed. The second test failed in createApplication before its scenario: this standalone harness retains the exact random schema in process environment and expects one fixture lifecycle, while the new two-method class incorrectly requested another lifecycle. All14 postflight checks and exact schema absence passed. No production defect is inferred from that setup error.

Parent combined both scenarios into a single test lifecycle, keeping the original strict schema guard and naturally committed fixtures. No production source changed. Rerun98503 is active (`w13-api-publication-concurrency-final.txt`). Post-commit acknowledgement-loss recovery remains unverified until that rerun actually executes it.

## Final standalone outcome and resumption

Rerun98503/tokenit_8dc7962b5d43410e exited0:1test39assertions,185s. Both scenarios executed successfully: two real authenticated HTTP workers reached the held actor mutex and converged on one ticket/receipt/creation audit/request audit with one replay; a subsequent request lost its response after actual outer commit, retained its completed receipt and recovered the same ticket on an authenticated same-key replay. Attempt count stayed1 for each successful intent. The second scenario uses a real post-commit listener failure, not an actual killed server or live provider test.

`w13-api-publication-concurrency-final.txt` and the token diagnostic JSONL show terminal success and all14 isolation postflight checks, including exact schema absence. Parent/worker cleanup completed; no active test, import, build or owned browser runtime remains. The working database was not reset or migrated. Final backend/Feature/worker hashes remain unchanged from the passing source snapshot; the corrected standalone class hash is recorded separately. `w13-api-publication-protected-design-check.json` confirms all10 immutable design files are unchanged.

Next work: finish canonical API intake/comment adapters and current authorization evidence serialization, then versioned update/link, credential rotation/transient issue/recovery UI and scoped Operations. API receipt publication now has bounded local proof; it does not establish whole W13/E12, browser acceptance, full RBAC/profile/Site races, live providers or the final release gate. Keep legacy attempt counts unmeasured when execution_state is NULL; historical rows were deliberately not backfilled with invented outcomes. Include the standalone target explicitly in W27 as documented in the concurrency README.
