# Shifts Lifecycle Service

`App\Domain\Shifts\Lifecycle\ShiftLifecycleService` is the authoritative write path for shift state transitions. Controllers, attendance clocking, jobs, and future bulk actions must authorize first, then call this service instead of writing `shifts.status` directly.

The service is intentionally table-driven and local. A small `TRANSITIONS` map keeps the state machine auditable without adding a new state-machine package or hiding guard logic behind framework events.

## Transition Table

| From | To | Owner | Notes |
| --- | --- | --- | --- |
| `draft` | `scheduled` | planning / assignment | Assignment promotes draft shifts when staff is attached. |
| `draft` | `in_progress` | `ShiftLifecycleService::start` | Only allowed from clock-in, preserving the existing attendance behaviour. |
| `scheduled` | `in_progress` | `ShiftLifecycleService::start` | Used by both the manager button and attendance clock-in. |
| `in_progress` | `completed` | `ShiftLifecycleService::complete` | Manual completion keeps rich handover/task/note validation. Clock-out uses the same transition with attendance-specific data. |
| pre-completion | `cancelled` | `ShiftLifecycleService::cancel` | Thin wrapper over `ShiftCancellationService`; approved-timesheet locks remain in `Shift::booted()`. |
| `cancelled` | `draft` / `scheduled` | `ShiftLifecycleService::reopen` | Reopens to `scheduled` when assigned, otherwise `draft`. |

## Idempotency

Lifecycle operations are idempotent on status:

- starting an already `in_progress` shift returns the shift and fills missing start trackers if needed;
- completing an already `completed` shift returns the shift and recovers a missing draft timesheet when requested;
- cancelling an already `cancelled` shift is handled before calling the cancellation cascade;
- reopening a non-cancelled shift is a no-op at the service layer, while the controller may still return a user-facing error.

Tracker fields are fill-on-empty for repeated calls, except manual completion still records the manual completer to preserve the old controller behaviour.

## Draft Timesheets

Shift completion and attendance clock-out both call `App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService` for draft operations timesheet creation. The service keeps the two existing payload semantics distinct:

- manual completion derives times from the shift and uses expected break minutes;
- clock-out derives times and breaks from the attendance session.

Both paths preserve immutable snapshot fields by only updating `draft` and `returned` timesheets. Approved or payroll-linked timesheets are reconciled but not re-derived from current shift state.

## Defences That Stay Below The Service

The model-level invariants remain the last line of defence:

- `Shift::booted()` still calls `ShiftSafetyInvariantService::assertShift()`.
- Approved-timesheet payroll locks still block edits to payroll-critical shift fields.
- Approved-timesheet cancellation locks still block `status = cancelled`.
- `Timesheet::booted()` still calls `TimesheetReconciliationService::assertWorkflowAllowed()` on workflow status changes.

These hooks are deliberately redundant with service-level guards so that raw model saves, jobs, and future refactors cannot bypass payroll safety.
