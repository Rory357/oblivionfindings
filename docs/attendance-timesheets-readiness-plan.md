# Attendance / Timesheets — Production-Readiness Plan

> Scope: stabilise and complete the existing attendance / timesheet stack so it is coherent, reliable, testable, and safe to ship incrementally. Prefer small targeted fixes over rebuilds.

> **Status (2026-08-29): historical plan with one superseded security contract.** This file preserves the 30 April 2026 plan and its then-valid PR C 403 + `attendance.clockOut.unauthorized` proposal. Do not read §2 blocker 6, §3 PR C, §6's `AttendanceCrossUserGuardTest` row, or §8's “ownership audit” shorthand as the current direct-object contract.
>
> **Current direct-object contract:** lack of the base clocking or management action capability returns 403. For an otherwise action-authorized actor, malformed, missing, foreign-owned, wrong-Site, or corrupt `shift_id`/`session_id` values return the same generic 404 before nested payload validation. A concealed denial must not mutate attendance sessions, shifts, tasks, timesheets, or time entries and must not write an object-specific `attendance.clockOut.unauthorized` audit row. Authorized forced clock-out, administrative end, and correction mutations remain strictly audited. Current regression coverage belongs in `tests/Feature/Hr/AttendanceSessionSiteBoundaryTest.php` and the reconciled `tests/Feature/AttendanceCrossUserGuardTest.php`.

## Context

Stack: Laravel 12 + Pest, Inertia + React 19, Playwright 1.59 (`tests/e2e/*.spec.ts`, `data-test` testIdAttribute, MySQL CI). Recent rostering work (commit `46ee7ba0`) is documented in [`docs/rostering-pr-map.md`](rostering-pr-map.md) and is gated behind feature flags. The attendance / timesheet area is canonical at `/attendance/*` (`routes/shifts.php:93-113`) and `/operations/timesheets/*` (`routes/operations.php:621-675`); legacy URLs 308-redirect, so the URL surface is stable.

## 1. Current-state map

### Worker side (clocking + end-of-shift)

- Worker home page: `resources/js/pages/my-day/index.tsx`
- Two clock-out surfaces on the same page:
  - `resources/js/components/clock-in-card.tsx` — simple "Clock out" confirm dialog with break chips. Optional handover form. Posts `/attendance/handover` then `/attendance/clock-out` from the browser as **chained calls** (`clock-in-card.tsx:216-246`).
  - `resources/js/components/active-shift-card.tsx` — embeds `resources/js/components/end-of-shift-checklist.tsx`. Renders blockers (tasks pending, handover missing, draft incidents, unsigned meds), task list, optional override reason. Same chained POST pattern (`end-of-shift-checklist.tsx:357-381`).
- Routes: `routes/shifts.php:93-113` — `attendance.clockIn`, `attendance.clockOut`, `attendance.break.start/end`, `attendance.handover.submit`, `attendance.handover.acknowledge`.
- Controller: `app/Http/Controllers/AttendanceController.php`. Returns `AttendanceClockOutBlockedException` as 422 (JSON) or redirect-with-errors (HTML) — `AttendanceController.php:152-166`.
- Service: `app/Domain/Hr/Services/AttendanceService.php`. Wraps the entire clock-out in a DB transaction: closes session → calls `ShiftLifecycleService::complete` → `DraftTimesheetService::fromAttendanceSession` → audit log if forced (`AttendanceService.php:131-168`). Auto-match grace = 4h (`AttendanceService.php:26`) and refuses ambiguous multi-shift windows (`AttendanceService.php:360-364`). Blockers are computed in `getEndOfShiftBlockers()` (`AttendanceService.php:191-271`).

### Draft timesheet sync

- `app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php` has two entry points:
  - `fromAttendanceSession()` — called from `AttendanceService::clockOut`.
  - `fromShift()` — called from `ShiftLifecycleService::complete` (`ShiftLifecycleService.php:336-351`).
- Both invoke `TimesheetReconciliationService::reconcile`. In the clock-out path, both run inside the same transaction, so reconciliation runs **twice** for the same timesheet (idempotent but wasteful).
- Snapshot fields are populated via `ShiftOperationalSnapshotService` and only overwritten if the timesheet is still draft/returned (`DraftTimesheetService.php:59-61`).

### Approvals + lifecycle

