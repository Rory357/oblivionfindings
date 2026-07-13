# HR Deferred Audit Backlog Execution Ledger

> Source design: `docs/superpowers/specs/2026-07-11-hr-deferred-audit-backlog-design.md` (`d84cd099`)
>
> Implementation plan: `docs/superpowers/plans/2026-07-11-hr-deferred-audit-backlog.md` (`21298bf9`)
>
> Release baseline: `9eaab3a5` · branch: `codex/hr-deferred-backlog`

Status: ⬜ not started · 🔶 in progress/blocked · ✅ verified complete · ⛔ explicitly closed by approved boundary.

## Baseline setup — 2026-07-11

- Isolated worktree: `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-hr-deferred-backlog`.
- Composer install: exit 0; one pre-existing PSR-4 warning for `TestableQueclinkInstallCommand` in `tests/Unit/Console/QueclinkInstallTest.php`.
- `npm ci`: 637 packages, 0 vulnerabilities.
- First Vitest run: 39 files / 167 tests passed; 5 suites failed only because fresh-worktree Wayfinder route modules were absent.
- `php artisan wayfinder:generate`: generated actions/routes successfully.
- Second Vitest run: **44 files / 184 tests / 0 failed**.
- Client build after route generation: **4,939 modules transformed**, exit 0, `public/build/manifest.json` created, 4m55s.
- Full HR baseline: exit 0, 741 warnings / 4,571 assertions in 1,166.17s. The assertions passed, but Pest reclassified each test because its wrapper attempted `file_get_contents(<worktree>/.env)` and the isolated checkout had no local `.env`; this was a worktree bootstrap warning, not an HR failure.
- Added an ignored test-only `.env` bootstrap file; `phpunit.xml` remains the source of test database/runtime settings. Fresh post-fix probe: `AddEmployeeWizardTest` non-manager case **1 passed / 2 assertions / 0 warnings** in 168.98s.

## Requirements

