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

## Main checkpoint and resumed verification

At the user's request, all current implementation, tests and audit evidence were committed on main as `5fa7c6a4db50fa1783200abf4d930f72046ef93e` and pushed to origin/main. `git ls-remote` confirmed the exact same remote hash; the working tree was clean immediately after the push. No deployment was performed. The public-repository changed-file credential-pattern scan found only three reviewed source/test expressions, not embedded credentials. Protected design sources were unchanged.

Continued after the push: final anchor UI suite passed45tests and full TypeScript36052 passed. Active guarded API scope regression90248/tokenit_839b6bede8744624, logw13-api-search-anchor-feature.txt; active Vite18782/logw13-api-search-anchor-build.txt. Five final source hashes recorded. Wait these exact handles; no owned browser runtime/tab exists. Final anchor navigation browser evidence remains pending.

## W14 preparation from current sources

`MonitorStateMachine` already uses configured confirmation counts, required duration, thresholds and hysteresis; `MonitoringObservationIngestor` applies maintenance/dependency suppression before emitting availability events. Reuse those owning-module decisions rather than adding an unrelated timer in IT. The public DeviceSignalPublished contract fires after successful signal ingestion whether or not a Control Room alert was created; the current IT listener nevertheless returns without an alert, confirming the missing nonurgent path.

`CreateOrUpdateMonitoringTicket::failureDescription` and `eventEvidence` currently copy raw source payload.message into ticket description/activity. W14 safe diagnostic projection must replace that with bounded safe technical wording while retaining protected canonical source evidence for permitted viewers. The existing MonitoringIncidentEvidenceService requires an alert and exactly two canonical links; direct nonurgent evidence must extend that canonical contract carefully without weakening urgent-alert checks.

Current tests to extend: tests/Feature/It/ItMonitoringTicketIntegrationTest.php and ItIngressContextAccessTest.php. Existing integration tests establish same-active-ticket repeated evidence, a fresh incident after resolution, recovery evidence without auto-close and no automatic conversion of security/healthcare domains. Preserve these semantics and add source/episode/current-access/concurrency proofs. No W14 implementation or verification is claimed by these read-only findings.

Final anchor checks: API scope90248 passed7tests242assertions, no failures/errors, terminal0 and all14postflight/exact schema absent. UI45, full and generated TypeScript passed. Vite18782 passed5m14s; app-DKNHidxV.js / manifestf5bfc0bc81fdc40e7464d7f9c2065d8b0fbc9a63950ec9bd36ee06a6fe3e5826. Final browser bootstrap91247 is active, ownedrun4ca19b68fbc54908/fingerprint38e5ecde5381ffd20d654074a885e9098b8a9911d2d806609f4a2f08b075de57, ApiFixtures only. Browser correction still awaits actual verification.

Final anchor browser tab51, ownedrun4ca19b68fbc54908: actual numbered page2 retained q and dates with1/26 matches and global34/27, but landed at page top. URL fragment was lost. Confirmed root cause in installed Inertia setHashIfSameUrl strict URL comparison versus Symfony Request::normalizeQueryString sorting and RFC3986 encoding. Backend pager now emits that canonical format; real Request normalization regression includes a search containing a space. Browser correction remains unverified. Runtime cleanup71052 and independent postflight passed; owned tab51 closed, user tab3 preserved, no resizing. CI checkpoint database-bootstrap succeeded; full tests failed (including Assurance/Catering/ControlRoom failures), so the checkpoint is not release-verified.

## Canonical URL correction — Implemented and locally Verified

11 September 2026. Scope W13 / E12 API request-history search and pagination only; full W13, automatic listener recovery and release gate remain open.

Changed after the main checkpoint: app/Domain/It/Services/ItApiOperationsPresenter.php now sorts query keys and uses RFC3986 encoding to match Laravel/Symfony Request normalization. Inertia retains the existing request-history fragment only when response and requested URLs match. tests/Feature/It/ItApiOperationsScopeTest.php verifies the exact normalized URL including a spaced search. No frontend source or assets changed in this correction.

- Guarded regression76347, ownedtokenit_d0c3110410754f5e:7 tests/243 assertions, no failed/errored events, terminal0; all14 isolation checks and exact schema absence passed. Pint import-only correction then scoped Pint passed. Prior final UI45/type/build checks remain applicable to the unchanged frontend.
- Browser52, ownedrun5d8e34016fac42b9, normal technician login; identity endpoint proved exact checkout, schema, current manifestf5bfc0bc81fdc40e7464d7f9c2065d8b0fbc9a63950ec9bd36ee06a6fe3e5826, normal CSRF, array mail, sync queue, SSR off.
- Actual header search validation:25/26 on page1; numbered2 ->1/26; Previous ->25/26; Next ->1/26. Both selected dates and q retained; Operations remained selected. Each settled screenshot directly after the pager click showed Request history visible below the fixed header, without any extra click/scroll to mask the result. URL retained #it-api-request-history.
- Clearing search from anchored page2 retained both dates and Operations, removed q/page/fragment, and returned25/34 on page1. Global health remained34 requests/27 errors throughout.
- Spaced search Not applied:25/27 then Next ->2/27. URL retained q=Not%20applied and the fragment; screenshot confirmed visible history landing. This proves both parameter-order and space-encoding cases in the actual browser.
- Browser screenshots and AX evidence are recorded in the current task's tool outputs (owned tab52, numbered2/Previous/Next/spaced-search landing). The earlier tab50 restricted-owner/requester403 and keyboard detail-recovery checks remain applicable; this correction changes only canonical pager URLs.
- Owned tab52 closed; user tab3 preserved; browser never resized. Cleanup11826 terminal0; independent postflight confirmed exact schema and directory absent. All5 source hashes remained unchanged during browser verification and all10 protected design hashes match the original baseline.

No active test/build/import/browser runtime remains. Main checkpoint is pushed; this subsequent focused URL correction and its evidence are local continuation changes. Next: classify applicable IT architecture CI failures without weakening behavior, then continue W14 direct nonurgent intake, durable automatic outcomes and canonical handoffs from w14-source-capability-inventory.md. Full goal is incomplete.
