# W13 automation history header search

Status: Implemented query and period preservation; verification in progress. Full W13/E12 and the goal remain incomplete.

## Changes and evidence

- Setup passes validated string `q` into the canonical automation presenter. Search applies before counting, oldest-unfinished selection and pagination, and page URLs retain the search and date period.
- Search uses public schedule labels/keys, typed outcome labels/categories, safe canonical failure messages and exact run IDs. Unknown stored command names, arbitrary result payloads and retained exception text are never searched. Literal wildcard and SQL-looking input cannot broaden results.
- The SQL outcome predicate matches the existing strict projection, including pending/failed/skipped/empty mailbox batches, malformed or incomplete counters, and unfinished or inconsistent legacy executions. Verified-success selection reuses that predicate for mailbox polls.
- Header search/clear preserves the selected automation date period and resets history pagination to its first page.
- Seven source/test paths and hashes: `w13-automation-search-source-hashes.json`.
- Guarded Feature91817/token `it_b52a59d7cafc48e8` passed **83tests / 867assertions**, terminal0 and all14 postflight checks including exact schema absence. Four suites: service Operations, mailbox paging, attachment cleanup Operations and SLA clock. Log `w13-automation-search-feature.txt`; diagnostic JSONL records individual results.
- UI **43tests passed** across Setup workspace, automation history, API diagnostics and Operations. Scoped lint, full incremental types65461 and Pint/Prettier passed. No backend test failures in this slice.
- ACTIVE initial Vite5166, log `w13-automation-search-build.txt`; no active import/runtime/tab/agents. Before browser verification, add explicit searched-result context and distinguish an empty search result from an empty retained history. The build will need refreshing after that UI change; do not claim its current copy is final.

## Next and retained scope

Finish the filtered empty-state wording using a typed `search_query` in the history projection, then focused UI checks/current build and real header journeys. Use the existing opt-in API fixture (32 automation runs, 27 closure runs, four mailbox outcomes and one notification failure) to verify search, page2, date preservation, no matches, clear/recovery, exact run IDs and private-marker exclusion. Preserve usertab3 and never resize the browser; use exact owned runtime cleanup plus independent postflight.

Code inspection also confirmed a separate remaining integration gap: `ItApiOperationsPresenter::operations` accepts viewer/identities/page but no search, and `ItApiOperations` renders that unfiltered history directly. Wire safe search into API request history next, preserving global identity health separately from filtered history counts and preserving search in numbered/previous/next links. Do not search raw request paths, bodies, keys or response payloads. Then continue W13 durable automatic outcomes with W14 source/episode rules.

Working database remains untouched; no migrations, live provider changes, external communications or production AI execution. Protected design documents remain unchanged.

## Final UI context checks (11 September 2026)

Initial build5166 finished successfully (4m33s). Added required `search_query` to the presenter contract and history component. Search captions now explicitly describe matching executions; no-match results offer changing/clearing header search, while the unfiltered empty history retains its distinct wording. Matching unfinished totals are labelled as filtered. The context comes from the server result, so an in-flight input cannot relabel old results.

Final focused UI98720: 44 tests passed, terminal0. Global incremental TypeScript72939 and lint50352 terminal0. PHP syntax check passed. This metadata addition happened after the 83-test backend query run; browser verification will exercise the final response contract. Final nine-path source hashes recorded separately. ACTIVE refreshed build40190, log `w13-automation-search-final-build.txt`; browser still pending.

The next API search slice must preserve aggregate health for all manageable identities independently of filtered receipt history. Add explicit history total/query and server-owned numbered links. Search only public identity names, canonical operation/outcome/category labels, numeric receipt/status fields; never raw paths, keys, hashes, response payloads or hidden record IDs. Verify SQL classification against existing case-sensitive PHP operation projection (including optional leading slashes, trailing newline/extra-path rejection and unknown legacy operations), then retained pages, revoked/foreign identity boundaries, no-match/clear and global-health invariance. Current relevant tests: `tests/Feature/It/ItApiOperationsScopeTest.php`; UI: `resources/js/components/it/it-api-operations.tsx`.