| ID | Requirement | Status | RED evidence | GREEN evidence | Commit | Notes |
|---|---|---:|---|---|---|---|
| A1 | Canonical organisation-scoped HR audit viewer | ✅ | `CanonicalAuditOrganizationTest`: 3 failed / 1 passed; unknown `organization_id` column and unresolved organisation. Viewer null-label Vitest: 2 failed. | Focused: 4 passed / 28 assertions. Regression bundle: 10 passed / 80 assertions. Viewer Vitest: 2 passed. `npm run types`: exit 0. | `2c4f1f08` | Canonical store is scoped by organisation; legacy table retained only in migration history and unused classes removed. |
| A2 | Payroll export-profile default-demotion audit | ✅ | `CanonicalAuditOrganizationTest`: no `hr.payroll_export_profile.default_changed` row. | Regression bundle: 10 passed / 80 assertions; promoted and demoted profile IDs asserted. | `2c4f1f08` | Store, update, and set-default paths emit one canonical event whenever existing defaults are demoted. |
| E1 | Service CSV formula neutralisation | ✅ | Service test: 2 failed / 4 assertions with raw formula cells. First implementation rerun: 1 failed / 1 passed, exposing shared tab/CR stripping. | Focused: 2 passed / 9 assertions. Regression bundle: 8 passed / 34 assertions. PHP syntax and `git diff --check` clean. | `c36ce490` | Both HR services use the shared guard; tab/CR root defect fixed while numeric values remain unchanged. |
| R1 | HR calendar event archive/restore | ✅ | Lifecycle feature: root became null after removal; copy contract lacked archive/history/restore language. Backend RED 2 failed / 5 assertions; copy RED 2 failed / 2 assertions. | Focused lifecycle: 2 passed / 42 assertions. Final calendar/salary/copy bundle: 26 passed / 217 assertions. Types, focused ESLint, PHP syntax, and diff checks clean. | `145f5e5f` | Active feed, hero, iCal, and reminders exclude archives; manager history restores retained evidence; archived mutations return 409. |
| R2 | Salary-band deactivate/reactivate | ✅ | Lifecycle feature had no deactivation route or lifecycle fields; copy contract lacked deactivate/reactivate explanation. | Final calendar/salary/copy bundle: 26 passed / 217 assertions; direct historical placement remains resolvable while active selectors exclude inactive bands. | `145f5e5f` | Manager-only lifecycle actions are audited through the existing model trait and available from existing row actions. |
| O1 | Explicit exit-interview ↔ offboarding-task identity | ✅ | Focused RED: explicit link stayed null; the schema had no `exit_interview_id`; all 6 slice tests failed overall. | Focused: 6 passed / 23 assertions. Regression bundle: 32 passed / 236 assertions. | `f0fc8c5d` | Unique relationship plus semantic workflow key; legacy title matching exists only in deterministic migration backfill. |
| O2 | Future exit-interview schedule notifications | ✅ | Focused RED: expected future schedule notification count 1, actual 0. | Focused future/past/no-op/material-reschedule cases pass; regression bundle 32/236. | `f0fc8c5d` | Future schedules and material reschedules notify the selected interviewer; post-hoc records do not. |
| O3 | Late-issued asset task reconciliation | ✅ | Focused RED: late HR asset assignment produced 0 stamped return tasks. | Focused creation/idempotency/returned/reassigned cases pass; regression bundle 32/236. | `f0fc8c5d` | Assignment identity stamp prevents repeat writes; open-checklist reconciliation notifies the resolved owner. |
| O4 | Deterministic offboarding assignee fallback | ✅ | Focused RED: payroll task owner was null; invalid initiating actor raised a database error instead of pre-write validation. | Focused role/manager/actor and zero-partial-write cases pass; regression bundle 32/236. | `f0fc8c5d` | All required tasks resolve before checklist insertion or fail with `ValidationException`. |
| X1 | Stable Timesheet ↔ HrTimeEntry approval identity | ✅ | Corrected RED: 3 failed / 1 passed; linked approval replaced the direct link with a second row, and conflict/foreign-link cases did not throw. | Focused X1/X2: 5 passed / 18 assertions. Planned regression bundle: 12 passed / 95 assertions. | `c4003c2e` | Timesheet row is the concurrency gate; linked row wins only when identity is valid and non-conflicting, otherwise the service fails closed. |
| X2 | Benefit plan/profile organisation invariant | ✅ | RED: cross-organisation service call did not throw and entered the write/notification path. | Focused mismatch case passes with zero enrolments and zero notifications; regression bundle 12/95. | `c4003c2e` | Guard executes before `DB::transaction`, independent of controller filtering. |
| Q1 | Manager force-expiry and intentional offer revival | ✅ | Focused RED: expiry route/reason/immutability/revival cases all failed; 8 of 9 slice cases failed overall. | Focused: 9 passed / 52 assertions. Full recruitment regression: 66 passed / 471 assertions. Types and focused ESLint pass. | `9ef2e576` | Force expiry clears the live token and records actor/reason; resend always rotates the token and clears explicit expiry attribution. |
| Q2 | Interview scorecard quorum with audited override | ✅ | RED: zero-interviewer, missing-score, and empty-override cases all advanced; full quorum already passed. | Focused quorum/override cases pass; full recruitment regression 66/471. | `9ef2e576` | Guard applies once when leaving the interview workflow; direct-offer and post-offer stages are not re-checked. Override reason and missing interviewer IDs are canonically audited in the advance transaction. |
| W1 | HR-branded employee reinvite with active-user guard | ✅ | Focused RED: branded notification was not sent; active and cross-tenant accounts were not blocked. | Focused W1/W2: 5 passed / 33 assertions. Regression bundle: 10 passed / 71 assertions. | `1371858d` | Uses the secure password-broker token with HR mail/database presentation; active and foreign-tenant accounts fail before token creation. |
| W2 | Tenant-scoped leave approval-chain administration | ✅ | Focused RED: all leave-route create/update/reorder/activation endpoints were absent. | Focused administration cases pass; generic-chain and native leave-action regressions pass in 10/71 bundle. Types and focused ESLint pass. | `1371858d` | Administers the existing per-employee approval levels; generic workflow chains remain separate and the native leave engine is unchanged. |
| W3 | Supervision taxonomy, employee note notice, dead-code removal | ✅ | RED: hub lacked `sessionTypes`; visible-note notification count was 0. | Focused plus planned regression: 53 passed / 260 assertions. Types and focused ESLint pass. | `3f2cd2e5` | Canonical model taxonomy drives the wizard and validation; visible creates notify once, private creates do not; the 441-line orphan dialog was removed after a zero-import scan. |
| W4 | OKR completion notification transition | ✅ | Corrected isolated RED: 1 failed / 2 assertions; completion notification count was 0 after an authorised 90→100 transition. | Focused transition and repeat-at-100 contract pass; planned regression bundle 53/260. | `3f2cd2e5` | Existing `HrNotificationService` runs only after a non-complete→complete transition and after service transactions return. |
| W5 | Announcement-reply author notification | ✅ | RED: same-tenant announcement author notification count was 0. | Same-tenant author and self-reply cases pass; announcement regression passes in 53/260 bundle. | `3f2cd2e5` | The actual `/hr/feed/reply` seam notifies the organisation-matched author, skips self, and retains the existing subject tenant guard. |
| W6 | Worker vetting/licence expiry nudges | ✅ | RED: dedicated command did not exist. | Both source types notify and stamp once; a second command run sends nothing; compliance regression passes in 53/260 bundle. | `3f2cd2e5` | Row locks reserve persisted stamps before post-commit notification; delivery failures clear the stamp for retry. Scheduled daily at 08:05. |
| W7 | Shift licence-class and endorsement requirements | ✅ | Backend RED: 8 failed / 0 assertions because the rule was absent and endorsement arrays had no cast. UI RED: 1 failed / 6 passed because requirement controls were absent. | Current focused: 9 passed / 19 assertions. Planned backend regression: 20 passed / 44 assertions, plus direct-publish 1/3. Wizard Vitest: 8 passed. Types and focused ESLint pass. | `71fcfd32` | Optional exact class/endorsement requirements use the existing tenant-scoped HR driver record; assignment, calendar writes, recurring sampling, roster publish, and direct publish enforce hard blocks. Ordinary create payloads omit the fields; edit can clear them. |
| U1 | Payroll hero and responsive action-table pattern | ✅ | Initial contract RED: specialised hero and desktop/mobile markers absent. Context-menu follow-up RED: 1 failed / 5 passed because the shared hook was absent. | Focused 6/6; full frontend 195/195; types, focused ESLint, client and SSR builds pass. | `d86c3a62` | Server counts/deep links retained; runs and payslips expose desktop tables, mobile cards, direct buttons, and shared HR row context menus without route or lifecycle changes. |
| U2 | Training hero kit and token discipline | ✅ | Initial contract RED: specialised hero absent and raw `oklch()` fallbacks remained. | Focused 6/6; full frontend 195/195; token scan, types, ESLint, client and SSR builds pass. | `d86c3a62` | HR hero kit now owns the same six counts, four actions, and mandatory-current/overdue context; filters and tab content are unchanged. |
| U3 | Specialised feedback hero | ✅ | Initial contract RED: specialised hero absent. | Focused 6/6; full frontend 195/195; types, focused ESLint, client and SSR builds pass. | `d86c3a62` | Server-derived total/pending/completed/overdue counts drive deep-linked stats; existing templates/request actions and status filter stay canonical. |
| U4 | Neutral shared `TextPromptDialog` ownership | ✅ | Initial contract RED: neutral component path absent and both consumers imported recruitment ownership. | Focused 6/6; zero old-owner imports; full frontend 195/195; builds pass. | `d86c3a62` | File moved with API and behavior unchanged; recruitment and documents now import the neutral HR component. |
| U5 | Time soft refresh updates entries and preserves filters | ✅ | Initial contract RED: partial reload omitted `entries`. | Focused 6/6; full frontend 195/195; types, focused ESLint, client and SSR builds pass. | `d86c3a62` | Existing 30-second overview reload now includes the server-filtered entries prop and explicitly preserves state/scroll; no selection reset was introduced. |
| C1 | Calendar table guards, permission defence, team audiences | ✅ | Pre-change source proof: no `Schema::hasTable` guard existed; the route group had no permission middleware; controller/wizard audience unions omitted `team`. | Focused: 10 passed / 36 assertions. Planned calendar regression: 24 passed / 90 assertions. Types and focused ESLint pass. | `89d16df2` | Seven optional source tables fail soft; all calendar routes carry the view gate; team values come only from active tenant profiles and visibility requires an active matching profile (creator retains access). |
| L1 | Classify every historical open/deferred observation | ✅ | Historical source-ledger observations rechecked against the release baseline and the 26 implemented requirement IDs. | The append-only disposition matrix maps every observation, and Run 14 supplies the missing production Chrome matrix, zero-error network/console evidence, exact cleanup, and scheduled-interval observation. | release app `46acdd31` | Classification and the deferred browser proof are complete. |

