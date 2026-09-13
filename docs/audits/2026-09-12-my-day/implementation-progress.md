# My Day implementation evidence

## Follow-up — desktop presentation, person notes and popup consistency

The user's later screenshot corrections and multi-person shift-note request are documented in [desktop-corrections-and-person-notes.md](desktop-corrections-and-person-notes.md). That report supersedes earlier statements about retaining local-device handover drafts, and records the new encrypted person sections, current permission filtering, shared popup navigation, and the final follow-up verification limits. The previous goal completion below describes the earlier approved task/timesheet implementation checkpoint.

Status: implementation, independent verification and user-approved local permission activation complete. The full approved scope is retained. Evidence and verification limits are recorded below.

## Current checkpoint — 13 September, final verification

The sections below retain historical checkpoints. This section supersedes their outstanding-work lists.

Activation completed after the user explicitly approved enabling Add task for all existing support workers. The guarded activation script exited successfully. A fresh read-only check after the reported PC crash confirmed `shifts.tasks.createSelf` is enabled and all three migrations remain present. Before/after hashes prove that other role permissions and individual overrides were unchanged. Evidence: `evidence/worker-task-permission-activation.json`. The browser still serves `app-BBId7isD.js` without captured warnings/errors; the prior session expired and now shows the normal login page. The earlier approval blocker is resolved.

- The approved desktop redesign, optional single-level task steps, real named help requests/acceptance, linked handover follow-ups, finish-shift integration and three-person timesheet review are implemented.
- Real browser verification used synthetic Taylor Demo and Elena Demo accounts in the owned disposable database. It covered private task-draft close/resume, task and step creation, completion, handover follow-up creation, handover acknowledgement without task completion, named help request and recipient-only acceptance, and accepted work remaining open in the original worker's finish review.
- Found and fixed a deeper shift lifecycle check that rejected accepted help, plus missing client-view permission evidence in the canonical attendance transaction when completing multiple people's tasks. The actual combined browser action now saves the handover draft and clocks out with accepted work still open.
- Timesheet browser journeys covered all three financial Site residents (only two clinical profiles are accessible), saved split reopening, an exact four-hour submission across three people, planned hours while running, actual 4.21 hours after clock-out, and preserved payroll reconciliation denial for a materially early finish.
- Browser recovery exposed that reconciliation metadata updates invalidated allocation revision hashes. The final hash now covers actual time, status, provenance and saved allocations; unrelated update timestamps are excluded. True time/allocation changes remain protected. Final recovery verification passed.
- Completed backend run `d5`: **60 passed / 284 assertions**. Subsequent controller-level combined clock-out run `d6`: **11 passed / 56 assertions**. Some tests overlap; these numbers are not additive. Final timesheet recovery suite `d7`: **21 passed / 71 assertions**.
- Final eight UI suites: **32 passed**. Focused ESLint passed. Full TypeScript passed after correcting a test-only query option. Default production build passed in 3m57s and is activated locally, `public/build/assets/app-BBId7isD.js`. No `public/hot` file is present.
- Final browser asset identity: `http://127.0.0.1:8766/build/assets/app-BBId7isD.js`, My Day route, viewport 1961×1216, no document-level horizontal overflow. Earlier natural viewport was approximately 1050px. Exact 1280/1440 sizes were not forced.
- `DESIGN.md` and `design_styles/` have no changes. Unrelated existing IT/Governance work remains untouched by this task. No commit, push, public deployment or real communication was performed.
- Durable quick-task drafts are private encrypted server rows, scoped to user/shift; they recover by version, clear on discard/creation, and inherit existing source deletion. No new retention policy was invented. Existing handover local-device draft behavior was retained.
- **Permission activation complete:** following explicit role-wide user approval, `activate-worker-task-permission.php` enabled only `shifts.tasks.createSelf` for existing support workers. Current shift, approved Site and permitted-person checks still apply. Other permissions and individual deny overrides are unchanged.
- Final recovery suite `d7`: **21 passed / 71 assertions**. Browser confirmed a blocked early-finish submission can then save its corrected split successfully. Helper completion after the original shift ended was verified; the task left the help inbox. An additional UI regression verifies completion closes the modal before offering Undo and reverses the confirmed version (**1 passed**); the final transient toast expired before the browser automation could click it, so final one-click Undo is supported by that UI regression rather than a claimed browser success.
- Final requested URL verification: `https://oblivionfindings.test/my-day` serves `app-BBId7isD.js`, at 1920×889 with no document horizontal overflow and no captured console errors. The synthetic timesheet wizard was visually inspected at 1961×1216. Representative support-worker usability testing was not performed.
- Owned preview process 89972 exited successfully. Read-only `verify-browser-cleanup.php` confirmed `owned_fixture_database_remaining: 0`. The in-app browser was returned to the normal `.test/my-day` URL.
- Implementation, independent verification and approved local activation are complete. The role change was applied only after explicit approval; no workaround of the earlier rejection was used. No required implementation or activation work remains.

