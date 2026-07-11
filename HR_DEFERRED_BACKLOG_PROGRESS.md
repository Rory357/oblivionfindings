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
| X1 | Stable Timesheet ↔ HrTimeEntry approval identity | ⬜ | — | — | — | One approved row, repeated approval idempotent. |
| X2 | Benefit plan/profile organisation invariant | ⬜ | — | — | — | Enforced inside `BenefitsService`. |
| Q1 | Manager force-expiry and intentional offer revival | ⬜ | — | — | — | Actor/reason recorded. |
| Q2 | Interview scorecard quorum with audited override | ⬜ | — | — | — | Every assigned interviewer required. |
| W1 | HR-branded employee reinvite with active-user guard | ⬜ | — | — | — | Reuse secure reset token. |
| W2 | Tenant-scoped leave approval-chain administration | ⬜ | — | — | — | Keep native leave engine. |
| W3 | Supervision taxonomy, employee note notice, dead-code removal | ⬜ | — | — | — | Visible notes only. |
| W4 | OKR completion notification transition | ⬜ | — | — | — | Notify once. |
| W5 | Announcement-reply author notification | ⬜ | — | — | — | Same organisation, not self. |
| W6 | Worker vetting/licence expiry nudges | ⬜ | — | — | — | Persisted dedupe stamps. |
| W7 | Shift licence-class and endorsement requirements | ⬜ | — | — | — | Existing HR driver records remain source of truth. |
| U1 | Payroll hero and responsive action-table pattern | ⬜ | — | — | — | No lifecycle/route change. |
| U2 | Training hero kit and token discipline | ⬜ | — | — | — | Preserve actions/filters/counts. |
| U3 | Specialised feedback hero | ⬜ | — | — | — | Server-derived deep links. |
| U4 | Neutral shared `TextPromptDialog` ownership | ⬜ | — | — | — | API unchanged. |
| U5 | Time soft refresh updates entries and preserves filters | ⬜ | — | — | — | No selection reset. |
| C1 | Calendar table guards, permission defence, team audiences | ⬜ | — | — | — | Team source is `HrEmployeeProfile.team`. |
| L1 | Classify every historical open/deferred observation | ⬜ | — | — | — | Implemented, stale, or approved closed boundary. |

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