## Approved closed boundaries retained throughout

- D-7: HR staff performance and governance performance remain separate.
- D-10: confidential HR wellbeing remains outside Control Room.
- D-11: procedure acknowledgements remain H&S-owned/read-only in HR.
- C4 child/template/reference and moderation/privacy deletion boundary remains intact.
- `setActive`, declined-leave resubmission, break warn-not-block, ambient reactions, and accepted full-page escape-hatch forms remain unchanged.

## Run log

### Run 1 — planning and isolated setup

Design approved, written, self-reviewed, and committed. Implementation plan covers all 27 requirement IDs across 13 tasks and 70 checkpoints; placeholder and coverage scans are clean. Fresh-worktree setup exposed only generated-route, build-manifest, and ignored local `.env` prerequisites, not application regressions. The full HR baseline exited zero with 4,571 assertions; a fresh post-bootstrap probe confirmed the `.env` warning is eliminated. Task 1 is complete.

### Run 2 — A1/A2 canonical audit store

RED was deliberate and requirement-specific: 3 backend failures exposed the absent organisation column, unresolved write scope, and missing payroll demotion event; the neutral system-event assertion already passed. A separate 2-test Vitest RED captured the canonical viewer's null-auditable label edge. GREEN evidence is 4/4 focused backend tests (28 assertions), 10/10 planned backend regression tests (80 assertions), 2/2 focused viewer tests, clean PHP syntax, `npm run types` exit 0, `git diff --check` clean, and no remaining application reference to `HrAuditLog` or the retired HR `AuditService`. Implementation commit: `2c4f1f08`.

### Run 3 — E1 service CSV formula neutralisation

RED proved `EmployeeImportExportService` and `ReportBuilderService` exported `=`, `+`, `-`, `@`, tab, and carriage-return prefixes raw. Reusing the shared trait closed the four formula-character cases; the next RED isolated a pre-existing trait defect where `ltrim()` erased tab/CR before inspection. The root fix preserves safe visual spaces, checks tab/CR before numeric handling, and still leaves `-42.50`, `123`, hours, bank values, and flags unchanged. GREEN: focused 2/2 (9 assertions); planned service/payroll/People bundle 8/8 (34 assertions).

CSV writer audit: `EmployeeImportExportService` and `ReportBuilderService` were updated in this slice; `PayrollExportService` and `PayrollJournalService` already use the shared sanitizer and retain dedicated payroll coverage; Asset, Compensation, Compliance, Goal, HR Documents CSV, Onboarding, Performance, Recruitment, Time, Training, and Wellbeing controllers inherit `putCsv()` from the base controller; Announcement, Leave, and Leave Report data rows retain their existing local equivalent guards (the Leave path has feature coverage), while their raw header/title writes contain only server-controlled labels. Import/Export and payroll download controllers only stream already-sanitized service output. No raw `fputcsv`/`streamDownload` writer remains in `app/Domain/Hr`. Implementation commit: `c36ce490`.

### Run 4 — R1/R2 retained lifecycles