## Additional user requirements and current evidence

- User requested optional subtasks under newly added tasks. Implemented a single-level step editor in quick-add, encrypted checklist state, stable step IDs, recovery with the task draft, progress in list/detail, and explicit step completion. Parent completion is rejected until steps are done, including model-based legacy and attendance writes. Finish-shift review now opens task details for steps. Browser verification of the new checklist build remains outstanding.
- User requested an end-to-end timesheet audit, including three people at a site. Found manual allocation only seeded the primary person with no add-person control, the My Day submission bypassed canonical approval safeguards, current-timesheet selection matched date rather than exact shift, time-segment inputs lost NZ/overnight dates, and draft splits had no separate save path.
- Replaced timesheet review with the shared three-step WizardShell: select all supported people, choose an even/manual/period split, review. It includes draft save, explicit close recovery, read-only submitted state, exact two-decimal totals, NZ date/time controls and repeated/nonexistent DST time handling.
- Added TimesheetAllocationService as the financial-roster and allocation validation boundary; My Day writes run through TimesheetApprovalService's payroll mutex, current authorization, canonical provenance, lock checks and reconciliation. Draft allocation rows can be incomplete, but submission requires a complete, balanced split. Hours and clock evidence are not edited by allocation saves. Backend rejects outsiders, duplicate people, mixed methods, overlapping/out-of-window periods and stale revisions. Canonical submit/approval also reject a stored allocation total that no longer matches paid time.
- Exact current-shift selection now covers overlapping calendar dates/overnight work. The canonical shift still requires its primary rostered client; financial allocations can cover all eligible people at its Site. The earlier speculative site-only shift change was reverted after checking the non-null schema. Whole-site tasks remain supported.
- Added migration 000062 (steps, source handover/task links, and help recipient/state). Applied only that migration to the verified local app DB and owned browser fixture DB. Help service and API methods exist but the help UI, acceptance/finish integration and handover source commands are still incomplete.
- `MyDayTaskWorkTest` plus `MyDayTimesheetReviewAuditTest`: **28 passed, 120 assertions**, disposable token `md_review_01a094e0_b1`, 296 seconds. Four UI suites: **18 passed**. Full TypeScript passed before the latest small UI wiring/wording updates. PHP files were stable throughout the backend run.
- Browser fixture login succeeded after disabling factory-provided dummy 2FA only on the synthetic account. Real CUA journey confirmed private draft keep/close/resume and one new canonical task (3 -> 4 tasks). Screenshot found stacked layout at natural 1050px preview, and old cross-module card duplicated the new task list and incorrectly made anytime tasks overdue. Provider correction/removal of that duplication is still outstanding.
- Active owned browser fixture process: exec session 89972, loopback 8766, DB `oblivion_findings_codex_test_myday_browser_md_ui_01a094e0`. Stop with evidence/desktop-browser.stop and verify cleanup. Do not leave it running after verification.

## Implemented so far

### Follow-through and audit update — 13 September

- Optional steps were created in the real isolated browser, saved, checked individually, and blocked parent completion until all steps were finished. The final Undo outcome needs a fresh browser check because the latest page snapshot still shows the task completed.
- Real browser timesheet verification: seven planned hours split across all three eligible Site residents (2.33/2.33/2.34), saved and reopened with the split retained. A closed four-hour shift was submitted successfully with 1.33/1.33/1.34. Current running attendance disables submission and clearly identifies planned draft hours.
- Help requests now name an eligible colleague and reason, require that colleague to accept, and stay in their inbox until completed. Accepted help provides an unfinished-work owner in finish-shift review without marking the work done. Current permission, privacy, employment and Site revocation restore the blocker.
- Linked handover follow-up UI and commands are implemented, including remaining-step copying and existing-link detection after acknowledgement. Positive backend tests currently return 403 and are being diagnosed; this feature is not yet verified complete.
- Improved due-medication priority, visible safety/active-round placement, accepted-help grouping, draft conflict choices, stale optimistic-row cleanup and long-dialog scrolling. Removed the duplicated secondary task card and incorrect overdue treatment of untimed work.
- Latest combined backend run (`md_followthrough_01a094e0_d1`): 51 passed, 4 failed, 211 assertions. Failures: three positive/key handover follow-up assertions and one navigation-badge fixture. Task work, help and timesheet cases passed. Latest seven selected UI suites: 28 passed. Full TypeScript passed before the last minor priority/layout changes; final checks remain required.
- New separate preview build is in progress. Default application build and narrow support-worker permission seeding have not yet been activated.