- `app/Http/Controllers/TimesheetController.php` covers index, create/store, edit/update, **submit**, **resubmit** (atomic save+submit, `TimesheetController.php:546-640`), approve, reject, return, bulk-approve/return/reject, payroll-adjustments queue.
- `app/Domain/Shifts/Timesheets/TimesheetApprovalService.php` — uses `lockForUpdate`, asserts payroll-not-locked and shift-not-cancelled, requires snapshot fields before approval (`TimesheetApprovalService.php:335-345`), syncs to HR + billing on approve.
- `app/Services/Operations/TimesheetReconciliationService.php` emits findings (`shift_user_mismatch`, `attendance_user_mismatch`, `attendance_incomplete`, etc.) and stores `reconciliation_status` ∈ {clear, review, blocked} on the timesheet.

### DB tables (key)

- `hr_attendance_sessions` (worker clock state)
- `hr_attendance_break_events`
- `shifts` (with `roster_period_id`, `published_at` from PR 2 of rostering)
- `timesheets` (`status`: draft|submitted|returned|approved|rejected; `reconciliation_*`, `payroll_reference`, `is_payroll_segment_complete`)
- `timesheet_amendments`
- `shift_handovers` (used by the end-of-shift handover write)
- `hr_payroll_runs` (locks timesheets when status ∈ {locked, exported})

### Cross-module touch points

- **Rostering**: `RosterPublishingService::unpublish` refuses if approved timesheets exist (`docs/rostering-pr-map.md` §PR 6, defense-in-depth). Frontline shift visibility gated by publish via `Shift::scopeVisibleToFrontline`.
- **HR**: `TimesheetHrSyncService` writes a corresponding HR time entry on approval.
- **Billing**: `BillingService::generateFromTimesheet` runs on approval.
- **Audit**: `AuditableChanges` trait on key models, plus explicit `AuditLogger::log('attendance.clockOut.forced', …)` when override is used.

### Existing tests

Backend (Pest, `./vendor/bin/pest`):

- `tests/Feature/AttendanceClockOutBlockerTest.php`, `tests/Feature/AttendanceBreakTest.php`, `tests/Feature/Hr/AttendanceClockWorkflowTest.php`, `tests/Feature/Routing/AttendanceCanonicalTest.php`, `tests/Feature/Contracts/AttendanceServiceDoesNotWriteShiftStatusDirectlyTest.php`
- `tests/Feature/TimesheetControllerTest.php`, `tests/Feature/TimesheetSafetyGuardsTest.php`, `tests/Feature/TimesheetProductionHardeningTest.php`, `tests/Feature/TimesheetReconciliationTest.php`, `tests/Feature/TimesheetAmendmentWorkflowTest.php`, `tests/Feature/Timesheets/TimesheetApprovalWritePathTest.php`
- `tests/Feature/Contracts/TimesheetSnapshotImmutabilityTest.php`
- `tests/Unit/Operations/TimesheetReconciliationServiceTest.php`, `tests/Unit/Operations/TimesheetProtectionTest.php`, `tests/Unit/Shifts/Timesheets/TimesheetApprovalServiceTest.php`, `tests/Unit/Services/AttendanceApprovalHardStopTest.php`

E2E (Playwright, `npm run visual:test`): `tests/e2e/my-day-end-of-shift.spec.ts`, `tests/e2e/my-day-returned-timesheet.spec.ts`, `tests/e2e/my-day-pre-shift-briefing.spec.ts`, `tests/e2e/my-day-lifecycle-smoke.spec.ts`, `tests/e2e/my-day-a11y.spec.ts`. Helpers in `tests/e2e/helpers.ts`: `loginAsFrontlineDemoWorker` (`sw1@demo.test`, seeded by `database/seeders/FrontlineLifecycleDemoSeeder.php`), `loginAsStaff` (`admin@demo.test`), `gotoMyDay`, `collectConsoleErrors`.

CI: `.github/workflows/tests.yml` runs Pest. `.github/workflows/visual.yml` runs `migrate:fresh --seed` then `npm run visual:test` with the rostering flags **on**, uploads `playwright-report` + `test-results` on failure (so traces/screenshots are already retained).

---

## 2. Production-readiness gaps

### Real blockers