Backend RED proved calendar removal deleted the root/evidence graph and salary bands had no lifecycle endpoint. A separate zero-database copy RED proved the live surfaces still promised permanent deletion and offered no deactivation/restore language. The additive migrations introduce archive/deactivation attribution without a purge path. Calendar archive retains attendees, reminders, attachments, and private files; active aggregator, hero, iCal, and reminder reads exclude archives; manager history exposes restore; non-restore mutations on archived events return 409. Salary bands remain listable for history while active selectors, hero counts, pay-review choices, and role lookup exclude inactive rows; direct placement remains resolvable and reactivation clears attribution.

Final GREEN: focused lifecycle 2/2 (42 assertions); calendar feed + CRUD + salary placement + copy bundle 26/26 (217 assertions); copy contract 2/2 (11 assertions); `npm run types` exit 0; focused ESLint zero warnings; touched PHP syntax and `git diff --check` clean. UI-pattern guidance kept the reversible actions in existing dialogs/row menus, used status text plus icon, shared calendar date presentation, and a 44px restore target. Implementation commit: `145f5e5f`.

### Run 5 — O1–O4 durable offboarding seams

RED was six requirement-level failures: there was no task/interview foreign key, future scheduling sent no interviewer notification, late-issued HR assets created no recovery work, role gaps left required tasks unowned, and an invalid fallback actor surfaced as a database exception rather than validation. The new nullable unique link and semantic `workflow_key` make all new checklist and standalone flows independent of mutable task titles; title matching is confined to a deterministic migration-only legacy backfill. Scheduling notifies only for a future date and repeats only after a date/interviewer change. Late HR asset assignments reconcile one assignment-stamped return task on an open checklist. Required task ownership is resolved role → employee manager → initiating actor before any checklist write.

Final GREEN: focused 6/6 (23 assertions); planned offboarding/workflow/interview/wizard/asset/immutability regression bundle 32/32 (236 assertions); `npm run types` exit 0; focused ESLint zero warnings; Pint and `git diff --check` clean. Implementation commit: `f0fc8c5d`.

### Run 6 — X1/X2 cross-module identity and tenancy

After correcting an invalid factory setup that paired the test worker with another worker's shift, X1 RED was 3 failures / 1 pass: the existing synchroniser ignored `timesheets.hr_time_entry_id`, created a second row, silently rewrote conflicting links, and accepted a linked row from another worker/organisation. X2 RED proved `BenefitsService` accepted a foreign plan and reached its write/notification path. The synchroniser now locks the timesheet as its concurrency gate, locks and validates direct/canonical candidates, updates a valid linked row in place, reuses a sole canonical source row, and fails closed on missing, duplicate, conflicting, worker-mismatched, tenant-mismatched, or foreign-source identity. Benefit tenancy is checked before any transaction.

Final GREEN: focused 5/5 (18 assertions); `ShiftPayrollBackboneIntegrationTest` plus `BenefitsEnrollmentTest` regression bundle 12/12 (95 assertions); Pint, PHP syntax, and `git diff --check` clean. Implementation commit: `c4003c2e`.

### Run 7 — Q1/Q2 recruitment expiry and quorum

RED was 8 failures / 1 pass: there was no manager expiry route or reason contract, responded offers had no explicit immutable expiry action, resend reused the old token, and interview advancement ignored zero interviewers, missing scores, and blank override reasons. The additive offer fields retain expiry actor/reason. Force expiry immediately clears the token and audits the decision; resend is the sole revival path, always rotates the token, and clears expiry/reminder attribution. The latest completed interview's unique assigned panel must all have submitted scorecards when a candidate leaves the interview workflow. Managers receive a reason-required prompt only after the normal advance reports incomplete quorum; the successful override and missing IDs are written canonically in the same transaction.

The first regression run showed the quorum check was too broad because it re-ran during direct-offer and post-offer transitions. It was narrowed to the actual interview boundary. One old hub test also attempted to send from `interview_completed` without an interview; its fixture now uses the real `offer_pending` send state.

Final GREEN: focused 9/9 (52 assertions); `RecruitmentOfferLifecycleTest` + complete `RecruitmentHubTest` bundle 66/66 (471 assertions); `npm run types` exit 0; focused ESLint zero warnings; Pint, PHP syntax, and `git diff --check` clean. Implementation commit: `9ef2e576`.

### Run 8 — W1/W2 employee invite and native leave routing

RED was 5/5 failures: reinvite still used the generic reset notification, active accounts and foreign tenants were accepted, and no native leave-route administration endpoints existed. Reinvite now creates the same secure password-broker token but sends an HR-branded mail/database notification with `type=employee_invite` and the reset action URL; active and cross-tenant accounts fail before token creation. The approvals page now exposes `HrLeaveApprovalChain` separately from generic workflow chains, limits users and rows to the resolved tenant, and supports create, edit, transactional reorder, activate/deactivate, and remove actions. Reorder uses temporary levels to respect the existing tenant/user/level uniqueness constraint.

Final GREEN: focused 5/5 (33 assertions); focused plus `ApprovalChainTenantTest` and native `LeaveBulkApprovalActionsTest` 10/10 (71 assertions); `npm run types` exit 0; focused ESLint zero warnings; Pint, PHP syntax, and `git diff --check` clean. Implementation commit: `1371858d`.

### Run 9 — W3–W6 deferred notification loops and supervision taxonomy