- Shared Event Horizon `PageHeader`, Home breadcrumb and application shell; Today / Handover / My shift views, scoped search and person/work filters.
- Permanent Add task actions in the header and work section; canonical shift-task creation with explicit person/whole-site context, assigned shift/worker, time and author.
- Existing record locking, authorization against current shift/site/client access, idempotent creation keys, desired-state completion and version-checked reversal. Legacy completion shortcut now completes instead of toggling on retries.
- Private encrypted server drafts with revision checks and explicit resume/discard. Save response recovery keeps the same creation key. Server detects already-created drafts.
- Visible Open task / Open meds controls, dated priority grouping across midnight, any-time work and expandable completed outcomes. Refused/withheld medication outcomes count as recorded.
- Shared end-of-shift form carries task versions; backend rejects stale task changes. Existing clinical blockers remain in force.
- New worker tasks survive stale roster task-list synchronization. Canonical task version increments cover model-based roster/attendance changes.
- Refresh timestamps advance only on success; controller failures are exposed as unavailable section names instead of silently implying healthy empty data.
- Existing shift controls, care-record dialogs, checklists, guided medication rounds, lone-worker safety, first-aid follow-ups, PPE, claims, paperwork and next-shift panel retained.

## Verified to date

- `tests/Feature/MyDayTaskWorkTest.php`: 14 passed, 64 assertions, isolated database token `md_01a094e0_a3`. Earlier failures came from freezing Carbon in worker-local time, which affected model date parsing; the fixture now freezes the same instant in UTC. A read-only PHP diagnostic verified this cause.
- UI tests: quick-add recovery and work priority, 6 passed; timesheet/allocation/next-shift wiring, 9 passed; after explicit draft-resume and end-of-shift wiring, selected suite 10 passed. Some suites overlap; these are not a unique total.
- TypeScript project check passed before the latest recovery/unavailable-state additions. A final check is still required.
- Focused lint passed before the latest additions. A final check is still required.
- Separate preview build succeeded, `public/my-day-review-20260912`; log `evidence/desktop-build-first.txt`. This has not replaced the application's default build.
- Applied only migrations `000060` and `000061` to the verified local application DB (`APP_ENV=local`, host `127.0.0.1`, database `oblivion_findings_codex_test`). No record deletion or full migration/seed reset.

## Remaining work and discovered gaps

1. Real browser ordinary-worker verification using an owned, disposable schema and loopback server. Helpers are in evidence; capture source/asset identity, actions and cleanup.
2. Linked handover follow-up creation and existing-follow-up detection. Keep acknowledged incoming handovers visible; scope fallback to the current site and worker. Current helper only fetches submitted handovers and falls back too broadly for a site-only shift.
3. Real help request / recipient / acceptance semantics on canonical work, with current approved-site and client access. No placeholder help controls have shipped.
4. Explicit supported unfinished-task handover/escalation outcome in the existing finish-shift review; do not mark blocked work complete or weaken medication/incident checks.
5. Broaden medication window from the existing two-hours-back/four-hours-forward query to the actual shift as appropriate, so the full day's work is visible. Verify occurrence identity and access.
6. Check remaining roster/task provider presentation for new per-person/whole-site context and permission checks. Review model/query updates, current-shift overtime timing and all read paths.
7. Make draft conflicts recoverable without trapping the worker; verify draft cleanup after an already-saved command. Assess retention using existing retention conventions.
8. Verify critical safety placement, filtered/empty/error states, long task labels, menus, desktop widths, keyboard focus and ordinary-worker task discovery. Do not claim representative human usability testing.
9. Enable the new create capability through the narrow `MyDayTaskPermissionSeeder` for existing support-worker roles; it appends one capability and does not resync other permissions. Explicit user deny overrides retain priority.
10. Final focused permissions/denial and attendance regression checks, TypeScript, lint, build, browser verification, requirement-by-requirement audit and local activation. Preserve unrelated IT/Governance edits, `DESIGN.md` and `design_styles/`.

## Browser setup

`evidence/desktop-browser-fixture.php` uses `Tests\TestCase::createApplication()` to prepare a disposable schema, creates only synthetic fixtures, starts an owned loopback PHP server at `127.0.0.1:8766`, and waits for the task-specific `evidence/desktop-browser.stop` file. The server uses the separate preview build. On stop, the owned server exits and the harness drops its disposable database. It must not be left running after verification.

The user's Chrome My Day tab was inspected read-only. It shows the previous built UI and Demo Admin, so it is not evidence of the redesigned assets or ordinary-worker behavior.
