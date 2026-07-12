# Client Profile and HR Live Gap Closeout

**Status:** Active
**Started:** 2026-07-12 (Pacific/Auckland)
**Branch:** `codex/client-hr-live-gap-closeout`
**Worktree:** `C:\Users\steph\Herd\oblivionfindings-client-hr-live-gap-closeout`
**Starting revision:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`
**Starting `origin/main`:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`

## Safety and ownership decisions

- The dirty detached root checkout is out of scope and must remain untouched.
- The dedicated `main` worktree is clean but currently carries five unpushed Fleet commits ending at `ab7f3636`; those commits must be preserved and reconciled before the final explicit merge.
- Client tab aliases remain owned by `resources/js/pages/operations/clients/tabs/_groups.ts`.
- Care & Support Plan remains owned by the canonical `care_plans` workspace.
- HR team ownership is `HrEmployeeProfile.team`; `HrPosition.team` remains an independent position-template attribute and is not dual-written.
- Durable synthetic HR data must extend the existing HR demo seeding path. Transient live lifecycle fixtures use one unique marker and must be removed.
- Control Room escalation remains owned by `AutoEscalateControlRoomQueues`.

## Slice ledger

### Startup

- Start SHA: `b16e934d72fa8bafa0a86882c4772f6b20a56289`
- End SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Files changed: `docs/client-hr-live-gap-closeout.md`
- Tests: worktree, branch, clean baseline, dependency links, and testing environment verified before feature edits.
- Live URLs: none
- Smoke marker and record IDs: none
- Cleanup: not applicable
- Commit SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Remaining: Slices 1-4, aggregate gates, integration, push, deployment, scheduled-interval observation, and single-session Chrome proof.

### Slice 1 — Client tab canonicalisation

- Start SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- End SHA: pending
- Ownership decision: legacy aliases stay in `_groups.ts`; the canonical care-plan workspace remains `care_plans`.
- Files changed: `resources/js/pages/operations/clients/tabs/_groups.ts`, `resources/js/test/client-profile-navigation.test.tsx`, `tests/e2e/operations-client-profile-phase-1.spec.ts`, and this ledger.
- Root cause: the alias canonicaliser called the browser History API directly, bypassing Inertia's history-state ownership. The visible URL changed, but Inertia navigation state was not updated safely.
- Fix: route alias `replace`/`push` operations through the supported Inertia router while retaining the full query and preserving scroll/state.
- Red proof: with the prior direct-History implementation restored temporarily, the focused Vitest run reported **1 failed / 13 passed** because the legacy alias made zero `router.replace` calls.
- Green tests and exact counts: focused Vitest **14 passed**; targeted Chromium desktop alias scenario **1 passed**; Prettier check passed for all 3 changed TS/TSX files; ESLint passed with zero warnings; `npm run types` passed; production client build passed (`4943` modules transformed).
- Browser URLs: local `/operations/clients/{id}?tab=support_plan&dialog=quick_note&record=99&source=legacy` canonicalised to the same URL with `tab=care_plans`; Back returned to the previous dashboard URL, Forward restored the canonical URL and open dialog, reload retained the canonical deep link, and the recent-client link used `tab=care_plans`.
- Console/network evidence: the targeted Playwright case asserted zero console errors and zero `>=400` responses for the target client route.
- Full-file classification: **1 passed / 2 failed**. The new alias case passed. Both pre-existing note-capture cases could not find the permission-gated quick-note action after canonical global setup failed on the existing duplicate `EMP0003` unique key in `SystemUsersSeeder`; the same two failures reproduced with reseeding skipped. This is a local fixture/seeder blocker, not an alias regression.
- Smoke marker and record IDs: exact local synthetic clients `5-38`, alternating `Playwright Profile` and `Recent Playwright Client`.
- Cleanup: deleted exactly client IDs `5-38` from the local testing database through model deletion; verified `remaining=0` for that ID set.
- Commit SHA: `e2958ca0a1ef64bb7c4729ffe506e5f14b71a43d`
- Remaining: post-deployment Chrome acceptance.

### Slice 2 — HR team ownership and Calendar configuration

