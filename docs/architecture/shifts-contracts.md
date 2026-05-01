# Shifts Critical Contracts

Last verified from code: 2026-04-28.

These contracts are the load-bearing constraints for Shifts, Attendance, Timesheets, Rostering, HR sync, and Payroll. Consolidation work can move orchestration into services, but it must not weaken these rules.

## 1. Payroll Lock

`App\Models\Shift::booted()` blocks changes to these payroll-critical fields once an approved operations timesheet exists:

- `client_id`
- `site_id`
- `service_context_id`
- `user_id`
- `starts_at`
- `ends_at`
- `shift_type`
- `is_sleepover`
- `is_on_call`
- `expected_break_minutes`

The lock protects approved-timesheet payroll evidence and must remain a model-level last line of defence.

## 2. Cancellation Lock

`Shift::booted()` blocks changing a shift to `cancelled` once an approved operations timesheet exists. Cancellation code can move behind a lifecycle service, but this model invariant must stay.

## 3. Timesheet Workflow Gate

`TimesheetReconciliationService::assertWorkflowAllowed()` must run on every operations timesheet status transition that can submit or approve payroll evidence. The current redundancy is intentional: controllers call it and `Timesheet::booted()` calls it again when `status` changes to `submitted` or `approved`.

## 4. Unique Shift/Staff Timesheet Pair

The `timesheets` table has a unique `(shift_id, user_id)` constraint named `uq_timesheets_shift_user`. This preserves the one operations timesheet to one payroll export mapping for a staff member on a shift.

## 5. Canonical Frontline Clock Endpoints

Frontline clocking is sanctioned only through:

- `POST /attendance/clock-in`, route name `attendance.clockIn`
- `POST /attendance/clock-out`, route name `attendance.clockOut`
- `POST /attendance/break/start`, route name `attendance.break.start`
- `POST /attendance/break/end`, route name `attendance.break.end`

Do not reintroduce `/my-tasks/clock-in/{shift}` or `/my-tasks/clock-out/{shift}` shortcuts.

## 6. Frontline Route Scope

Operations scheduler routes that are not intended for frontline staff must keep `role_scope:my-day`. The middleware gives frontline users a soft redirect to `/my-day` instead of exposing scheduler views.

## 7. Shift Alert Idempotency

`ShiftSignalService` deduplicates shift alerts with SHA256 idempotency keys, including `(shift_id, signal_type, window_key)` for shift-scoped signals. Do not change emission semantics without a migration plan for existing `ShiftSignal` and outbox rows.

## 8. Fatigue Excludes Cancelled Shifts

`FatigueRule` excludes `status = cancelled` shifts in rest, daily, weekly, and consecutive-day checks. Any future status model must preserve this exclusion.

## 9. Eligibility Runs Synchronously On Assignment

`ShiftStaffEligibilityService::evaluate()` must remain in the shift assignment path. The assignment flow currently blocks immediately on hard eligibility failures and records overrides where allowed; do not move this check to async-only processing.

## 10. Permission Strings Are Stable

Permission keys must be reused, not renamed, during consolidation. `timesheets.*` is canonical for timesheet access; legacy `hr.time.*` keys remain policy-layer aliases only:

`shifts.viewAny`, `shifts.viewAssigned`, `shifts.create`, `shifts.update`, `shifts.delete`, `shifts.manageAny`, `shifts.tasks.updateSelf`, `timesheets.viewAny`, `timesheets.viewAssigned`, `timesheets.create`, `timesheets.update`, `timesheets.submit`, `timesheets.approve`, `timesheets.manageAny`, `rostering.viewAny`, `rostering.autoSchedule`, `roster_templates.*`, `handovers.*`, `controlRoom.*`, `incidents.create`.

## Related Immutable Data Rule

Timesheet snapshot fields such as `*_snapshot`, `sleepover`, `on_call`, `break_minutes`, and `coverage_roles_snapshot` are payroll evidence. They must not be re-derived from current shift state after approval or payroll export.