## W14 source inventory correction discovered while the isolated browser imports

Read-only inspection confirms a real supported Fleet offline source beyond telemetry SOS/tamper/battery/geofence: `app/Jobs/DetectFleetOfflineDevices.php` is scheduled in `routes/console.php` and reads persisted `FleetVehicleStateSnapshot.last_seen_at`, using existing `fleet.signals.offline_after_minutes` (default15). It emits `device.offline`. Do not report this source as missing. Its current status update precedes signal/outbox publication without a surrounding transaction or locked snapshot; an emission failure can leave the snapshot offline and excluded from the next sweep. W14 must close that loss window with canonical source/outbox atomicity and concurrency tests, retaining the existing configured threshold rather than inventing one.

`FleetSignalService::emit` persists canonical signal/outbox in a nested transaction, then dispatches immediately; inspect outer-transaction commit ordering when extending it. `DispatchFleetSignalOutbox` marks Control Room delivery sent, but this does not prove IT follow-up completed. Extend durable canonical delivery state rather than treating source delivery as downstream IT success. FleetAutoAlertJob also emits compliance/maintenance/overdue events; those remain Fleet-owned, not blanket IT triggers. Telemetry low-battery already has deterministic event-based idempotency; do not duplicate it.

These are source findings and next implementation requirements, not fixed or verified claims.

## Final desktop browser verification and cleanup

Status: this automation-search slice is **Implemented and locally Verified**. Full W13/E12 and the release gate remain incomplete.

- Final Vite40190 exited0, 5m35s; app-HEYFNr6u.js, manifest `42f637f0295fa12fae9f589f4f95b7162eb2fd0260b96c3e0a16e4311558aa08`. Generated route TypeScript also exited0.
- Owned API-fixture runtime `05e48f770da444ef`, reviewed fingerprint `4c8fbf110d4b0ac20927661a9caf74e6c5c12be0ab780e0c199993f6d5e09a34`; bootstrap77864 exited0. Read-only identity endpoint confirmed exact checkout, owned schema/storage, current manifest, normal CSRF, array mail, synchronous queues and SSR disabled.
- Actual in-app tab49 normal technician login settled before navigation. Header `Close resolved` returned25of27, Next returned2of27 (runs3/1), retaining q and scroll position. Inline screenshots show both pages using approved table patterns.
- Header no-match query reset to page1 and0of0 with matching-specific recovery wording. Private retained `synthetic-scheduler-secret-marker` also returned0of0. `Run 2` returned exactly the notification failure, with safe canonical diagnostic text and permitted delivery recovery link.
- Menu ArrowDown/Enter opened the dialog. Escape restored row647, and a separately settled Enter reopened the same pending mailbox run32. A first scripted recovery click used an index before the reopened dialog settled and accidentally navigated to Tasks; no application defect inferred. Repeated with fresh settled AX state, Review deliveries correctly returned Operations at `#deliveries` and cleared the run query.
- Date period2026-09-11..2026-09-11 returned31runs, excluding yesterday's unfinished run. Header search retained both dates and returned26closure runs; Next showed1of26 with both dates/q intact. Clearing via the real header reset page1 and restored25of31, retaining dates and removing search context.
- `Mailbox scan pending` selected run32 only. Its finished batch correctly remained pending. Technician dialog concealed connection counts and privileged mailbox settings link; screenshot recorded inline. No retry execution was invented.
- Normal sign-out and requester login settled. Direct Operations URL withq returned403; requester recovered to their permitted ticket1 and visible conversation workspace.
- Never resized the browser; usertab3 preserved. Owned tab49 closed. Cleanup1368 exited0; independent postflight exited0 and proved exact schema and owned directory absent. Working Herd environment unchanged. No external provider calls or communications.
- All nine final source hashes still match verified files (`w13-automation-search-verified-source-check.json`); all ten protected design hashes unchanged (`w13-automation-search-design-check.json`).

Next: safe API receipt history search and scoped filtered counts/navigation, then durable automatic-intake outcomes coupled with W14. No active build/test/import/browser runtime/tab/agents remains from this slice.
