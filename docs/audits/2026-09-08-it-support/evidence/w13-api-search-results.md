# W13 API request-history search

Scope: W13 / E12 Operations diagnostics, continuing the existing canonical API receipt presenter and approved history components. Full W13 and the release gate remain incomplete.

## Implementation

- Validated header search now reaches `ItApiOperationsPresenter`. Matching history totals are separate from overall manageable-identity health: filtering never hides global recorded failures, pending work, oldest pending evidence or last success.
- Search uses public identity names, operation/outcome/category labels, receipt IDs and response status. Private paths, response bodies, hashes, credentials and linked ticket IDs are not searched or projected. Raw paths are classified into fixed public operations inside the database; the same strict classification drives rows and matching predicates.
- Classification preserves case-sensitive methods/routes, leading-slash normalization and numeric route IDs. End-character checks reject trailing line terminators. Unknown legacy requests remain unclassified and require reconciliation.
- Previous, numbered and next links come from the server and preserve search and the selected automation period. API history itself is not restricted by the automation date period. Pagination preserves the history scroll position.
- Typed history count/query/link fields drive the UI. No-match copy offers changing or clearing header search and distinguishes an empty retained history. Search context explains that health totals remain global to the viewer's manageable identities.

## Verification in progress

- Guarded backend session96426, token `it_113fa576193f48ef`, running `ItApiOperationsScopeTest.php` and `ItServiceOperationsTest.php`. Four added API tests cover retained pagination/global facts, strict legacy classification, outcome/category searches, and private-data/current-authority exclusion. No backend pass claim yet.
- Focused UI: 45 tests passed across four files. The full TypeScript check initially found an unsupported `exact` option in a test's role query; removed it because a string accessible name is already exact. Corrected full TypeScript passed; affected component rerun passed all six tests. Scoped lint and Pint passed.
- Build5768 is active in `w13-api-search-build.txt`. Browser verification remains pending. No owned runtime, browser tab or other database import is active.

## Next

Wait for the exact backend and build handles. Inspect every failure and all isolation postflight results. Then use a fresh reviewed API-fixture runtime and current assets: normal technician header search for validation (26 records across 25+1), numbered/next/previous navigation with retained query/date context, no-match/private-marker exclusion with unchanged health, exact receipt/operation/outcome search and safe recovery. Verify the other identity owner and requester exclusion. Remove only the owned runtime and confirm independent postflight.

Continue with W14's durable automatic-ticket outcomes after the remaining W13 Operations criteria. The preceding automation-search results contain confirmed Fleet offline source and atomic-publication findings. Preserve the user's tab and desktop viewport, protected design documents and working database. Production AI remains disabled.

Backend96426 completed: 45 tests / 747 assertions, no Failed/Errored events, terminal0. All14 postflight checks passed, including exact schema absence. Diagnostic it_113fa576193f48ef.diagnostic.jsonl records the results. Build5768 remains active; no browser pass claim yet.

## First desktop pass and required pagination correction

Build5768 passed in4m41s with app-spBlr-ZW.js / manifest bbc59b4eb0794dad42487f49e22996bd2890eeac55ffee34576a5c14163b3053. Generated types passed. Reviewed runtime3a436060bab146f7, fingerprint3725cc8fe8e8c2660dc9c1b9bc3c6404996ed52b0382db63f475d416d062e529, bootstrap84758 exited0; identity endpoint proved exact checkout/current assets/isolated schema/normal CSRF/array mail/sync queues/SSR off.

Actual in-app tab50 technician: baseline34requests/27errors; header validation filtered26 across25+1 with numbered and Previous URLs retaining both dates and q. Global health remained34/27. Private provider-secret-marker returned0 with accurate no-match guidance; clear restored34 and retained dates. Read ticket and Receipt4 each selected the one read receipt, whose recovery correctly describes another authorized read. Menu/ArrowDown/Enter, Escape returning to row177 and settled Enter reopening receipt4 passed. No completed outcome selected only unclassified legacy receipt36. Normal other-owner login saw2requests/0errors, could not search the technician connector, and found its own two receipts. Requester direct searched Operations URL returned403; own ticket1 conversation recovered successfully.

Browser found a remaining UX defect: a short second page preserved the previous absolute scroll offset and landed below API history. Inline screenshots document it. Corrected pager links to #it-api-request-history, added the history anchor with scroll margin, and use Inertia's existing default anchor scrolling instead of preserveScroll. Existing installed Inertia source confirms anchor reset behavior. Updated URL/visit-option tests and Pint/Prettier passed. Final focused tests/build/browser recheck remains pending; do not count this correction as Verified yet.

Owned tab50 closed; usertab3 preserved, never resized. Cleanup31205 exited0 and independent postflight confirms exact schema/directory absent. No active process/runtime remains. User requested a main-branch checkpoint and push before resuming this final verification.