- Start SHA: `e2958ca0a1ef64bb7c4729ffe506e5f14b71a43d`
- End SHA: pending
- Ownership decision: `HrEmployeeProfile.team` is the single employee-membership fact and the current Calendar contract. `HrPosition.team` remains an independent position-template attribute and is not dual-written. Calendar, manager create/edit, and demo assignments all use the profile field; no schema, route, taxonomy, or parallel configuration surface was added.
- Files changed: `HrEmployeeProfile`, the two employee requests, `EmployeeProfileController`, `CalendarController`, `HrDemoSeeder`, manager create/edit UI, shared segmented-control disabled support, Calendar wizard empty state, focused feature tests, demo-seeder test, and this ledger.
- Red proof: the first genuine focused test asserted that create input `clinical support` must resolve to the tenant's existing `Clinical Support`; it failed because the newly created profile team was `NULL`.
- Normalisation and safety: leading/trailing and repeated whitespace collapse; blank becomes `NULL`; existing tenant spelling/case is reused; 255-character maximum is enforced; foreign-tenant edit/update returns 404; Calendar options include only active same-tenant profiles and deduplicate case/spacing variants.
- Manager UI: Add Employee and Edit Employee expose the canonical profile team. Edit copy explains Calendar use and clearing. Calendar disables `A team` when none exist and links to `/hr/people` instead of exposing a dead selector.
- Demo ownership: `HrDemoSeeder` now targets only the three exact `hrdemo.worker{1..3}@example.test` profiles and assigns `Community Support`, `Community Support`, and `Operations` idempotently; it no longer selects arbitrary active profiles for its demo workflow data.
- Green tests and exact counts: `HrTeamConfigurationTest`, `HrDemoSeederTest`, and `HrCalendarResilienceTest` passed **16 tests / 118 assertions**. PHP syntax passed for all 8 changed PHP/test files. Pint passed before the legacy-formatting churn was reverted. TypeScript passed. ESLint passed with zero warnings. `git diff --check` passed.
- Formatting classification: all four touched legacy TSX files fail whole-file Prettier on the starting revision; formatting them would create hundreds of unrelated lines, so that sweep was explicitly reverted and only scoped lines remain. The changed PHP requests/seeder likewise predate current whole-file Pint formatting; the broad mechanical sweep was reverted for minimal churn.
- Browser URLs: pending
- Smoke marker and record IDs: pending
- Cleanup: no Slice 2 local transient records remain; feature tests use isolated per-process databases that are dropped at shutdown. Durable known demo assignments are intentionally idempotent.
- Commit SHA: `e75047ab63ad57684e29ced57fbc678cab3e52ff`
- Remaining: post-deployment browser lifecycle acceptance.

### Slice 3 — Fixture-gated HR browser matrix

- Start SHA: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- End SHA: pending
- Ownership decision: exercise canonical HR lifecycle workflows and existing demo ownership; do not create parallel implementations.
- Files changed: `tests/e2e/hr-live-gap-closeout.spec.ts` and this ledger. No Slice 3 application source, route, schema, or parallel workflow was added.
- Baseline counts: users `35`; profiles `33`; audit logs `584`; calendar events/attendees/reminders/attachments `0/0/0/0`; salary bands `3`; offboarding checklists/tasks/exit interviews `0/0/0`; candidates/applications/offers `5/5/0`; approval chains/steps `2/2`; payroll runs/payslips `1/0`.
- Local discovery state: `HrDemoSeeder` was run idempotently and exact synthetic demo workers were confirmed (`3`). It supplies one payroll run and two generic approval chains but no offer, payslip, offboarding, or exit-interview lifecycle fixture, so the Playwright spec creates transient canonical-model records under one exact marker.
- Marker: `CODEX-LIVE-HR-GAPS-20260712T151828`. Final green-run IDs: leaver user/profile `57/54`; active payroll user/profile `58/55`; calendar event `16`; salary band `19`; offboarding checklist/task `16/16`; offer `14`; generic approval chain `16`; leave-chain routes `27/28`; payroll run/payslip `15/14`; private attachment `hr/calendar/1/CODEX-LIVE-HR-GAPS-20260712T151828.txt`.
- Mail-driver safety: local default and resolved transport were both `array`, so offer resend exercised notification construction without external delivery.
- Browser URLs and results: Chromium desktop covered `/hr/settings/audit-log?action=codex.live_hr_gaps`, `/hr/calendar`, `/hr/compensation/bands`, `/hr/offboarding/{id}`, `/hr/recruitment?tab=offers`, `/careers/offers/{old-token}`, `/hr/approvals/chains`, `/hr/payroll`, `/hr/payroll/payslips`, and `/hr/my/payslips`; it also loaded `/hr/training/catalog`, `/hr/feedback`, `/hr/performance?tab=supervision`, `/hr/time?tab=overview`, and `/operations/shifts` as regression routes. Payroll payslips were asserted at `390x844`; other rows ran at desktop width.
- Lifecycle evidence: audit filtering and System attribution; calendar archive/restore with `1/1/1` attendees/reminders/attachments retained; salary-band placement/deactivate/reactivate; one canonical exit interview after reload with offboarding-completion login revocation preserved; required offer-expiry reason, immediate old-token invalidation, actor/reason attribution, resend token rotation and attribution reset; native leave-route reorder/edit/deactivate/reactivate; payroll run/admin payslip/mobile actions; and active employee self-service net pay.
- Green browser proof: final matrix **1 passed** in **1.6m**. The spec asserted zero captured console errors and zero `>=400` HR responses.
- Cleanup and final counts: reverse cleanup deletes the attachment and every marker record, pivot, and exact auditable ID. After a second full green run, marker users/profiles/events/files/audits were all `0`, and every baseline count above returned exactly, including audit logs `584`.
- Backend suites: `CanonicalAuditOrganizationTest`, `DeferredRetentionLifecycleTest`, `HrCalendarResilienceTest`, `SalaryBandPlacementTest`, `ExitInterviewWizardTest`, `OffboardingWizardStoreTest`, `RecruitmentDeferredLifecycleTest`, `ApprovalChainTenantTest`, `MyPayslipsSelfServiceTest`, `PayslipPdfTest`, and `PayrollRunIntegrityTest` passed **59 tests / 426 assertions**.
- Static gates: new spec Prettier passed, ESLint passed with zero warnings, TypeScript passed, and `git diff --check` passed.
- Commit SHA: pending
- Remaining: commit, aggregate release gates, deployment, and the required post-deployment real Chrome acceptance session.