The initial five-contract RED reported 5 failures / 15 assertions: the active performance hub did not expose the session taxonomy, visible supervision notes sent no employee notification, announcement replies sent no author notification, and the dedicated worker-compliance reminder command was absent. Its first W4 request used an under-permissioned actor, so that evidence was discarded; a corrected authorised 90→100 run with only the completion hook removed produced the requirement-specific RED of 1 failure / 2 assertions because `GoalCompletedNotification` was sent zero times.

The canonical session taxonomy now lives on `HrSupervisionNote`, feeds the active performance-hub wizard, and validates both create and update requests. A visible new note notifies its employee; a private note does not. The unused 441-line `supervision-dialog.tsx` had no imports and was removed. Goal completion calls the existing notification service after direct/service transactions return and only on a non-complete→complete transition, so repeated 100% updates are silent. Announcement replies are written by `FeedController`, not `AnnouncementController`; that actual seam now notifies the organisation-matched author and excludes self-replies. A scheduled 08:05 worker-compliance command reserves durable vetting/licence stamps under row locks, sends after commit, clears a reservation on delivery failure, and skips stamped rows on rerun.

Final GREEN: focused 5/5 (33 assertions); planned `DeferredNotificationContractsTest` + supervision + goals/OKR + announcement + compliance bundle **53/53 (260 assertions)** in 202.47s; `npm run types` exit 0; focused ESLint zero warnings; Pint, PHP syntax, orphan-import scan, and `git diff --check` clean. Implementation commit: `3f2cd2e5`.

### Run 10 — W7 shift licence requirements

Backend RED was 8/8 failures: `RequiredDriverLicenceRule` did not exist and the shift model could not persist endorsement arrays. The UI RED was 1 failure / 6 passes because the create/edit wizard exposed no licence requirement controls. The additive schema stores an optional class and endorsement list on shifts and recurring series. The rule is registered in the shared eligibility stack, resolves only the worker's existing HR driver record for the shift organisation, accepts both current numeric class values and retained `Class N` values, and hard-blocks absent approval, expiry before the shift, class mismatch, and missing endorsements. No second driver store was introduced.

The requirements round-trip through one-off create/edit, eligibility preview, calendar create/edit, recurring-series sampling/generation, future-series changes, duplication, promotion, manager quick detail, and frontline roster detail. Assigned shift creation/assignment, calendar writes, roster-period validation, proposed-roster validation, and direct draft publishing cannot bypass a block. Ordinary new shifts omit both request keys; edit mode submits empty values so a manager can intentionally remove an old requirement.

Final GREEN: current focused **9/9 (19 assertions)**; planned driver + publish + recurring sampler regression bundle **20/20 (44 assertions)**, plus the added direct-publish contract **1/1 (3 assertions)**; focused wizard Vitest **8/8**; `npm run types` exit 0; focused ESLint zero warnings; Pint, PHP syntax, and `git diff --check` clean. Implementation commit: `71fcfd32`.

### Run 11 — C1 calendar resilience and team audiences

The test contracts were written before production edits, but the isolated database's ~147-second bootstrap completed after implementation had already begun, so that process did not preserve a clean pre-change failure count. The authoritative RED evidence is therefore the inspected source state: `HrCalendarAggregator` had zero table guards, the entire HR calendar route group lacked permission middleware, and both controller validation and the wizard's audience union excluded the schema-supported `team` type. This limitation is recorded rather than relabelling the later harness-only mock failures as product RED evidence.

Each optional calendar source now checks its root table before querying: events, leave/holidays, shifts/coverage, three independent compliance sources, and employee milestones. The route group carries `permission:hr.calendar.view` while controller manage, RSVP, tenant, archive, and attachment checks remain. Team selection is populated from distinct active `HrEmployeeProfile.team` values, validated against an active profile in the event tenant, stored in the existing attendee `audience_ref`, returned through feed/edit payloads, and visible only to the creator or an active same-tenant profile with the matching team.

Final GREEN: focused **10/10 (36 assertions)**; focused plus `HrCalendarFeedTest` and `HrCalendarEventCrudTest` **24/24 (90 assertions)** in 180.23s; `npm run types` exit 0; focused ESLint zero warnings; Pint, PHP syntax, and `git diff --check` clean. Implementation commit: `89d16df2`.

### Run 12 — U1–U5 named HR UI completion

The initial source/UI contract failed all six cases: the payroll, training, and feedback specialised heroes did not exist; payroll had no responsive desktop/mobile contract; the shared prompt dialog remained recruitment-owned; and time refresh omitted the visible entries prop. After responsive payroll work, a separate context-menu assertion failed 1/6 and proved that action parity alone had not yet wired the established HR row context hook.

Payroll runs and payslips now use one specialised hero backed by the existing server counts and deep links, retain all direct actions, render desktop tables and mobile cards, and attach the shared HR context menu to both forms. Training moved from its bespoke gradient/raw-colour hero to `HrHero` while preserving all counts, actions, filters, tabs, and compliance context. Feedback uses a specialised deep-linked hero derived from the existing server stats. `TextPromptDialog` moved to neutral HR ownership without an API change. The time overview partial reload now requests `entries` alongside KPIs and explicitly preserves state and scroll, so the server's active filters remain authoritative.

Final GREEN: focused **6/6**; complete frontend **46 files / 195 tests** in 17.83s; `npm run types` exit 0; focused ESLint zero warnings; old-owner import and raw-training-colour scans clean; client build **2m 42s**; SSR build **36.86s**; `git diff --check` clean. Implementation commit: `d86c3a62`.

