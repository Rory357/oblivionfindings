# W07 persisted reply delivery evidence

9 September 2026. Source implemented; focused backend/UI gates passed. Build8 browser verification remains pending. W07, E05, W12 and the release gate are not complete.

## Behavior and boundaries

The existing ticket JSON/Inertia presenter now gives each permitted public reply current leaf-attempt counts from the canonical email outbox. Queued, sending, accepted, delivered, failed, bounced and retry evidence remain distinct; provider acceptance does not claim delivery. A receipted reply with no notification differs from old history with no recorded delivery evidence. Internal notes never acquire a delivery projection. An ordinary participant sees delivery evidence only for their own public submissions; current per-record IT workers can inspect permitted public replies. No recipient address, subject or private provider error is added to the conversation DTO.

The reply identity remains stable when a canonical comment has moved after a merge. Its historical outbox ticket FK is not treated as the comment's current parent. The test models that completed projection; it does not prove the complete W09 merge workflow.

The existing Operations delivery log can be opened directly for an authorized reply. Filtering occurs before pagination, so a reply older than the latest 100 global attempts remains reachable. The existing delivery visibility/retry policy applies, including direct-ID denial. Search and paging retain the reply filter. This bounded change does not complete the broader W12 log/provider lifecycle.

Ticket JSON explicitly returns `Cache-Control: no-store, private`. Conversation controls reuse approved Button/StatusBadge and the application's date formatter. Refresh reuses the host's canonical snapshot; no success toast claims a refresh before confirmation. The drawer uses its existing loading/error recovery. The full page has pending, failure, retry and a bounded cancellation timeout while retaining entered drafts. Current actor/ticket checks reject a mismatched acknowledgement.

## Changed files

- `app/Domain/It/Presenters/ItTicketCommentDeliveryPresenter.php`
- `app/Http/Controllers/It/ItTicketController.php` — comment projection and JSON header only for this slice.
- `app/Http/Controllers/It/ItServiceManagementSetupController.php` — authorized reply filter, pagination and current retry permission.
- `resources/js/components/it/ticket-comment-delivery.tsx` and its focused test.
- `resources/js/components/it/ticket-thread.tsx` and its focused test — public/current-author rendering and pending refresh propagation.
- `resources/js/pages/it/setup/index.tsx` and `setup-workspace.test.tsx` — filter, paging and preserved search.
- `resources/js/hooks/use-ticket-page-refresh.ts` and test, with show/drawer integration — shared with the coordinated watcher UI slice.
- `tests/Feature/It/ItTicketCommentDeliveryTest.php` — seven backend cases.

No migration, provider configuration, notification dispatch or protected design source was changed by this delivery slice.

## Actual verification

1. Initial backend run: **6 passed, 1 failed, 64 assertions, 177.30s**, wrapper exit1. The first case found the existing JSON response had `no-cache, private` instead of required `no-store, private`; later assertions in that case were not reached. Exact schema `it_6a63967dd5c54ac2` was independently absent at wrapper postflight. The controller header was corrected rather than weakening the assertion. Log: `w07-comment-delivery-focused-tests.txt`.
2. Final affected six-file group: **79 passed, 679 assertions**, wrapper exit0, including all seven delivery cases plus watcher/notification/lifecycle/outbox/service-operations regressions. Exact schema `it_d36b927cfb5e430d` absent at postflight. Log: `w07-watcher-lifecycle-final-tests.txt`. This group must not be added to earlier overlapping counts as distinct tests.
3. Final delivery/Thread/Setup UI group after pending refresh propagation: **29 passed, 3 files, 3.93s**, exit0. Log: `w07-comment-delivery-ui-build8-tests.txt`. Includes stale internal/other-author payload concealment, current statuses, safe links, busy refresh, paging and filter/search retention.
4. Coordinated watcher/show/drawer/page-refresh group: **52 passed, 4 files, 6.70s**, exit0; eleven-file ESLint0. Evidence: `w07-watcher-ui-results.md`. Root final Thread/Setup scoped lint also exit0.
5. Full repository TypeScript: **exit0**, `w07-build8-types.txt`. Build8 is running at this checkpoint; no new browser acceptance is inferred from source checks.

All PHP tests used the unique-schema isolation wrapper with fake/array delivery and no external provider. Remaining browser privacy/refresh/log journeys and actual provider outcomes remain open. Watcher queued-recipient suppression and real concurrency are separately recorded in `w07-watcher-lifecycle-design.md`.

## Subsequent review — refresh acceptance reopened

Before Build8 browser acceptance, independent source review found three concrete gaps beyond the passing focused cases: partial Inertia refresh omitted fresh shared `auth` while applying current ticket props, so actor-scoped entered work could retain the previous actor context; a successful write-triggered refresh could be dropped while an older read was pending; and the drawer GET had no bounded timeout. These are confirmed source defects. W02 is correcting scoped actor/access concealment, coalesced follow-up refresh and bounded drawer recovery with regressions. The earlier 52-test/full-TypeScript results predate these fixes. Build8 started before this review and must not be called verified/current after the corrections without recompilation.

Root's Thread/composer correction is now implemented and frozen: **43 tests/2 files/5.77s**, exit0, scoped lint0 (`w07-refresh-concealment-composer-final-tests.txt`). A host's session/access/actor state removes private conversation/composer DOM immediately while preserving the existing audience instances. Confirmed access/actor loss uses the existing intent invalidation and per-audience memory purge; session failure retains concealed work until fresh host proof. A confirmed reply also requests conversation refresh when its saved-draft ACK cannot be matched, retaining the held text/files and review blocker. Host integration/final aggregate checks remain pending at this checkpoint. All ten protected design source hashes still match baseline (`w07-build9-protected-design-check.json`).

Build8 completed exit0 in3m25s, entry `app-MXY-NkXy.js`, manifest SHA256 `665ab0bb724dbbc9cf52a4d8fe6d06d9cda3925d4bf6c88598c87a70a5c52ee9`. It is an intermediate pre-fix build, with no browser acceptance. Build9 will follow coordinated source freeze.

Further pre-build review found that the drawer's dirty guard was tied to the current read payload instead of its retained session draft, and partial refresh omitted independently private `linked_context`, knowledge hints and scoped options. A technician narrowed to participant access could therefore retain old internal task evidence. The refresh is being changed to request the complete canonical page props while preserving component state; the original identity checks/coalescing remain. W02 owns those fixes and tests. Session navigation confirmation must remain available while content is concealed. The first Build9 full-TypeScript pass found only nine unsupported `exact` options in root's Testing Library tests; they were removed (name matching is already exact), scoped formatting/lint0, with final focused/type rerun pending. These review findings and the failed type pass are not counted as successful verification.