1. **Worker end-of-shift split between two surfaces is genuinely confusing and not atomic on the client.** Both `clock-in-card.tsx` and `end-of-shift-checklist.tsx` issue chained `POST /attendance/handover` → `POST /attendance/clock-out`. If the handover succeeds and the clock-out fails (validation, network), the worker has a saved handover and is still clocked in with no clear retry path. The two components also have slightly different blocker UX: the simple card never shows the blocker list, so a worker who triggers blockers from the simple card sees a generic redirect-with-errors that may not surface clearly. Today both buttons are visible at once; a worker can pick either.
2. **`AttendanceClockOutBlockedException` rendering on Inertia is ambiguous.** The HTML branch returns `redirect()->back()->withErrors(['clock_out' => …])->with('clock_out_blockers', …)`. Neither clock-out caller reads `clock_out_blockers`; both rely on the toast/error flash. The worker can therefore see "Clock-out failed" with no explicit blocker list on the home page.
3. **Tasks-pending blocker can still race.** `end-of-shift-checklist.tsx:312-326` drops the `tasks_pending` blocker locally as the worker ticks tasks, but the actual task-completion POST happens inside `ShiftTaskList` (separate request). If the worker ticks a task and immediately clicks "End shift", the server may still see `is_completed=false` and 422. No regression test for that race.
4. **Permission middleware on clock endpoints is a permission OR-list** (`routes/shifts.php:97-107`): `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`. That mirrors the controller `canClock()` (`AttendanceController.php:221-229`). The `/attendance` index uses a different OR-list (`timesheets.viewAny|timesheets.viewAssigned`), so a worker who can clock might not be able to *see* their own session list. Not a security bug, but a UX inconsistency that bites edge-role users.
5. **Reconciliation runs twice on every clock-out.** `ShiftLifecycleService::complete` → `DraftTimesheetService::fromShift` → `reconciler->reconcile`, then `AttendanceService::clockOut` → `DraftTimesheetService::fromAttendanceSession` → `reconciler->reconcile`. Idempotent but wasted DB writes inside the transaction. Low-stakes performance issue.
6. **`session_id` validation is `exists:hr_attendance_sessions,id` only.** Ownership is checked inside the service, which throws `LogicException` returned via `withErrors` — no explicit 403, no audit on cross-user attempts. Tighten via FormRequest or controller-level abort.
7. **Approvals page has no stable selectors.** `resources/js/pages/operations/timesheets/approvals.tsx` uses plain text labels; no `data-test`. Any Playwright coverage would either depend on visible English text (fragile under i18n) or grow `data-test` hooks.

### Polish (not blockers)

- "Reason to end anyway" UX appears even when the only blocker is `handover_missing` is filtered out — the override reason input only shows for `otherBlockers`, which is correct, but copy could be clearer.
- Forced clock-out audit logs `blockers` payload; approve/reject/return rely on the `AuditableChanges` trait. Verify it produces a usable audit trail per timesheet (probably fine, but no explicit test).
- `payrollAdjustmentsPending` queue exists but no E2E coverage. Defer.
- No offline draft for clocking. Out of scope.

### What does **not** look broken

- Server-side clock-out transaction is solid.
- Approval service uses `lockForUpdate`, payroll-locked guards, snapshot-required-before-approve.
- Reconciliation captures the right mismatch findings.
- Rostering↔timesheet contracts (`unpublish` refusal when approved timesheets exist, frontline visibility scope) are wired.
- 308 redirects from legacy `/timesheets/*` and `/shifts/*` are in place.

---

## 3. Minimal implementation plan

Steps are ordered for incremental shipping. Each PR should land independently and be revertable.

### PR A — Server-side handover-on-clockout (atomic) — blocker 1, 2

- **Goal**: move the handover write inside the clock-out transaction so a single `POST /attendance/clock-out` carries optional handover payload. Eliminate the chained-POST UX hazard.
- **Files likely touched**:
  - `app/Domain/Hr/Services/AttendanceService.php` — accept optional handover payload, call `ShiftHandoverService::save` inside the existing transaction, before draft sync.
  - `app/Http/Controllers/AttendanceController.php` — extend `clockOut` validation with an optional `handover` block (`meds_completed`, `shift_rating`, `handover_notes`, `follow_up_needed`).
  - Keep `POST /attendance/handover` as-is for backwards compatibility; just stop calling it from the clock-out flow.
  - Frontend: `resources/js/components/clock-in-card.tsx` and `resources/js/components/end-of-shift-checklist.tsx` — collapse two POSTs into one, drop the chained `onSuccess` indirection.