### Run 13 — L1 classification and terminal release gate

Crash recovery restored the exact unmerged branch and recovered the omitted migration evidence from the original task: all seven additive migrations were applied as one test batch, rolled back together, then reapplied; retained-row counts remained stable. The historical audit and closeout ledgers were re-read in full, and every open/deferred/partial observation is classified below as implemented, stale, already closed by an earlier release, or an approved boundary.

Fresh terminal verification on 12 July 2026:

- complete HR suite: **798 passed / 4,891 assertions / 0 failed / 1,426.48s**;
- Timesheets, Rostering and shift-eligibility bundle: **58 passed / 292 assertions / 0 failed / 178.77s**;
- complete frontend: **46 files / 195 tests / 0 failed / 38.20s**;
- PHP syntax: **76 changed PHP files clean**; two deliberately deleted legacy audit files skipped;
- Pint: all current changed PHP files passed after one import-only normalisation in `RecruitmentHubTest.php`;
- TypeScript `tsc --noEmit`: **exit 0**;
- ESLint: **29 changed TypeScript files / zero warnings / exit 0**;
- client production build: **4,942 modules transformed / exit 0 / 3m 23s**;
- SSR production build: **1,594 modules transformed / exit 0 / 39.35s**;
- `git diff --check main...HEAD`: **exit 0**.

Browser proof is still missing. The earlier pass was interrupted by the Codex crash, and no browser was opened during this terminal-only recovery. Therefore the implementation and terminal release gates are green, the historical classification is complete, but L1 and the new release closeout remain **Partial** rather than being overstated as complete.

### Run 14 — production Chrome matrix and L1 closure

The missing browser boundary was completed on 13 July 2026 against clean production application SHA `46acdd312e432c93cf09e8264d6d2dcb35f13637`. Demo Admin exercised the canonical audit, Calendar, salary-band, offboarding, recruitment, leave-chain, manager payroll, Training, Feedback, Supervision, Timekeeping, Shifts, and shift-licence requirement surfaces; a marker-scoped payroll worker exercised self-service payslips. Calendar archive/restore retained attendees, reminders and attachments; offboarding recorded one interview and proved completion/reopen/cancel; offer expiry invalidated the old token and resend rotated it under the safe `log` mailer; leave-chain add/edit/reorder/active/remove and scorecard override produced the expected server state. The 30-second Timekeeping refresh preserved the selected Overview tab and URL. Shift licence class and endorsement controls were visibly present in the create wizard, which was cancelled without saving.

Every grouped Chrome row had an empty console-error list. Nginx recorded zero `>=400` responses for the Chrome source during the acceptance hour. Exact marker `CODEX-LIVE-HR-GAPS-20260713T0400-LIVE` was removed with all marker counts zero, the private attachment absent, and the pre-fixture production baseline restored. The cleanup helper was hardened for audit rows belonging to a leave route deleted before teardown; its targeted regression passed in both configured Chromium projects (**2/2**). `AutoEscalateControlRoomQueues` remained registered at `*/10`, and the post-deploy log window across multiple ordinary intervals contained zero production errors and zero prior failure signatures. L1 is now verified complete.

## L1 historical-observation disposition — 2026-07-12

> This matrix is append-only. It does not rewrite historical observations or pretend redesign ideas were implemented. Run 14 appends the evidence that resolves the historical browser-proof row and closes `L1`.

