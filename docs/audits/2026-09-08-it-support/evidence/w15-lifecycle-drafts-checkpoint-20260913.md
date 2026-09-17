# W15 lifecycle and catalogue drafts — 13 September checkpoint

Recorded 2026-09-13 03:52 UTC. This is bounded evidence, not W15 completion or production acceptance. Full joiner/mover/leaver, publication, tracking, bulk recovery, HR integration, and desktop acceptance remain in progress.

## Canonical catalogue drafts

Catalogue drafts extend the existing `ItTicketDraft` and `ItAttachment` stores. An actor's context retains the original catalogue item, published schema version, and submission UUID. Private form values stay encrypted in the server draft and in browser RAM; browser session storage holds recovery identifiers only. Uploads retain their original catalogue field and audience. Submission transfers the original attachment rows inside the existing catalogue submission transaction, without copying the file or creating another content store.

Migration `2026_09_13_000081_extend_it_draft_context_bindings.php` widens the existing context key from 64 to 128 characters. The original item/version/request binding exceeded the prior width. Its rollback refuses to truncate retained longer bindings. This migration has only been exercised in guarded disposable schemas by this task.

Draft recovery remains controlled by the existing retention feature gate. No production retention policy, IT manager, or eligible cover was invented or configured.

## Backend results and exact cleanup

- Initial three-file group, exec 83289, token `it_8986bfa9a3c14380`: first case errored on the original context key width; terminal 1 / Pest 2. All 14 postflight checks passed and its exact schema was absent. Migration 000081 corrected the width.
- Three-file retry, exec 30956, token `it_f5136c2af21d4401`: 47 passed, 1 failed, 0 errored; terminal 1 / Pest 1. This comprises 44 existing canonical draft/attachment cases and 3 new catalogue draft cases. All 14 postflight checks passed and `oblivion_it_support_test_it_f5136c2af21d4401` was absent. The remaining case found that a replacement item was validated before checking the immutable original item binding, returning 422 instead of the intended 404. `assertOriginalCatalogueFields` now runs before payload validation in both save and candidate validation.
- Affected-only group, exec 40655, token `it_208b52a2d16f4f9f`: 1 passed, 1 failed, 0 errored; terminal 1 / Pest 1. The corrected catalogue metadata/binding case passed. The separate Knowledge migration case failed at its strict decoded JSON comparison, line 24 of `ItKnowledgeMediaMigrationTest.php`; diagnosis and correction were handed to its owner. All 14 postflight checks passed and `oblivion_it_support_test_it_208b52a2d16f4f9f` was absent. Logs: `w15-lifecycle-drafts-affected-20260913.txt` and `it_208b52a2d16f4f9f.diagnostic.jsonl`.

The four new catalogue cases have now passed across the retry and affected run: private metadata/current actor/original item/schema binding; exactly-once transfer and replay of original staged files; immutable upload-field and internal audience denial; and cancellation preventing late submission. These results do not establish acceptance for the wider new provisioning lifecycle.

The preceding retry had a disclosed shared-source change during import: Knowledge migration 000003 and its new test were saved at 03:34:28–30 UTC, after preparation began at 03:34:03 UTC. No migration discovery timestamp exists, so that run cannot establish the exact loading time or execution of the new migration test. The later two-case group explicitly selected it against the frozen checkpoint.

## Frontend verification

The bounded five-file group passed 110 tests after integration: catalogue submission, catalogue request wizard, canonical draft hook, draft RAM store, and draft RAM hook. The added catalogue draft recovery case subsequently passed by itself, covering continued typing during autosave, concealment of private form content after a 401, and identifier-only session storage. Its initial failure was an accessible-name lookup in the test and was corrected to the required field's textbox role/name.

Scoped ESLint passed after correcting the picker callback reference update and the draft upload callback dependency. Targeted PHP syntax/Pint checks passed for the draft integration and the binding-order correction. The last full TypeScript check exposed two newly added test casts, now corrected, plus three pre-existing exact-option errors in `today-retirement.test.tsx`. No subsequent W15 full TypeScript/build/browser result is claimed here.

## Remaining work

- Apply catalogue approval to all original workflow tasks; reuse canonical mover context; retain the published template contract and numeric requester URL.
- Aggregate requester status, approval, progress and safe events across all original tasks. The current first-task projection can imply completion prematurely.
- Finish bulk selection, reviewed per-row commands, receipt recovery, explicit conflicts and partial outcomes.
- Finish canonical HR start-date propagation and source-status guards; keep completed evidence and original dates intact.
- Complete retained template history access checks, unchanged target reauthorization, current staff checks and NZ date handling.
- Add the published launch review and remaining action discoverability; write meaningful lifecycle/command tests; adapt legacy launch fixtures to explicit publication.
- Complete coordinated backend, build and unchanged-size desktop browser acceptance and exact cleanup. No full W15 acceptance has occurred.

The task remains in the original dirty checkout without staging, commits, worktrees, deployment, production/provider actions, or a working-database reset. All 11 protected DESIGN/design_styles hashes match `w15-lifecycle-design-baseline-20260913.json` as of 03:51:52 UTC. W15 application source remains frozen for the shared build/browser handoff; this evidence file does not change served application code.