- **Risk**: MED. Touches the canonical clock-out path. Mitigated by the existing extensive test suite; old `/attendance/handover` route stays.
- **Tests**:
  - Pest: extend `AttendanceClockOutBlockerTest` with a "handover persisted on clock-out" case and a "clock-out failure rolls back handover" case (deliberately fail draft sync via mock).
  - Playwright: extend `my-day-end-of-shift.spec.ts` to assert the handover is visible on next clock-in.
- **Acceptance**:
  - Clocking out with `handover` payload writes a single submitted `ShiftHandover` and closes the session in one transaction.
  - If clock-out throws (e.g. break >= duration), no handover row is left behind.
  - Existing `/attendance/handover` endpoint still works for any caller that uses it directly.

### PR B — Surface blocker list in Inertia response — blocker 2

- **Goal**: when the clock-out is blocked, the worker should see a structured blocker list on `/my-day` (not just a generic "Clock-out failed").
- **Files**:
  - `app/Http/Controllers/AttendanceController.php` — already flashes `clock_out_blockers`. Make the redirect target deterministic (`/my-day#clock`) and ensure the JSON branch is unchanged.
  - `resources/js/pages/my-day/index.tsx` — read flashed `clock_out_blockers` and render an inline alert at the top of the clock card.
- **Risk**: LOW. Pure presentation.
- **Tests**:
  - Vitest: snapshot of the alert rendering with sample blockers.
  - Playwright: extend `my-day-end-of-shift.spec.ts` — clock out with a deliberately-blocking shift, assert the blocker labels appear.
- **Acceptance**: Inertia redirect surfaces blockers visibly to the worker without forcing them to open the dialog again.

### PR C — Tighten clock-out ownership + audit cross-user attempts — blocker 6

- **Goal**: fail closed earlier, get an audit trail on attempts to clock out someone else's session.
- **Files**:
  - `app/Http/Controllers/AttendanceController.php` — extract a small FormRequest (`ClockOutRequest`) that resolves `session_id` and aborts 403 if `session->user_id !== auth->id` and the actor lacks `attendance.manageAny` (or simply move the existing service-level check up here and add `AuditLogger::log('attendance.clockOut.unauthorized', …)` in the rejection path).
  - Same pattern for `clock-in`'s `shift_id` (already exists in service; surface as 403 not 500).
- **Risk**: LOW. Preserves the current behaviour for the happy path; only changes failure modes.
- **Tests**:
  - Pest: cross-user clock-out returns 403 + writes audit row.
- **Acceptance**: Cross-user attempts visible in audit log. No behaviour change for legitimate use.

### PR D — Resolve the "two clock-out surfaces" UX — blocker 1

- **Goal**: collapse the simple `clock-in-card` clock-out into the checklist flow so there is exactly **one** end-of-shift path.
- **Approach**: keep `clock-in-card` as the "clock in" surface and the elapsed-time card. When clocked in, its **only** clock-out CTA opens the same `EndOfShiftChecklist` dialog. Remove the bespoke `AlertDialog` confirmation in clock-in-card (lines 384-460+ in that file).
- **Files**:
  - `resources/js/components/clock-in-card.tsx` — strip the bespoke confirm dialog and break-chip controls; route the button through to the existing `EndOfShiftChecklist` component (or hoist a shared dialog into `my-day/index.tsx`).
  - `resources/js/components/active-shift-card.tsx` — keep its End-shift button as-is.
  - `resources/js/components/end-of-shift-checklist.tsx` — make `break_minutes` accept the same chip presets clock-in-card used (if the team wants to keep those).
- **Risk**: MED. UX-visible change. The `useFormAutosave` handover draft hook in clock-in-card is the only thing that has to migrate; it's already keyed by `shift_id`, so wire it into the checklist component.
- **Tests**:
  - Vitest: assert clock-in-card no longer renders its own dialog.
  - Playwright: existing `my-day-end-of-shift.spec.ts` continues to pass; add a smoke test that there is only one "End shift" button for any given session state.
- **Acceptance**: One end-of-shift dialog, one POST, one set of validation rules. UX consistency.