| Historical source | Observation(s) | Final disposition |
|---|---|---|
| Audit §5 row 2 — People | Generic reset-mail reinvite; active-user reinvite guard | **Implemented — W1**, `1371858d`. |
| Audit §5 row 2 — People | `setActive` is HR visibility only; client MIME metadata; full-page profile detail | **Approved closed boundaries.** Offboarding owns login revocation; private files retain extension allowlists and authorised downloads; full-page detail remains accepted. |
| Audit §5 row 2 — People | Carry-over profile-update notification idea | **Approved closed scope.** It never defined a waiting party or transition contract and was not adopted as a requirement; existing audit/success feedback remains. No implementation claim. |
| Audit §5 row 3 — Recruitment | Force-expire lapsed offers; scorecard quorum | **Implemented — Q1/Q2**, `9ef2e576`. |
| Audit §5 row 3 / S14 / D-1 | Real approvals absent from the chain inbox | **Implemented before this programme — closeout C8**, `483bb709`: four native queues are surfaced with owning links; no workflow migration. |
| Audit §5 row 4 — Onboarding | Checklist hard delete | **Implemented before this programme — closeout C4**, `cbef5fed`: checklist archive retains tasks. |
| Audit §5 row 4 — Onboarding | Task/template/email child deletion; quiet per-task completion; transient `pending` recomputation | **Approved closed boundaries.** Child/reference deletion remains inside C4; aggregate checklist completion remains the notification point; recomputation is harmless. |
| Audit §5 row 5 — Exit interviews | Store-once immutability | **Implemented before this programme — closeout C5**, `638b55eb`: submitted answers lock and append-only addenda remain available. |
| Audit §5 row 5 — Offboarding | Title-matched interview link; future-interview notice; late-issued assets; missing-role assignees | **Implemented — O1–O4**, `f0fc8c5d`. |
| Audit §5 row 5 — Offboarding UI | Generic detail hero and minimal empty states | **Approved closed UI boundary.** Accepted detail/workspace surface; no workflow gap. |
| Audit §5 row 6 — Calendar | “Unmerged” calendar rebuild branch | **Stale.** The branch was already an ancestor of release `main`; no duplicate redesign work. |
| Audit §5 row 6 — Calendar | Missing table guards; route-level view defence; dormant team audience | **Implemented — C1**, `89d16df2`. |
| Audit §5 row 7 — Leave | No `HrLeaveApprovalChain` administration | **Implemented — W2**, `1371858d`. |
| Audit §5 row 7 — Leave | `react-hooks/refs` failure | **Stale/already fixed before release**, `c31a7b01`; current source no longer contains the reported `reqRef` pattern. |
| Audit §5 row 7 — Leave | CTA-light empty states; full-page detail; no declined-request appeal | **Approved closed boundaries.** Full-page escape hatch remains; declined leave is resubmitted as a new request. |
| Audit §5 row 8 — Time | Timesheet approval could fork/replace the linked HR entry | **Implemented — X1**, `c4003c2e`. |
| Audit §5 row 8 — Time | Soft refresh omitted entries | **Implemented — U5**, `d86c3a62`. |
| Audit §5 row 8 — Time | Permissive pay types; break warn-not-block | **Approved closed boundaries.** Integration-compatible validation and non-trapping clock-out behavior remain. |
| Audit §5 row 9 — Compensation | Salary-band retention; service-level benefit tenancy | **Implemented — R2**, `145f5e5f`; **X2**, `c4003c2e`. |
| Audit §5 row 9 — Compensation | Multi-item full-page expense form and no browser draft recovery | **Approved closed boundary.** The dialog remains the primary guided flow; the full page remains the escape hatch. |
| Audit §5 row 9 — Compensation | Expense approvals outside the generic spine | **Implemented before this programme — closeout C8**, `483bb709`, by read-only native-queue federation. |
| Audit §5 row 10 — Payroll | Generic hero and hand-built desktop-only action tables | **Implemented — U1**, `d86c3a62`. |
| Audit §5 row 10 / D-6 | Payroll CSV formula injection | **Implemented before this programme — closeout C1**, `8f717805`. |
| Audit §5 row 10 | Audit-invisible default demotion | **Implemented — A2**, `2c4f1f08`. |
| Audit §5 row 11 — Compliance | `StaffBackgroundCheck` hard delete | **Stale/already retained.** The model uses `SoftDeletes`; closeout C4’s classification is recorded at `cbef5fed`. |
| Audit §5 row 11 / S3 | Worker expiry nudges; shift licence class/endorsement gate | **Implemented — W6**, `3f2cd2e5`; **W7**, `71fcfd32`. |
| Audit §5 row 12 — Documents | Hard-delete root documents; recruitment-owned prompt dialog | **Implemented before this programme — closeout C4**, `cbef5fed`; **implemented — U4**, `d86c3a62`. |
| Audit Run 0 O-6 / Run 12 | Missing policy re-attestation duplicate guard | **Stale.** Same-version duplicate attestation was already rejected by the version-scoped store contract. |
| Audit §5 row 13 — Performance | Session taxonomy absent from wizard; orphan dialog; visible-note notice | **Implemented — W3**, `3f2cd2e5`. |
| Audit §5 row 13 / D-7 | Merge or cross-surface HR and governance reviews | **Approved no-action boundary**, `97ff4af0`: two live domains remain separate. |
| Audit §5 row 14 — Goals | Dead OKR completion notifier | **Implemented — W4**, `3f2cd2e5`. |
| Audit §5 row 14 | Development-goal hard delete | **Stale/already retained.** `HrDevelopmentGoal` uses `SoftDeletes`; C4 classification remains authoritative. |
| Audit §5 row 15 — Training | Bespoke hero and raw `oklch()` fallback | **Implemented — U2**, `d86c3a62`. |
| Audit §5 row 16 — Feed | Announcement replies notified nobody | **Implemented — W5**, `3f2cd2e5`. |
| Audit §5 row 16 — Feed | Reaction notifications; moderation/privacy deletion | **Approved closed boundaries.** Reactions remain ambient; explicit moderation/privacy deletion remains inside C4. |
| Audit §5 row 18 — Feedback | Generic `PageHero` | **Implemented — U3**, `d86c3a62`. |
| Audit §5 row 19 — Wellbeing | Flag-action undo retention; non-private check-in notice; bespoke hero | **Approved redesign boundary.** No wholesale Wellbeing redesign was approved; immediate actor-scoped undo and current confidentiality/acknowledgement behavior remain. No implementation claim. |
| Audit §5 row 22 / Run 22 | Near-empty inbox and no real-queue deep links | **Implemented before this programme — closeout C8**, `483bb709`: every real native queue has an owning link. |
| Audit Run 22 | Generic chain-row item text, raw date, bare empty state and generic hero | **Closed to the approved surface-only D-1 outcome.** Generic chain instances remain unfed in production; the real native queues are linked. No redesign claim. |
| Audit §5 row 23 / Run 23 | Sign/decline requester notices and raw signature dates | **Approved redesign/notification-policy boundary.** These remain future product ideas, not unclassified defects in this programme. |
| Audit Run 24 partial marker | Headcount, Succession and Import/export pending | **Stale historical marker.** The same ledger’s Run 24 part 2 source-audited all three clean and changed the cluster to ✅. |
| Audit Run 24 | Analytics/Headcount/Succession specialised heroes | **Approved redesign boundary.** The programme approved only U1–U3 named hero builds. |
| Audit Run 0 O-1 | Payslip stat-card links | **Approved closed boundary.** No filtered destination exists; the list is the explaining view. |
| Audit Run 0 O-2 | My-training cards lacked course links | **Already implemented before release**, `8d078521`. |
| Audit Run 0 O-3 | Supervision acknowledgement notice | **Already implemented before release**, `d1c6da43`. |
| Audit Run 0 O-4 | Development-goal completion notice | **Already implemented before release**, `2e9bee24`. |
| Audit Run 0 O-5 | Survey/policy/clock/draft-expense notifications | **Approved closed notification-noise/privacy boundaries.** |
| Audit S8/S9/S11/S12 historical partials | Runtime proof owed for GL, payroll/time, shared kudos and recruitment/onboarding | **Already closed before release** by `a420be3f`, `327dfe67`, `c45313e7`, and `9f996cbb`. |
| Audit D-1…D-11 | Eleven historical decisions | **All classified before this programme:** D-1 `483bb709`; D-2 `23a5991e`; D-3 `319ab3ce`; D-4 `cbef5fed`; D-5 `638b55eb`; D-6 `8f717805`; D-7/D-10/D-11 `97ff4af0`; D-8 `51d5b88c`; D-9 `f6d98423`. |
| Closeout C1 out-of-scope note | `EmployeeImportExportService` and `ReportBuilderService` CSV cells | **Implemented — E1**, `c36ce490`. |
| Closeout C3 architecture note | Split legacy HR audit viewer and missing organisation scope | **Implemented — A1**, `2c4f1f08`; canonical organisation-scoped store only. |
| Closeout C4 boundary note | Calendar had no honest archive lifecycle | **Implemented — R1**, `145f5e5f`. |
| Closeout contained-gate notes | Several slices deferred the full suite/build | **Stale for that release.** Closeout C10 `9eaab3a5` ran the complete terminal release gates. This does not substitute for the deferred-backlog branch’s own final proof. |
| Deferred-backlog Task 13 Step 5 | Browser proof for audit log, calendar, salary bands, offboarding, recruitment, leave chains, payroll, training, feedback, supervision, time refresh and licence requirements | **Still unverified.** The attempted browser pass was interrupted by a Codex crash. No URL/actor/action/visible-result/console/network matrix exists, so L1/release closeout must not be marked fully complete. |
| Run 14 resolution of Task 13 Step 5 | Production Chrome proof on the changed HR surfaces plus exact cleanup and normal scheduled-interval observation | **Verified complete on 13 July 2026.** Application SHA `46acdd31`; Demo Admin plus the exact marker-scoped payroll persona; empty grouped console errors; zero Chrome-source Nginx `>=400`; exact marker counts zero; private attachment absent; production baseline restored. |

