# Shifts Module Map

Last verified from code: 2026-04-28.

This document is the architecture map for Shifts consolidation. It captures the current route surface, the completed legacy route cleanup, lifecycle write paths, and integration contracts so future work can refactor against a stable reference.

The canonical lifecycle diagram is in [shifts-lifecycle.mmd](shifts-lifecycle.mmd). The critical contracts are listed in [shifts-contracts.md](shifts-contracts.md).

## Inputs

This map is based on:

- Route consolidation exploration: legacy `routes/shifts.php` and canonical `routes/operations.php`.
- Lifecycle write-path exploration: `ShiftController`, `AttendanceService`, `ShiftSafetyInvariantService`, `TimesheetReconciliationService`.
- Approval and integration exploration: operations `TimesheetController`, HR `TimeTrackingController`, payroll, eligibility, fatigue, notifications, and control room routes.

Repository files checked for this snapshot:

- `routes/web.php`
- `routes/shifts.php`
- `routes/operations.php`
- `routes/hr.php`
- `routes/control-room.php`
- `app/Http/Controllers/ShiftController.php`
- `app/Http/Controllers/TimesheetController.php`
- `app/Http/Controllers/Hr/TimeTrackingController.php`
- `app/Domain/Hr/Services/AttendanceService.php`
- `app/Models/Shift.php`
- `app/Models/Timesheet.php`
- `app/Services/Operations/TimesheetReconciliationService.php`
- `app/Services/ShiftSafetyInvariantService.php`

## Canonical URL Spaces

| Surface | Current canonical role | Notes |
| --- | --- | --- |
| `/operations/*` | Scheduler, manager, admin operations | Canonical home for shifts, timesheets, rostering, handovers, reports, payroll export, and other operations views. |
| `/my-day` | Frontline day view | Single staff/frontline entry point. `role_scope:my-day` redirects frontline users away from scheduler-only operations views. |
| `/my-roster`, `/my-calendar` | Frontline roster/calendar | Frontline read surfaces for own roster and calendar data. |
| `/attendance/*` | Frontline clocking | Canonical clock/break/handover endpoints. These are not legacy duplicates. |
| `/control-room/*` | Control Room operations | Intentional role separation. Do not collapse into operations shifts. |
| `/hr/time/*` | HR period timekeeping | Uses `HrTimesheet` for HR period aggregates, but follows the same submit/approve/return/reject workflow shape as operations shift timesheets through `HrTimesheetApprovalService`. |
| `/shifts/*`, `/timesheets/*` | Legacy GET redirects only | Unnamed permanent redirects for deep links. Legacy route names and state-changing mounts have been removed. |

## Frontline Route Inventory

| Method | URI | Name | Controller | Contract |
| --- | --- | --- | --- | --- |
| GET | `/my-day` | `my-day` | `MyTasksController` | Frontline landing page. |
| POST | `/my-tasks/shift-task/{task}/complete` | `my-day.shift-task.complete` | `MyDayActionsController@completeShiftTask` | Legacy URI, future-facing route name. |
| POST | `/my-tasks/timesheet/{timesheet}/submit` | `my-day.timesheet.submit` | `MyDayActionsController@submitTimesheet` | Legacy URI, future-facing route name. |
| POST | `/my-day/alerts/{alert}/ack` | `my-day.alert.ack` | `MyDayActionsController@acknowledgeAlert` | Frontline alert acknowledgement. |
| POST | `/my-day/alerts/{alert}/snooze` | `my-day.alert.snooze` | `MyDayActionsController@snoozeAlert` | Frontline alert snooze. |
| GET | `/my-roster` | `my-roster` | `RosterController@index` | Own roster page. |
| GET | `/my-roster/data` | `my-roster.data` | `RosterController@data` | Own roster data endpoint. |
| GET | `/my-calendar` | `my-calendar` | `MyCalendarController@index` | Own calendar page. |
| GET | `/my-calendar/events` | `my-calendar.events` | `MyCalendarController@events` | Own calendar events. |

## Attendance Route Inventory

These routes are canonical. They should not be redirected to operations routes.