### PR E — Fix tasks-pending race — blocker 3

- **Goal**: avoid the race where the worker ticks tasks in the dialog then clicks "End shift" before the task POSTs land.
- **Approach**: have the checklist's submit handler `await` task-completion writes via Promise tracking from `ShiftTaskList`, OR send pending-task updates as part of the clock-out request and have `AttendanceService::clockOut` apply them inside the transaction before re-evaluating blockers. Prefer the second (server-side) — it folds the change into the same atomic write.
- **Files**:
  - `resources/js/components/end-of-shift-checklist.tsx` — pass `task_updates: [{id, is_completed}]` in the clock-out POST.
  - `app/Http/Controllers/AttendanceController.php` and `app/Domain/Hr/Services/AttendanceService.php` — apply task updates inside the transaction (use `ShiftTaskController`'s update path or an extracted service).
- **Risk**: MED. Touches the canonical task-completion path.
- **Tests**:
  - Pest: clock-out with embedded task updates clears `tasks_pending` and closes the session in the same transaction.
  - Pest: clock-out with stale task updates (task was already deleted) returns a clean error, no partial close.
- **Acceptance**: No race. Workers do not need to manually wait for ticks to settle.

### PR F — Stop double reconciliation on clock-out — blocker 5

- **Goal**: only run reconciliation once per clock-out.
- **Approach**: in `ShiftLifecycleService::complete`, when `data->source === ClockOut`, skip the `draftTimesheets->fromShift` call (or pass a `skipReconciliation` flag) since `AttendanceService::clockOut` will run `fromAttendanceSession` immediately after.
- **Files**:
  - `app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php:336-351`
  - `app/Domain/Shifts/Timesheets/Drafts/DraftTimesheetService.php` — optional `skipReconcile` argument.
- **Risk**: LOW. Pure dedupe.
- **Tests**:
  - Pest: assert `TimesheetReconciliationService::reconcile` is called exactly once during a normal clock-out.
- **Acceptance**: One reconciliation per clock-out; no behaviour change to the resulting timesheet state.

### PR G — Approvals UI: minimal `data-test` hooks — blocker 7

- **Goal**: stable Playwright selectors for approve/return/reject so we can ship deeper E2E coverage in PR H without flake.
- **Files**:
  - `resources/js/pages/operations/timesheets/approvals.tsx` — add `data-test="approvals-row"`, `data-test="approvals-row-checkbox"`, `data-test="approvals-bulk-approve"`, `data-test="approvals-bulk-return"`, `data-test="approvals-bulk-reject"`, `data-test="approvals-decision-notes"`.
  - `resources/js/pages/operations/timesheets/edit.tsx` — add `data-test="timesheet-approve"`, `data-test="timesheet-reject"`, `data-test="timesheet-return"`, `data-test="timesheet-submit"`, `data-test="timesheet-decision-notes"`.
  - `resources/js/components/clock-in-card.tsx` — `data-test="clock-in-button"`, `data-test="clock-out-button"`.
  - `resources/js/components/end-of-shift-checklist.tsx` — `data-test="end-shift-submit"`, `data-test="end-shift-override-reason"`.
- **Risk**: LOW. Markup-only.
- **Tests**: covered by PR H.
- **Acceptance**: Playwright selectors exist for every action driven by PR H.

### PR H — E2E coverage for the workflows we don't yet test (see §6)

Bundles Playwright work. Risk LOW (tests-only).

---

## 4. What not to touch

- **Rostering publish/visibility contracts** — `Shift::scopeVisibleToFrontline`, `RosterPublishingService::unpublish` refusal when approved timesheets exist, the publish/dirty listener pipeline. They were the focus of commit `46ee7ba0` and are gated behind feature flags.
- **`TimesheetApprovalService` core write paths** — `submit`/`resubmit`/`approve`/`return`/`reject` already use `lockForUpdate`, payroll-lock guards, snapshot-required-before-approve, and HR/billing sync. Don't refactor.
- **Snapshot fields on `timesheets`** — `client_name_snapshot`, `staff_name_snapshot`, `shift_type_snapshot`, `coverage_roles_snapshot`. Tests in `TimesheetSnapshotImmutabilityTest` lock these in.
- **The 308 legacy redirects** in `routes/shifts.php`. Don't simplify.
- **`AttendanceService::AUTO_MATCH_GRACE_HOURS = 4` and the multi-shift refusal** — phase 1 of `docs/shift-module-remediation-plan.md` explicitly stabilised this; don't widen the window.
- **`TimesheetReconciliationService` thresholds** (`DURATION_REVIEW_TOLERANCE_MINUTES = 10`, `BOUNDS_HIGH_TOLERANCE_MINUTES = 60`). Calibrating these is a product decision, not a readiness fix.

### Tempting refactors to defer

- Pulling `TimesheetController` validation into FormRequests. Worth doing eventually, not necessary for production readiness.
- Splitting `AttendanceService` into clock/break/handover sub-services. Cohesion is fine.
- Replacing the chained-POST handover with a service event bus. Overkill — PR A already collapses to one transaction.

---

## 5. When a larger change is justified

I don't recommend a larger redesign. The architecture supports production:

- Canonical routes are stable.
- The state machine on `shifts` and `timesheets` is enforced by `ShiftLifecycleService` and `TimesheetApprovalService`.
- Reconciliation, payroll lock, and audit are all wired.

The only place where a larger change is defensible is **if** PR D's UX consolidation surfaces deep coupling between `clock-in-card` and `my-day` props (e.g. handover draft autosave that doesn't migrate cleanly). In that case the right move is to introduce a single `WorkerShiftStateProvider` context for the page rather than to deepen the component-level prop chain. That is a half-day refactor, not a rebuild.