### Slice 4 — Control Room scheduled escalation

- Start SHA: `e75047ab63ad57684e29ced57fbc678cab3e52ff`
- End SHA: pending
- Ownership decision: keep escalation in `AutoEscalateControlRoomQueues`; apply only required callback capture changes.
- Files changed: `app/Jobs/AutoEscalateControlRoomQueues.php`, `tests/Feature/ControlRoom/AutoEscalateControlRoomQueuesTest.php`, and this ledger.
- Red proof: the eligible-alert regression reproduced `Undefined variable $automationService` at `AutoEscalateControlRoomQueues.php:91` after entering the nested alert chunk.
- Fix: capture the injected automation service in the outer queue chunk and inner alert chunk. No escalation behavior, service, route, model, or schema was changed.
- Green tests and exact counts: focused job regression plus `AlertAutomationServiceTest` passed **14 tests / 31 assertions**. The regression verifies old assignment closure, new assignment creation, alert queue/level/context update, notification call, automation call, and clean completion.
- PHP gates: syntax passed for both changed PHP files; scoped Pint passed; `git diff --check` passed.
- Scheduled-interval server evidence: pending
- Commit SHA: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- Remaining: deployment and one normal scheduled-interval log observation.

## Crash-containment checkpoint — 2026-07-12

- Stop request received after Slice 3 discovery began. No further slice work, merge, push, deployment, or browser activity was performed.
- Worktree: `C:\Users\steph\Herd\oblivionfindings-client-hr-live-gap-closeout`
- Branch: `codex/client-hr-live-gap-closeout`
- HEAD before this checkpoint commit: `abd84351b30f495b9bdefdf6ba7dce3d75784e5e`
- Dirty state before ledger update: clean.
- Last safe command: `npm run build` completed successfully with `4943` modules transformed in `3m 52s`.
- Running-process state: no preview, Playwright, Vite, or browser process started by this task remains. Port `4173` is not listening. An older PHP server owned by another task is listening on `127.0.0.1:8768` (PHP PID `37228`, started 2026-07-11); it was not started or stopped here.
- Next step on resume: start from this ledger and branch, create the unique marker-scoped Slice 3 fixtures only after baseline counts and mail-driver safety are recorded, then run the required HR browser lifecycle matrix and cleanup proof.

## Release gates

- Focused and aggregate tests: pending
- PHP syntax: pending
- Pint: pending
- Prettier: pending
- ESLint zero-warning: pending
- Wayfinder: pending
- TypeScript: pending
- Client build: pending
- SSR build: pending
- `git diff --check`: pending

## Integration and deployment

- Latest upstream reconciliation: pending
- Feature commit(s): pending
- Merge commit: pending
- Local `main`: pending
- `origin/main`: pending
- `git ls-remote`: pending
- Deployed SHA: pending
- Server cleanliness, migrations, manifest, queue, login and logs: pending

## Final acceptance status

- Client browser row: Partial — exact post-deployment Chrome evidence not yet captured.
- HR L1: Partial — exact fixture-backed post-deployment Chrome evidence not yet captured.
- Control Room scheduled interval: Partial — deployed interval not yet observed.
