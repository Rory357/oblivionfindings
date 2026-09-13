# Dedicated IT workspace verification — 13 September 2026

This is evidence for the user's reprioritised page split, structured Knowledge and specialist UI work. It does not close W00–W27 or claim release readiness.

## Implemented scope

- Provisioning, Knowledge and Reports have canonical pages. Service Desk retains ticket overview, queues and request entry points. Legacy links preserve their query context when redirected.
- Problems, Changes and Major Incidents share current register/profile headers and reviewed command wizards. Creation results identify the actual saved record. Forms retain rejected work and require explicit current-record review before adopting another version.
- Knowledge includes typed documents, structured content, canonical relationships, scoped pagination, documentation owners, proposed revisions, immutable encrypted publication history and separate author/reviewer capabilities.
- Knowledge editor/history requests are bound to the originating account. Current permissions and original proposal audiences are rechecked, and inaccessible content is concealed.

## Checks and corrections

The focused frontend selection contains 68 cases in 11 files. All pass across the initial run and affected reruns. Existing tests were migrated to actual page headings, native table/context-menu interactions, accessible links and reviewed publication behavior. New access/conflict/creation tests passed in the first run.

- Initial frontend run: `w21-specialist-knowledge-ui-20260913.log` — 56 passed, 12 failed.
- Five affected files: `w21-specialist-knowledge-ui-fix-20260913.log` — 22 passed, 5 remaining Knowledge fixture failures.
- Final Knowledge table interactions: `w21-knowledge-ui-table-20260913.log` — 13 passed.
- Focused lint: `w21-specialist-knowledge-lint-fix-20260913.log`, `w21-specialist-knowledge-test-lint-20260913.log`, `w21-knowledge-test-lint-final-20260913.log` — exit 0.
- Type checking: `w21-specialist-knowledge-types-fix-20260913.log` — no IT errors; repository exit 2 for three `ByRoleOptions.exact` errors in the independent `today-retirement.test.tsx`. That file was not edited here.
- Explicit PHP formatting: `w21-specialist-knowledge-pint-final-20260913.log` — exit 0. The earlier `--diff` invocation was not a valid explicit-file check and is superseded. Files that the native formatter could not write were formatted through stdin and applied with the file editor. Scoped `git diff --check` then passed.

The initial isolated PHP run used `oblivion_it_support_test_it_93dca169f6234b91`: 38 passed, 8 failed, 13 pending, 719 assertions, 680.18 seconds. The first setup took about 598 seconds. All 14 preflight and postflight checks passed, including independently absent owned schema. See `w21-specialist-knowledge-backend-20260913.log`.

That run found two implementation defects, now fixed:

- A same-second `touch()` could fail to advance the canonical ticket version after specialist profile/link changes. `ItTicketVersionService::advance()` now dirties the version before the model allocates the next persisted version. A frozen-clock regression covers all three workspaces.
- Ad-hoc Knowledge lifecycle routes validated the version before concealing an inaccessible original article/proposal. They now check that access first, while the lifecycle service retains its locked authorization check.

Test fixtures also now provide the required major-incident impact and named primary approver. Existing lifecycle and audience tests retain their original authorization assertions.

The affected eight-file rerun used `oblivion_it_support_test_it_a66cec0e566341c5`. Its diagnostics recorded 55 passed and one failed, with no errored tests. The remaining failure was the historical clock in the major-incident communication test: synthetic staff were not employed at that past date. Both cadence tests now freeze the current hour instead. All 14 preflight/postflight checks passed, including absent schema. Diagnostic mode suppressed Pest's ordinary summary in this run; the evidence is the event log, not an inferred successful exit.

- `w21-specialist-knowledge-backend-fix-20260913.log` — exit 1, exact cleanup confirmed.
- `it_a66cec0e566341c5.diagnostic.jsonl` — 55 `Passed` events, one `Failed` at `ItMajorIncidentManagementTest.php:163`, no fatal/internal error.

The final two cadence tests passed (45 assertions, 200.90 seconds). The guarded runner exited 0, all 14 postflight checks passed and `oblivion_it_support_test_it_67e9d6dd8cac4b26` was absent. See `w21-major-cadence-final-20260913.log`. The focused backend selection therefore passes across the initial run and affected reruns.

## Browser preparation and remaining acceptance

The guarded browser launcher has an opt-in `-WorkspaceFixtures` flag included in its fingerprint and owner/environment checks. It created separate synthetic Knowledge author/reviewer accounts, 27 documents spanning pages, a published runbook with a proposed revision, a canonical service relationship and one of each specialist record. The build and desktop browser pass completed; see `w21-workspace-desktop-browser-20260913.md` for observed journeys and five defects.

The owned runtime was removed and independent postflight confirmed its exact schema and directory absent. The working database remains unmigrated. All five browser corrections are now saved; their focused UI selection passes 27 cases across four files, with scoped lint/Prettier/Pint passing. An additional test-only type error was corrected; final typecheck, backend correction checks and a rebuilt browser recheck are pending. No browser resizing, provider configuration, live communications, production AI, push or deployment.

Later correction/content-on-demand checks and actual browser recheck are complete: see `w21-demand-loading-20260913.md` and `w21-correction-desktop-browser-20260913.md`; these supersede pending correction-check language above. The guarded runtime is fully cleaned with independent postflight. Metadata-only library loading is implemented and tested, while larger-scale acceptance remains open.

Whole-package scope remains open, including the newly explicit full-page document experience, Word/PDF uploads/opening/history and in-workspace diagram creation, canonical target navigation, specialist full lifecycle/visibility/governed communications/simultaneous-worker proof, W15 canonical drafts and joiner/mover/leaver lifecycles, and every remaining W00–W27 release criterion. Package status is1 verified baseline,19 in progress,8 planned. The user requested a fresh session and feature implementation before grouped checks; `../new-session-handoff-20260913.md` defines the next slice and the following W23 vendors/W24 credentials workstream.