| Method | URI | Name | Controller | Permission middleware |
| --- | --- | --- | --- | --- |
| GET | `/attendance` | `attendance.index` | `AttendanceController@index` | `timesheets.viewAny|timesheets.viewAssigned` |
| POST | `/attendance/clock-in` | `attendance.clockIn` | `AttendanceController@clockIn` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |
| POST | `/attendance/clock-out` | `attendance.clockOut` | `AttendanceController@clockOut` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |
| POST | `/attendance/break/start` | `attendance.break.start` | `AttendanceController@startBreak` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |
| POST | `/attendance/break/end` | `attendance.break.end` | `AttendanceController@endBreak` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |
| POST | `/attendance/handover` | `attendance.handover.submit` | `AttendanceController@submitHandover` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |
| PATCH | `/attendance/handover/{handover}/acknowledge` | `attendance.handover.acknowledge` | `AttendanceController@acknowledgeHandover` | `timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny` |

## Operations Shift Route Inventory

| Method | URI | Name | Controller | Permission / scope |
| --- | --- | --- | --- | --- |
| GET | `/operations/shifts` | `operations.shifts.index` | `ShiftController@index` | `shifts.viewAny|shifts.viewAssigned`, `role_scope:my-day` |
| GET | `/operations/shifts/{shift}` | `operations.shifts.show` | `ShiftController@show` | `shifts.viewAny|shifts.viewAssigned` |
| GET | `/operations/shifts/create` | `operations.shifts.create` | `ShiftController@create` | `shifts.create` |
| POST | `/operations/shifts` | `operations.shifts.store` | `ShiftController@store` | `shifts.create` |
| GET | `/operations/shifts/eligibility-preview` | `operations.shifts.eligibility_preview` | `ShiftController@eligibilityPreview` | `shifts.create|shifts.update` |
| GET | `/operations/shifts/series` | `operations.shifts.series.index` | `ShiftSeriesController@index` | `role_scope:my-day`, `rostering.viewAny|shifts.viewAny|shifts.manageAny` |
| GET | `/operations/shifts/series/{series}` | `operations.shifts.series.show` | `ShiftSeriesController@show` | `role_scope:my-day`, `rostering.viewAny|shifts.viewAny|shifts.manageAny` |
| POST | `/operations/shifts/series` | `operations.shifts.series.store` | `ShiftSeriesController@store` | `shifts.create` |
| PATCH | `/operations/shifts/series/{series}/cancel-future` | `operations.shifts.series.cancel_future` | `ShiftSeriesController@cancelFuture` | `rostering.viewAny|shifts.manageAny` |
| GET | `/operations/shifts/{shift}/edit` | `operations.shifts.edit` | `ShiftController@edit` | `shifts.update` |
| PUT | `/operations/shifts/{shift}` | `operations.shifts.update` | `ShiftController@update` | `shifts.update` |
| POST | `/operations/shifts/{shift}/assign` | `operations.shifts.assign` | `ShiftController@assign` | `shifts.manageAny` |
| POST | `/operations/shifts/{shift}/unassign` | `operations.shifts.unassign` | `ShiftController@unassign` | `shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/start` | `operations.shifts.start` | `ShiftController@start` | `shifts.update|shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/complete` | `operations.shifts.complete` | `ShiftController@complete` | `shifts.update|shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/cancel` | `operations.shifts.cancel` | `ShiftController@cancelOccurrence` | `shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/reopen` | `operations.shifts.reopen` | `ShiftController@reopenOccurrence` | `shifts.manageAny` |
| POST | `/operations/shifts/{shift}/replacement-request` | `operations.shifts.replacement.request` | `ShiftController@requestReplacement` | `shifts.update|shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/replacement-request/cancel` | `operations.shifts.replacement.cancel` | `ShiftController@cancelReplacement` | `shifts.update|shifts.manageAny` |
| PATCH | `/operations/shifts/{shift}/tasks/{task}` | `operations.shifts.tasks.update` | `ShiftTaskController@update` | `shifts.update|shifts.tasks.updateSelf|shifts.manageAny` |
| POST | `/operations/shifts/{shift}/handover` | `operations.shifts.handover.store` | `Operations\HandoverController@store` | `handovers.create|shifts.update|shifts.manageAny` |

## Removed Legacy Shift Route Inventory

Legacy Shift route names have been removed. The GET URLs remain only as unnamed redirects to their canonical operations successors.