Smaller fixes won't be enough only if PR A's atomic handover-in-clockout exposes that `ShiftHandoverService::save` itself isn't transactional with the calling DB transaction. From the test `tests/Feature/AttendanceClockOutBlockerTest.php:87-104` ("handover submit does not crash when no incoming shift exists"), it does compose. So no larger change is justified.

---

## 6. Testing plan (with Playwright)

### Existing setup to build on

- Pest with isolated per-PID MySQL DB (`.github/workflows/tests.yml:65-70`).
- Playwright config: `playwright.config.ts` — `testDir: ./tests`, `testMatch: /.*\.spec\.ts/`, `testIdAttribute: 'data-test'`, `trace: 'retain-on-failure'`, `screenshot: 'only-on-failure'`, projects `chromium-desktop` (1440×1000) and `chromium-mobile` (Pixel 7), `webServer: php -S 127.0.0.1:4173`.
- Helpers: `tests/e2e/helpers.ts` — `loginAsFrontlineDemoWorker(page)`, `loginAsStaff(page)` (admin), `gotoMyDay(page)`, `collectConsoleErrors`, `expectNoConsoleErrors`.
- Fixtures: seeded by `migrate:fresh --seed` in CI. `database/seeders/FrontlineLifecycleDemoSeeder.php` seeds: a returned timesheet for `sw1@demo.test` with a manager note + a pre-shift briefing card. The clocked-in scenario is **timing-bound** — `my-day-end-of-shift.spec.ts:30-32` already calls `test.skip()` when the demo worker isn't currently clocked in.

### What changes

- **No new Playwright structure.** Keep `tests/e2e/`, `*.spec.ts` files, role-based helpers.
- **Add minimal `data-test` selectors** (PR G) for approval and clocking flows. Use existing text/role selectors elsewhere.
- **Add seed scenarios** to make timing-bound tests deterministic. Either:
  - Extend `FrontlineLifecycleDemoSeeder` to seed an actively-clocked-in session for `sw2@demo.test` (a dedicated demo worker for the deterministic case), and an `sw3@demo.test` with a clean roster for the clock-in case; **or**
  - Add a Pest-style `TestCase` for end-to-end UI tests using Laravel Dusk (already in `composer.json` via `laravel/dusk`). Recommended: the **first** approach — it composes with the existing Playwright setup and CI.

### Unit + integration (Pest)

- **Unit**: `AttendanceServiceClockOutHandoverTest.php` — verifies PR A handover-in-transaction.
- **Unit**: `DraftTimesheetServiceReconciliationCallTest.php` — verifies PR F single-call.
- **Integration**: `AttendanceClockOutTaskUpdateTest.php` — PR E, embed task updates in clock-out, no race.
- **Integration**: `AttendanceCrossUserGuardTest.php` — PR C, 403 + audit row on cross-user attempts.

### Playwright tests (PR H)

