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
| A1 | Canonical organisation-scoped HR audit viewer | ⬜ | — | — | — | Preserve legacy table only for rollback/history. |
| A2 | Payroll export-profile default-demotion audit | ⬜ | — | — | — | Canonical event with promoted/demoted IDs. |
| E1 | Service CSV formula neutralisation | ⬜ | — | — | — | Preserve numeric machine fields. |
| R1 | HR calendar event archive/restore | ⬜ | — | — | — | Retain attendees, reminders, attachments, and files. |
| R2 | Salary-band deactivate/reactivate | ⬜ | — | — | — | Historical references remain resolvable. |
| O1 | Explicit exit-interview ↔ offboarding-task identity | ⬜ | — | — | — | No new title matching. |
| O2 | Future exit-interview schedule notifications | ⬜ | — | — | — | No post-hoc completion notification. |
| O3 | Late-issued asset task reconciliation | ⬜ | — | — | — | Idempotent per checklist/asset. |
| O4 | Deterministic offboarding assignee fallback | ⬜ | — | — | — | Role → manager → initiating HR actor. |
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
