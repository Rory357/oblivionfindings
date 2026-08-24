# CAP-OPS-TIMESHEET-ARCHIVE-PAYROLL: Timesheet archive restore and payroll adjustment handoff

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.manageAny`, `permission:timesheets.approve|timesheets.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-TIMESHEET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/timesheets/payroll-adjustments` (`operations.timesheets.payrollAdjustments`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.manageAny`, `permission:timesheets.approve|timesheets.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.manageAny`, `role_scope:my-day`, `permission:timesheets.approve|timesheets.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/timesheets/payroll-adjustments` (`operations.timesheets.payrollAdjustments`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/timesheets/{timesheet}/archive` (`operations.timesheets.archive`, action `archive`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/TimesheetController.php:856-876`; `reason`.
3. Invoke only the owning control for `POST operations/timesheets/{timesheet}/restore` (`operations.timesheets.restore`, action `restore`). Source category: **mutation outcome source gap (restore)**; controller `app/Http/Controllers/TimesheetController.php:881-897`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/timesheets/amendments/{amendment}/mark-processed` (`operations.timesheets.markPayrollProcessed`, action `markPayrollAdjustmentProcessed`). Source category: **mutation outcome source gap (markPayrollAdjustmentProcessed)**; controller `app/Http/Controllers/TimesheetController.php:1351-1376`; no exact validation fields extracted.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `archive` / `ROUTE-2226` at `app/Http/Controllers/TimesheetController.php:856`; it is not runtime-observed.
- **mutation outcome source gap (restore)** is applicable only to `restore` / `ROUTE-2229` at `app/Http/Controllers/TimesheetController.php:881`; it is not runtime-observed.
- **mutation outcome source gap (markPayrollAdjustmentProcessed)** is applicable only to `markPayrollAdjustmentProcessed` / `ROUTE-2233` at `app/Http/Controllers/TimesheetController.php:1351`; it is not runtime-observed.
- **information presented** is applicable only to `payrollAdjustmentsPending` / `ROUTE-2239` at `app/Http/Controllers/TimesheetController.php:1309`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/timesheets/payroll-adjustments.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2226` / `archive`: fields `reason`; success app/Http/Controllers/TimesheetController.php:867 `return back()->with('success', 'Timesheet already archived.');`; app/Http/Controllers/TimesheetController.php:875 `return back()->with('success', 'Timesheet archived.');`.
- `ROUTE-2229` / `restore`: success app/Http/Controllers/TimesheetController.php:888 `return back()->with('success', 'Timesheet is already active.');`; app/Http/Controllers/TimesheetController.php:896 `return back()->with('success', 'Timesheet restored.');`.
- `ROUTE-2233` / `markPayrollAdjustmentProcessed`: success app/Http/Controllers/TimesheetController.php:1365 `return back()->with('success', 'This adjustment has already been marked as processed.');`; app/Http/Controllers/TimesheetController.php:1375 `return back()->with('success', 'Payroll adjustment marked as processed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/TimesheetController.php:870 `Timesheet::query()->whereKey($timesheet->id)->update([`; app/Http/Controllers/TimesheetController.php:891 `Timesheet::query()->whereKey($timesheet->id)->update([`; app/Http/Controllers/TimesheetController.php:1368 `$amendment->update(['applied_at' => now()]);`; responses app/Http/Controllers/TimesheetController.php:867 `return back()->with('success', 'Timesheet already archived.');`; app/Http/Controllers/TimesheetController.php:875 `return back()->with('success', 'Timesheet archived.');`; app/Http/Controllers/TimesheetController.php:888 `return back()->with('success', 'Timesheet is already active.');`; app/Http/Controllers/TimesheetController.php:896 `return back()->with('success', 'Timesheet restored.');`; app/Http/Controllers/TimesheetController.php:1357 `return back()->with('error', 'Only approved amendments can be marked as processed.');`; app/Http/Controllers/TimesheetController.php:1361 `return back()->with('error', 'This amendment does not require payroll adjustment.');`; app/Http/Controllers/TimesheetController.php:1365 `return back()->with('success', 'This adjustment has already been marked as processed.');`; app/Http/Controllers/TimesheetController.php:1375 `return back()->with('success', 'Payroll adjustment marked as processed.');`; app/Http/Controllers/TimesheetController.php:1328 `return inertia('operations/timesheets/payroll-adjustments', [`; audit calls app/Http/Controllers/TimesheetController.php:1370 `\App\Services\AuditLogger::log('timesheet.amendment.payroll_processed', $amendment->timesheet, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/timesheets/{timesheet}/archive` — `operations.timesheets.archive` — `App\Http\Controllers\TimesheetController@archive` — `app/Http/Controllers/TimesheetController.php:856` — middleware `web, auth, permission:timesheets.manageAny`
- `POST operations/timesheets/{timesheet}/restore` — `operations.timesheets.restore` — `App\Http\Controllers\TimesheetController@restore` — `app/Http/Controllers/TimesheetController.php:881` — middleware `web, auth, permission:timesheets.manageAny`
- `POST operations/timesheets/amendments/{amendment}/mark-processed` — `operations.timesheets.markPayrollProcessed` — `App\Http\Controllers\TimesheetController@markPayrollAdjustmentProcessed` — `app/Http/Controllers/TimesheetController.php:1351` — middleware `web, auth, role_scope:my-day, permission:timesheets.approve|timesheets.manageAny`
- `GET|HEAD operations/timesheets/payroll-adjustments` — `operations.timesheets.payrollAdjustments` — `App\Http\Controllers\TimesheetController@payrollAdjustmentsPending` — `app/Http/Controllers/TimesheetController.php:1309` — middleware `web, auth, role_scope:my-day, permission:timesheets.approve|timesheets.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/TimesheetController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/timesheets/payroll-adjustments.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