All run in CI in the same `npm run visual:test` invocation. Use existing `loginAsFrontlineDemoWorker` / `loginAsStaff` helpers; add `loginAsSupervisorDemoUser(page)` helper for the approval role.

| # | Spec file | Role | Starting state | Main actions | Expected | Fixtures/mocks | CI |
|---|---|---|---|---|---|---|---|
| 1 | `attendance-clock-in.spec.ts` | `sw3@demo.test` (clean roster) | One assigned shift in the auto-match window, no open session. | Visit `/my-day`. Click `data-test="clock-in-button"`. | URL stays on `/my-day`; "Shift in progress" appears; no console errors. | New seed: `sw3` with a single in-window assigned shift. | Yes |
| 2 | `attendance-clock-out-clean.spec.ts` | `sw2@demo.test` (active session, no blockers) | Clocked in, no tasks, no required handover. | Click "End shift" (active-shift-card). Submit dialog. | "Clocked out…" toast; clock card returns to idle. Server: session closed, draft timesheet created. | New seed: `sw2` with an open session and a shift that has no blockers. | Yes |
| 3 | `attendance-clock-out-checklist.spec.ts` | `sw2@demo.test` variant | Clocked in, 1 incomplete task, handover required. | Open end-of-shift dialog. Tick the task. Fill handover. Submit. | One POST to `/attendance/clock-out` with task updates + handover (PR A + PR E). Session closed, handover persisted, blocker absent on next reload. | Seed variant `sw2-with-blockers`. | Yes |
| 4 | `attendance-force-clockout.spec.ts` | `sw2@demo.test` variant | Clocked in, blocker that cannot be cleared in-dialog (e.g. unsigned med). | Open dialog; type override reason; click "End shift anyway". | Force toast; session closed; audit log row written (verify via separate Pest assertion in same suite). | Seed variant with one unsigned med dose due in window. | Yes |
| 5 | `my-day-draft-sync.spec.ts` | `sw2@demo.test` (post clock-out) | Just clocked out. | Reload `/my-day`; verify draft timesheet visible (or follow link to `/operations/timesheets/{id}/edit`). | Draft exists with correct work_date, break_minutes, and snapshot fields. | Reuses scenario 2 seed. | Yes |
| 6 | `operations-timesheets-approval.spec.ts` | `admin@demo.test` | One submitted timesheet from sw1. | Navigate to `/operations/timesheets/approvals`. Select row via `data-test="approvals-row-checkbox"`. Click `data-test="approvals-bulk-approve"`. | List empties; flash "Selected timesheets approved"; row no longer in submitted set. | Existing `FrontlineLifecycleDemoSeeder` extended to also seed one submitted timesheet. | Yes |
| 7 | `operations-timesheets-return.spec.ts` | `admin@demo.test` | One submitted timesheet. | Open the timesheet edit page; click `data-test="timesheet-return"`; fill notes; submit. | Status=returned; banner appears on `/my-day` for the worker. | Same seed. | Yes |
| 8 | `operations-timesheets-reject.spec.ts` | `admin@demo.test` | One submitted timesheet. | Reject with notes. | Status=rejected; cannot edit. | Same seed. | Yes |
| 9 | `my-day-resubmit.spec.ts` | `sw1@demo.test` | Returned timesheet (already seeded). | Open edit sheet, fix mileage, submit. | Status=submitted; sheet closes; success toast. (This formalises what `my-day-returned-timesheet.spec.ts` only renders — extend it with the actual submit.) | Existing seed. | Yes |
| 10 | `reconciliation-mismatch-visibility.spec.ts` | `admin@demo.test` | One timesheet with `reconciliation_status='review'` (e.g. duration mismatch). | Open list; assert mismatch badge visible; open edit; assert findings render. | Mismatch is visible without filtering. | Seed: variant timesheet with seeded `reconciliation_findings` JSON. | Yes |
| 11 | `permission-boundaries.spec.ts` | `sw1@demo.test` (worker) | Logged in. | Attempt to GET `/operations/timesheets/approvals` and POST `/operations/timesheets/{id}/approve`. | 403. | Existing seed. | Yes |
| 12 | `clock-in-ambiguous.spec.ts` | `sw3@demo.test` variant | Two assigned shifts in the auto-match window. | Visit `/my-day`. | Worker is asked to pick a shift; clicking generic clock-in fails with the explicit message. | Seed two overlapping shifts. | Yes |
| 13 | `clock-out-blocker-feedback.spec.ts` | `sw2@demo.test` | Active session with one blocker. | Click "End shift", attempt to submit without override. | Blocker labels visible inline (PR B); no clock-out fired. | Seed variant with one blocker. | Yes |