| Legacy name | Legacy URI | Canonical successor | Status |
| --- | --- | --- | --- |
| removed | `GET /shifts` | `operations.shifts.index` | Unnamed 301 redirect. |
| removed | `GET /shifts/{shift}` | `operations.shifts.show` | Unnamed 301 redirect. |
| removed | `GET /shifts/create` | `operations.shifts.create` | Unnamed 301 redirect. |
| removed | `POST /shifts` | `operations.shifts.store` | Removed; use canonical route. |
| removed | `POST /shifts/series` | `operations.shifts.series.store` | Removed; use canonical route. |
| removed | `GET /shifts/{shift}/edit` | `operations.shifts.edit` | Unnamed 301 redirect. |
| removed | `PUT /shifts/{shift}` | `operations.shifts.update` | Removed; use canonical route. |
| removed | `POST /shifts/{shift}/assign` | `operations.shifts.assign` | Removed; use canonical route. |
| removed | `POST /shifts/{shift}/unassign` | `operations.shifts.unassign` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/start` | `operations.shifts.start` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/complete` | `operations.shifts.complete` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/cancel` | `operations.shifts.cancel` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/reopen` | `operations.shifts.reopen` | Removed; use canonical route. |
| removed | `POST /shifts/{shift}/replacement-request` | `operations.shifts.replacement.request` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/replacement-request/cancel` | `operations.shifts.replacement.cancel` | Removed; use canonical route. |
| removed | `PATCH /shifts/{shift}/tasks/{task}` | `operations.shifts.tasks.update` | Removed; use canonical route. |

Adjacent non-operations shift routes remain intentionally separate today:

- `shifts.clinical.events.store`, `shifts.clinical.observations.store`, and `shifts.clinical.observations.due` under `/shifts/{shift}/clinical/*`.
- `shifts.incidents.store` under `/shifts/{shift}/incidents`.
- `calendar.shifts.store` and `calendar.shifts.update` under `/calendar/shifts`.
- `api.medications.shift.summary` under `/api/medications/shifts/{shiftId}/medication-summary`.

## Operations Timesheet Route Inventory

| Method | URI | Name | Controller | Permission / scope |
| --- | --- | --- | --- | --- |
| GET | `/operations/timesheets` | `operations.timesheets.index` | `TimesheetController@index` | `timesheets.viewAny|timesheets.viewAssigned|hr.time.viewAny` |
| GET | `/operations/timesheets/approvals` | `operations.timesheets.approvals` | `TimesheetController@approvals` | `role_scope:my-day`, review permissions |
| GET | `/operations/timesheets/payroll-adjustments` | `operations.timesheets.payrollAdjustments` | `TimesheetController@payrollAdjustmentsPending` | `role_scope:my-day`, review permissions |
| POST | `/operations/timesheets/amendments/{amendment}/mark-processed` | `operations.timesheets.markPayrollProcessed` | `TimesheetController@markPayrollAdjustmentProcessed` | `role_scope:my-day`, review permissions |
| POST | `/operations/timesheets/bulk-approve` | `operations.timesheets.bulkApprove` | `TimesheetController@bulkApprove` | `role_scope:my-day`, review permissions |
| POST | `/operations/timesheets/bulk-return` | `operations.timesheets.bulkReturn` | `TimesheetController@bulkReturnForChanges` | `role_scope:my-day`, review permissions |
| POST | `/operations/timesheets/bulk-reject` | `operations.timesheets.bulkReject` | `TimesheetController@bulkReject` | `role_scope:my-day`, review permissions |
| GET | `/operations/timesheets/create` | `operations.timesheets.create` | `TimesheetController@create` | `timesheets.create` |
| POST | `/operations/timesheets` | `operations.timesheets.store` | `TimesheetController@store` | `timesheets.create` |
| GET | `/operations/timesheets/{timesheet}` | `operations.timesheets.show` | `TimesheetController@show` | `timesheets.viewAny|timesheets.viewAssigned|hr.time.viewAny` |
| GET | `/operations/timesheets/{timesheet}/edit` | `operations.timesheets.edit` | `TimesheetController@edit` | `timesheets.viewAny|timesheets.viewAssigned|hr.time.viewAny` |
| PUT | `/operations/timesheets/{timesheet}` | `operations.timesheets.update` | `TimesheetController@update` | `timesheets.update` |
| POST | `/operations/timesheets/{timesheet}/submit` | `operations.timesheets.submit` | `TimesheetController@submit` | `timesheets.submit|timesheets.manageAny` |
| POST | `/operations/timesheets/{timesheet}/resubmit` | `operations.timesheets.resubmit` | `TimesheetController@resubmit` | `timesheets.update|timesheets.manageAny` |
| POST | `/operations/timesheets/{timesheet}/approve` | `operations.timesheets.approve` | `TimesheetController@approve` | `timesheets.approve|timesheets.manageAny|hr.time.manage|hr.time.approveTeam` |
| POST | `/operations/timesheets/{timesheet}/reject` | `operations.timesheets.reject` | `TimesheetController@reject` | `timesheets.approve|timesheets.manageAny|hr.time.manage|hr.time.approveTeam` |
| POST | `/operations/timesheets/{timesheet}/return` | `operations.timesheets.return` | `TimesheetController@returnForChanges` | `timesheets.approve|timesheets.manageAny|hr.time.manage|hr.time.approveTeam` |

`review permissions` means `timesheets.approve|timesheets.manageAny|hr.time.manage|hr.time.approveTeam`.

## Removed Legacy Timesheet Route Inventory

Legacy Timesheet route names have been removed. The GET URLs remain only as unnamed redirects to their canonical operations successors.

| Legacy name | Legacy URI | Canonical successor | Status |
| --- | --- | --- | --- |
| removed | `GET /timesheets` | `operations.timesheets.index` | Unnamed 301 redirect. |
| removed | `GET /timesheets/approvals` | `operations.timesheets.approvals` | Unnamed 301 redirect. |
| removed | `POST /timesheets/bulk-approve` | `operations.timesheets.bulkApprove` | Removed; use canonical route. |
| removed | `POST /timesheets/bulk-return` | `operations.timesheets.bulkReturn` | Removed; use canonical route. |
| removed | `POST /timesheets/bulk-reject` | `operations.timesheets.bulkReject` | Removed; use canonical route. |
| removed | `GET /timesheets/create` | `operations.timesheets.create` | Unnamed 301 redirect. |
| removed | `POST /timesheets` | `operations.timesheets.store` | Removed; use canonical route. |
| removed | `GET /timesheets/{timesheet}` | `operations.timesheets.show` | Unnamed 301 redirect. |
| removed | `GET /timesheets/{timesheet}/edit` | `operations.timesheets.edit` | Unnamed 301 redirect. |
| removed | `PUT /timesheets/{timesheet}` | `operations.timesheets.update` | Removed; use canonical route. |
| removed | `POST /timesheets/{timesheet}/submit` | `operations.timesheets.submit` | Removed; use canonical route. |
| removed | `POST /timesheets/{timesheet}/resubmit` | `operations.timesheets.resubmit` | Removed; use canonical route. |
| removed | `POST /timesheets/{timesheet}/approve` | `operations.timesheets.approve` | Removed; use canonical route. |
| removed | `POST /timesheets/{timesheet}/reject` | `operations.timesheets.reject` | Removed; use canonical route. |
| removed | `POST /timesheets/{timesheet}/return` | `operations.timesheets.return` | Removed; use canonical route. |

## Rostering Route Inventory

| Method | URI | Name | Controller | Permission / scope |
| --- | --- | --- | --- | --- |
| GET | `/operations/rostering` | `operations.rostering.index` | `RosteringController@index` | `role_scope:my-day`, `rostering.viewAny` |
| GET | `/operations/rostering/conflicts` | `operations.rostering.conflicts` | `RosteringController@conflicts` | `role_scope:my-day`, `rostering.viewAny` |
| POST | `/operations/rostering/auto-schedule` | `operations.rostering.auto_schedule` | `RosteringController@autoSchedule` | `rostering.autoSchedule` |
| GET | `/operations/rostering/templates` | `operations.rostering.templates.index` | `Operations\RosterTemplateController@index` | `roster_templates.viewAny` |
| GET | `/operations/rostering/templates/create` | `operations.rostering.templates.create` | `Operations\RosterTemplateController@create` | `roster_templates.create` |
| POST | `/operations/rostering/templates` | `operations.rostering.templates.store` | `Operations\RosterTemplateController@store` | `roster_templates.create` |
| GET | `/operations/rostering/templates/{template}` | `operations.rostering.templates.show` | `Operations\RosterTemplateController@show` | `roster_templates.viewAny` |
| GET | `/operations/rostering/templates/{template}/edit` | `operations.rostering.templates.edit` | `Operations\RosterTemplateController@edit` | `roster_templates.update` |
| PUT | `/operations/rostering/templates/{template}` | `operations.rostering.templates.update` | `Operations\RosterTemplateController@update` | `roster_templates.update` |
| POST | `/operations/rostering/templates/{template}/apply` | `operations.rostering.templates.apply` | `Operations\RosterTemplateController@apply` | `roster_templates.update` |
| DELETE | `/operations/rostering/templates/{template}` | `operations.rostering.templates.destroy` | `Operations\RosterTemplateController@destroy` | `roster_templates.delete` |

`routes/web.php` also contains an old-to-new redirect from `/rostering/{any}` to `/operations/rostering/{any}`.

## HR Time Route Inventory

| Method | URI | Name | Controller | Model / service |
| --- | --- | --- | --- | --- |
| GET | `/hr/time` | `hr.time.index` | `Hr\TimeTrackingController@index` | HR time dashboard. |
| POST | `/hr/time/clock-in` | `hr.time.clock-in` | `Hr\TimeTrackingController@clockIn` | `TimeTrackingService`. |
| POST | `/hr/time/clock-out` | `hr.time.clock-out` | `Hr\TimeTrackingController@clockOut` | `TimeTrackingService`. |
| POST | `/hr/time/clock-on-behalf` | `hr.time.clock-on-behalf` | `Hr\TimeTrackingController@clockOnBehalf` | `TimeTrackingService`. |
| POST | `/hr/time/entries` | `hr.time.entries.store` | `Hr\TimeTrackingController@store` | `HrTimeEntry`. |
| PUT | `/hr/time/entries/{entry}` | `hr.time.entries.update` | `Hr\TimeTrackingController@updateEntry` | `HrTimeEntry`. |
| GET | `/hr/time/entries/{entry}/amendments` | `hr.time.entries.amendments` | `Hr\TimeTrackingController@entryAmendments` | `HrTimeEntryAmendment`. |
| GET | `/hr/time/timesheets` | `hr.time.timesheets` | `Hr\TimeTrackingController@timesheets` | `HrTimesheet`. |
| POST | `/hr/time/timesheets/{timesheet}/submit` | `hr.time.timesheets.submit` | `Hr\TimeTrackingController@submitTimesheet` | `HrTimesheetApprovalService::submit()`. |
| POST | `/hr/time/timesheets/{timesheet}/approve` | `hr.time.timesheets.approve` | `Hr\TimeTrackingController@approveTimesheet` | `HrTimesheetApprovalService::approve()`. |
| POST | `/hr/time/timesheets/{timesheet}/reject` | `hr.time.timesheets.reject` | `Hr\TimeTrackingController@rejectTimesheet` | `HrTimesheetApprovalService::reject()`. |
| POST | `/hr/time/timesheets/{timesheet}/return` | `hr.time.timesheets.return` | `Hr\TimeTrackingController@returnTimesheet` | `HrTimesheetApprovalService::returnForChanges()`. |
| POST | `/hr/time/timesheets/bulk-approve` | `hr.time.timesheets.bulk-approve` | `Hr\TimeTrackingController@bulkApproveTimesheets` | `HrTimesheetApprovalService::bulkApprove()`. |
| POST | `/hr/time/timesheets/bulk-reject` | `hr.time.timesheets.bulk-reject` | `Hr\TimeTrackingController@bulkRejectTimesheets` | `HrTimesheetApprovalService::bulkReject()`. |
| POST | `/hr/time/timesheets/bulk-return` | `hr.time.timesheets.bulk-return` | `Hr\TimeTrackingController@bulkReturnTimesheets` | `HrTimesheetApprovalService::bulkReturn()`. |

The HR time routes are permission-scoped under `hr.time.viewAny`, with management actions under `hr.time.manage|hr.time.approveTeam`. Team approval actions are additionally scoped in the controller before calling the service.

## Control Room Shift Route Inventory

These are intentional role-separation routes and should remain outside the operations consolidation.

| Method | URI | Name | Controller |
| --- | --- | --- | --- |
| GET | `/control-room/shifts` | `control-room.shifts.index` | `ControlRoom\ControlRoomShiftController@index` |
| POST | `/control-room/shifts` | `control-room.shifts.store` | `ControlRoom\ControlRoomShiftController@store` |
| GET | `/control-room/shifts/{shift}/handover` | `control-room.shifts.handover-page` | `ControlRoom\ControlRoomHandoverController@show` |
| POST | `/control-room/shifts/{shift}/handover` | `control-room.shifts.handover` | `ControlRoom\ControlRoomShiftController@handover` |
| POST | `/control-room/shifts/{shift}/acknowledge-handover` | `control-room.shifts.acknowledge-handover` | `ControlRoom\ControlRoomShiftController@acknowledgeHandover` |
| POST | `/control-room/shifts/{shift}/note` | `control-room.shifts.note` | `ControlRoom\ControlRoomShiftController@addNote` |

## Historical Duplicate Route Pairs

The primary duplicate pairs were true controller duplicates, not role separation. The legacy route names below no longer resolve; the canonical `operations.*` names remain.

| Legacy route | Canonical route | Same controller method |
| --- | --- | --- |
| `shifts.index` | `operations.shifts.index` | `ShiftController@index` |
| `shifts.show` | `operations.shifts.show` | `ShiftController@show` |
| `shifts.create` | `operations.shifts.create` | `ShiftController@create` |
| `shifts.store` | `operations.shifts.store` | `ShiftController@store` |
| `shifts.edit` | `operations.shifts.edit` | `ShiftController@edit` |
| `shifts.update` | `operations.shifts.update` | `ShiftController@update` |
| `shifts.start` | `operations.shifts.start` | `ShiftController@start` |
| `shifts.complete` | `operations.shifts.complete` | `ShiftController@complete` |
| `shifts.cancel` | `operations.shifts.cancel` | `ShiftController@cancelOccurrence` |
| `shifts.reopen` | `operations.shifts.reopen` | `ShiftController@reopenOccurrence` |
| `shifts.assign` | `operations.shifts.assign` | `ShiftController@assign` |
| `shifts.unassign` | `operations.shifts.unassign` | `ShiftController@unassign` |
| `shifts.replacement.request` | `operations.shifts.replacement.request` | `ShiftController@requestReplacement` |
| `shifts.replacement.cancel` | `operations.shifts.replacement.cancel` | `ShiftController@cancelReplacement` |
| `shifts.tasks.update` | `operations.shifts.tasks.update` | `ShiftTaskController@update` |
| `shifts.series.store` | `operations.shifts.series.store` | `ShiftSeriesController@store` |
| `timesheets.index` | `operations.timesheets.index` | `TimesheetController@index` |
| `timesheets.show` | `operations.timesheets.show` | `TimesheetController@show` |
| `timesheets.create` | `operations.timesheets.create` | `TimesheetController@create` |
| `timesheets.store` | `operations.timesheets.store` | `TimesheetController@store` |
| `timesheets.edit` | `operations.timesheets.edit` | `TimesheetController@edit` |
| `timesheets.update` | `operations.timesheets.update` | `TimesheetController@update` |
| `timesheets.submit` | `operations.timesheets.submit` | `TimesheetController@submit` |
| `timesheets.resubmit` | `operations.timesheets.resubmit` | `TimesheetController@resubmit` |
| `timesheets.approve` | `operations.timesheets.approve` | `TimesheetController@approve` |
| `timesheets.reject` | `operations.timesheets.reject` | `TimesheetController@reject` |
| `timesheets.return` | `operations.timesheets.return` | `TimesheetController@returnForChanges` |
| `timesheets.bulkApprove` | `operations.timesheets.bulkApprove` | `TimesheetController@bulkApprove` |
| `timesheets.bulkReturn` | `operations.timesheets.bulkReturn` | `TimesheetController@bulkReturnForChanges` |
| `timesheets.bulkReject` | `operations.timesheets.bulkReject` | `TimesheetController@bulkReject` |
| `timesheets.approvals` | `operations.timesheets.approvals` | `TimesheetController@approvals` |

## Lifecycle Write Trace

| Transition / action | Current write path | Writes / side effects | Fragmentation note |
| --- | --- | --- | --- |
| Create shift | `ShiftController@store` | Creates `Shift`; may evaluate eligibility; records timeline start/completion/cancel snapshot depending on resulting status. | Creation and planning status are still controller-owned. |
| Assign | `ShiftController@assign` -> `ShiftLifecycleService::assign()` | Runs `ShiftStaffEligibilityService::evaluate()`, writes `user_id`, moves `draft` to `scheduled`, may create override. | Lifecycle service owns the write; controller owns authorization and request validation. |
| Unassign | `ShiftController@unassign` -> `ShiftLifecycleService::unassign()` | Writes `user_id = null`, `status = draft`; blocks completed/cancelled/in-progress. | Lifecycle service owned. |
| Manual start | `ShiftController@start` -> `ShiftLifecycleService::start()` | Moves eligible shifts to `in_progress`, fills start fields, records one explicit transition event. | Same start path as clock-in. |
| Clock-in start | `AttendanceService::clockIn` -> `ShiftLifecycleService::start()` | Creates `HrAttendanceSession`, then delegates the shift state transition to lifecycle service. | Attendance keeps clocking concerns; lifecycle service owns shift state. |
| Manual complete | `ShiftController@complete` -> `ShiftLifecycleService::complete()` | Controller validates operator-facing completion inputs; lifecycle service writes completion fields, timeline event, note, and draft timesheet. | Same completion write path as clock-out. |
| Clock-out complete | `AttendanceService::clockOut` -> `ShiftLifecycleService::complete()` | Checks end-of-shift blockers, closes attendance session, then delegates completion with clock-out source metadata. | Clock-out preserves frontline semantics while sharing lifecycle writes. |
| Cancel | `ShiftController@cancelOccurrence` -> `ShiftLifecycleService::cancel()` -> `ShiftCancellationService::cancel()` | Cancels shift and related workflows; model blocks cancellation if approved timesheet exists. | Lifecycle service wrapper keeps cancellation in the same transition API. |
| Reopen | `ShiftController@reopenOccurrence` -> `ShiftLifecycleService::reopen()` | Moves `cancelled` back to normalized `draft` or `scheduled`; clears actual times; deletes cancellation timeline event; syncs snapshot. | Lifecycle service owned. |
| Draft timesheet from manual complete | `DraftTimesheetService::fromShift()` | Creates/updates operations `Timesheet` for `(shift_id, user_id)`; snapshots shift/site/service/client/staff/type/coverage; reconciles. | Single draft service; preserves manual-completion field semantics. |
| Draft timesheet from clock-out | `DraftTimesheetService::fromAttendanceSession()` | Creates/updates operations `Timesheet` from attendance session and linked shift; snapshots related data; resolves payroll rate; reconciles. | Single draft service; preserves clock-out field semantics. |
| Reconciliation report | `TimesheetReconciliationService::reconcile()` | Writes `reconciliation_*` fields. | Reconciliation owns findings and workflow gates; draft creation lives in `DraftTimesheetService`. |

## Timesheet Approval Trace

| Action | Current operations path | Current HR path | Fragmentation note |
| --- | --- | --- | --- |
| Submit | `TimesheetController@submit` -> `TimesheetApprovalService::submit()` | `Hr\TimeTrackingController@submitTimesheet` -> `HrTimesheetApprovalService::submit()` | Same workflow shape; operations is shift-level, HR is period-level. |
| Resubmit | `TimesheetController@resubmit` -> `TimesheetApprovalService::resubmit()` | `HrTimesheetApprovalService::submit()` accepts `returned` HR period timesheets | Operations supports atomic update + resubmit; HR period corrections use returned -> submitted. |
| Approve single | `TimesheetController@approve` -> `TimesheetApprovalService::approve()` | `Hr\TimeTrackingController@approveTimesheet` -> `HrTimesheetApprovalService::approve()` | Both approval paths are service-owned and idempotent. |
| Approve bulk | `TimesheetController@bulkApprove` -> `TimesheetApprovalService::bulkApprove()` | `Hr\TimeTrackingController@bulkApproveTimesheets` -> `HrTimesheetApprovalService::bulkApprove()` | Bulk calls the same single approval method internally on both surfaces. |
| Return | `TimesheetController@returnForChanges` -> `TimesheetApprovalService::returnForChanges()` | `Hr\TimeTrackingController@returnTimesheet` -> `HrTimesheetApprovalService::returnForChanges()` | Both use explicit returned semantics. |
| Reject | `TimesheetController@reject` -> `TimesheetApprovalService::reject()` | `Hr\TimeTrackingController@rejectTimesheet` -> `HrTimesheetApprovalService::reject()` | Both rejection paths are service-owned. |
| Approval snapshots | `TimesheetApprovalService::syncApprovedTimesheet()` | Not applicable to `HrTimesheet` period aggregates | Operations approval populates immutable payroll snapshots and triggers HR sync + billing; HR period approval updates HR time entries only. |

## Integration Contract Table

| Integration surface | Entry points | Contract to preserve |
| --- | --- | --- |
| My Day | `/my-day`, `my-day.*`, attendance endpoints | Frontline staff should remain in frontline UI and not be linked into scheduler-only operations views. |
| Roster | `/operations/rostering*`, `/my-roster*`, shift assign/unassign | Assignment must keep synchronous eligibility checks and preserve roster permission gates. |
| Attendance | `/attendance/*`, `AttendanceService` | Clock-in/clock-out remain canonical frontline clock entries and must create real attendance sessions. |
| Timesheets | `operations.timesheets.*`, `hr.time.timesheets.*` | Operations timesheet workflow gate and snapshot immutability must remain in place. |
| Payroll | `PayrollRateResolver`, operations payroll export, HR payroll services | Sleepover, on-call, break, public holiday, and coverage-role semantics must not drift. |
| Eligibility | `ShiftStaffEligibilityService`, `ShiftEligibilityOverride` | Evaluate synchronously on assign; hard blocks stay blocking. |
| Fatigue | `FatigueRule` | Cancelled shifts remain excluded from daily, weekly, rest-gap, and consecutive-day checks. |
| Notifications | `NotificationService`, `ShiftAssignedNotification`, timesheet notifications | Route consolidation must not drop lifecycle and approval notifications. |
| Control Room | `ShiftSignalService`, `ShiftAutoAlertJob`, `/control-room/shifts*` | Signal idempotency and control-room role separation must remain intact. |
| Incidents | `shifts.incidents.store`, client incident links | Incident creation tied to shifts must continue to resolve from current shift route contexts. |
| HR sync | `TimesheetHrSyncService`, HR time routes | Operations approval must continue to sync approved timesheets to HR in the same transaction position. |
| Permissions | Route middleware and `User::canDo()` checks | Permission strings are stable and must not be renamed. |

## Current Fragmentation Snapshot

1. Legacy route namespace duplication has been cleaned up. `routes/operations.php` owns scheduler/admin Shift and Timesheet controller endpoints; `routes/shifts.php` keeps canonical attendance routes plus unnamed GET redirects from `/shifts/*` and `/timesheets/*`.

2. Shift lifecycle transitions are consolidated:
   - `scheduled -> in_progress` is written through `ShiftLifecycleService::start()` from both manual start and clock-in.
   - `in_progress -> completed` is written through `ShiftLifecycleService::complete()` from both manual complete and clock-out.
   - Controllers and attendance services keep authorization, validation, and clock/session concerns; the lifecycle service owns state writes, transition events, and draft sync.

3. Draft operations timesheet creation is consolidated in `DraftTimesheetService`. Reconciliation jobs/services inspect missing links and report findings but do not create the draft payload directly.

4. Operations timesheet approval is consolidated:
   - `TimesheetController` single and bulk actions call `TimesheetApprovalService`.
   - Bulk actions loop through the same single-transition methods to keep workflow gates, snapshots, HR sync, billing, and idempotency consistent.
   - HR time approval routes use `Hr\TimeTrackingController` and `HrTimesheetApprovalService` against `HrTimesheet` period aggregates. This gives managers one workflow vocabulary while preserving the model boundary between shift-level operations timesheets and HR period timesheets.

5. Defensive model invariants already exist and must remain after service consolidation:
   - `Shift::booted()` calls `ShiftSafetyInvariantService::assertShift()`.
   - `Shift::booted()` locks payroll-critical fields and cancellation once an approved timesheet exists.
   - `Timesheet::booted()` calls `ShiftSafetyInvariantService::assertTimesheet()`.
   - `Timesheet::booted()` enforces `(shift_id, user_id)` uniqueness and status workflow checks.

## Phase References

This map supports the later consolidation phases:

- Phase 1: route redirects and route-regression tests.
- Phase 2: `ShiftLifecycleService` as the single write path for shift transitions.
- Phase 3: operations timesheet approval service and write-path consolidation.
- Phase 4: frontend route-name migration to canonical `operations.*` names.
- Phase 5: contract hardening tests for payroll locks, snapshots, fatigue, alert idempotency, and NZ payroll semantics.
- Phase 6: lifecycle and browser end-to-end test suite.
- Phase 7: legacy names and state-changing mounts removed; GET URLs remain as deep-link redirects.