## Separate approved outstanding UI/UX track — 2026-07-13

This append-only addendum does not rewrite the historical L1 disposition or reopen the completed combined Client/HR live release. A separate user-approved HR track now owns four previously optional redesign ideas plus any current P0/P1 defect proven during re-audit.

| Historical observation | New current-source evidence | Track status |
|---|---|---|
| Wellbeing actor-scoped undo clarity | Existing service already scopes by staff + actor and the controller enforces permission/tenant; no explicit regression or accessible explanation existed. Confidentiality and the no-Control-Room boundary remain unchanged. | **Implemented, verification in progress:** actor/tenant tests added; accessible bounded undo copy added. |
| Generic chain item text, raw dates, bare all-empty experience | Native queues and their owning links remain canonical. Generic rows exposed raw model names and both collections rendered separate bare empty rows. | **Implemented, verification in progress:** friendly server label, ISO instants, shared en-NZ formatting, and combined empty state; no workflow migration. |
| Sign/decline requester notices and raw signature dates | Re-audit also proved a more serious current defect: request/sender/signer paths did not consistently enforce the resolved HR tenant. | **Backend GREEN 13/51; broader verification in progress:** tenant guards, privacy-minimal same-tenant outcome notice, self/repeat suppression, and shared date formatting implemented. |
| Analytics/Headcount/Succession specialised heroes | Analytics and Headcount repeated the same KPIs immediately below generic heroes; Succession kept useful stats/actions inline. | **Implemented, verification in progress:** dedicated HR heroes use existing server stats/actions; duplicate Analytics/Headcount KPI rows removed. |

Approved boundaries remain absolute: native approvals remain canonical, HR/governance performance stays separate, H&S acknowledgements stay H&S-owned/read-only in HR, and confidential Wellbeing remains outside Control Room.

### Separate-track verification closeout — 2026-07-13

The later permission-resumed run closes the four `verification in progress` markers above without altering any historical L1 classification. This application is desktop-web only; mobile-card/WebView changes and acceptance claims were removed before commit. Final evidence: **38 focused HR tests / 300 assertions**, **10 desktop frontend contract tests**, scoped syntax/Pint/Prettier/ESLint/TypeScript green, standard client and SSR builds green, and compiled Playwright proof across **5 desktop surfaces**. Native approval ownership, Wellbeing confidentiality/no-Control-Room, and all approved D-7/D-10/D-11 boundaries remain unchanged.