**Deeper regression suite** (separate file or `test.describe.skip`-by-default):

- `clock-out-payroll-lock.spec.ts` — submitted+approved timesheet inside a locked payroll run cannot be edited or rejected.
- `clock-out-stale-task-update.spec.ts` — task deleted after dialog opened; clock-out returns a clean 422.

**Selector strategy** (per project policy):

- Prefer `getByRole('button', { name: /End shift/i })` for visible affordances already in tests.
- Use `data-test` for buttons whose label is i18n-fragile or duplicated (approve/return/reject in approvals page).
- Avoid `waitForTimeout`. Use `expect(...).toBeVisible({ timeout: 10_000 })` (already the project default).
- Never assert against unrelated UI text.

**No new offline-draft test** — the app doesn't yet support offline clocking; add only when PR A's atomic POST is shipped. Revisit if the team adds a service worker.

---

## 7. Production-readiness verification

Run all of the following on `main` (or the release branch) before declaring this area ready.

```bash
# 1. PHP / backend
./vendor/bin/pest                                 # full Pest suite (CI default)
./vendor/bin/pest --filter=Timesheet              # focused timesheet suite
./vendor/bin/pest --filter=Attendance             # focused attendance suite
./vendor/bin/pint --test                          # style check
php artisan rbac:sync --force                     # confirm permissions in sync (composer rbac:sync)

# 2. Frontend
npm run lint
npm run types        # tsc --noEmit
npm run test         # vitest run
npm run build        # asset build must succeed

# 3. End-to-end (Playwright)
npm run visual:test                               # full E2E with the visual snapshots
# or, focused
npx playwright test tests/e2e/attendance-clock-in.spec.ts \
                    tests/e2e/attendance-clock-out-clean.spec.ts \
                    tests/e2e/attendance-clock-out-checklist.spec.ts \
                    tests/e2e/attendance-force-clockout.spec.ts \
                    tests/e2e/operations-timesheets-approval.spec.ts \
                    tests/e2e/operations-timesheets-return.spec.ts \
                    tests/e2e/permission-boundaries.spec.ts

# 4. Frontline parity (rostering + attendance contract)
php artisan rostering:verify-frontline-parity --details
```

Required exit conditions:

- Pest: green (CI: `.github/workflows/tests.yml`).
- TypeScript: 0 errors.
- ESLint: 0 errors / 0 warnings on changed files.
- Vitest: green.
- Playwright: 13 new specs from §6 + existing 6 my-day specs all pass on both `chromium-desktop` and `chromium-mobile`.
- `rostering:verify-frontline-parity` reports 0 backfill misses.

**Trace/screenshot/video recommendation**: keep the current config (`trace: 'retain-on-failure'`, `screenshot: 'only-on-failure'`). It already gives debugability without inflating CI artifacts. The `visual.yml` workflow already uploads `playwright-report` and `test-results` on failure (lines 108-115). Don't enable `video: 'on'` in CI — it doubles artifact size for marginal benefit. Do enable `video: 'retain-on-failure'` for the **regression suite** (non-CI by default) so deeper failures get rich evidence.

---

## 8. Final recommendation

**Ship after PRs A–H.** No larger redesign is required.

- **Real fixes**: A (atomic handover+clockout), B (visible blockers), C (ownership audit), D (single end-of-shift surface), E (tasks-pending race), F (single reconciliation), G (selectors), H (Playwright coverage).
- **Time estimate**: A–G are each 0.5–1.5 days of work; H is ~2 days for the new specs + seed extensions. Total: ~1 week of focused work.
- **Order**: A → C → F → E → B → D → G → H. A through F are server-first to lock down behaviour; D is the visible UX change; G + H add the safety net.
- **Safe to defer**: payroll-adjustments queue E2E, offline draft for clocking, FormRequest extraction, supervisor-approve audit-trail polish.
- **Risk to ship**: LOW once A and D land with their Pest tests. The architecture is sound; the gaps are real but contained.
