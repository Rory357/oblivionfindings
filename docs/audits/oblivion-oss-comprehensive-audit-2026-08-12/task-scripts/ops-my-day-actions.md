# OPS-MY-DAY-ACTIONS: My Day Actions

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-MY-DAY-ACTIONS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST my-day/alerts/{alert}/ack` (`my-day.alert.ack`, action `acknowledgeAlert`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/MyDayActionsController.php:409-442`; no exact validation fields extracted.
3. Invoke only the owning control for `POST my-day/alerts/{alert}/snooze` (`my-day.alert.snooze`, action `snoozeAlert`). Source category: **mutation outcome source gap (snoozeAlert)**; controller `app/Http/Controllers/MyDayActionsController.php:453-489`; no exact validation fields extracted.
4. Invoke only the owning control for `POST my-tasks/shift-task/{task}/complete` (`my-day.shift-task.complete`, action `completeShiftTask`). Source category: **completed/closed/released**; controller `app/Http/Controllers/MyDayActionsController.php:28-49`; no exact validation fields extracted.
5. Invoke only the owning control for `POST my-tasks/timesheet/{timesheet}/submit` (`my-day.timesheet.submit`, action `submitTimesheet`). Source category: **created/recorded**; controller `app/Http/Controllers/MyDayActionsController.php:156-197`; no exact validation fields extracted.
6. Invoke only the owning control for `POST my-tasks/timesheet/ensure-today` (`my-day.timesheet.ensure-today`, action `ensureTodayTimesheet`). Source category: **mutation outcome source gap (ensureTodayTimesheet)**; controller `app/Http/Controllers/MyDayActionsController.php:70-139`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledgeAlert` / `ROUTE-1885` at `app/Http/Controllers/MyDayActionsController.php:409`; it is not runtime-observed.
- **mutation outcome source gap (snoozeAlert)** is applicable only to `snoozeAlert` / `ROUTE-1886` at `app/Http/Controllers/MyDayActionsController.php:453`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeShiftTask` / `ROUTE-1893` at `app/Http/Controllers/MyDayActionsController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitTimesheet` / `ROUTE-1894` at `app/Http/Controllers/MyDayActionsController.php:156`; it is not runtime-observed.
- **mutation outcome source gap (ensureTodayTimesheet)** is applicable only to `ensureTodayTimesheet` / `ROUTE-1895` at `app/Http/Controllers/MyDayActionsController.php:70`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1885` / `acknowledgeAlert`: success app/Http/Controllers/MyDayActionsController.php:422 `return back()->with('success', 'Alert already acknowledged.');`; app/Http/Controllers/MyDayActionsController.php:441 `return back()->with('success', 'Alert acknowledged.');`.
- `ROUTE-1886` / `snoozeAlert`: success app/Http/Controllers/MyDayActionsController.php:488 `return back()->with('success', 'Snoozed.');`; failure app/Http/Controllers/MyDayActionsController.php:464 `return back()->withErrors([`.
- `ROUTE-1893` / `completeShiftTask`: success app/Http/Controllers/MyDayActionsController.php:48 `return back()->with('success', $task->is_completed ? 'Task completed.' : 'Task reopened.');`.
- `ROUTE-1894` / `submitTimesheet`: success app/Http/Controllers/MyDayActionsController.php:196 `return back()->with('success', 'Timesheet submitted for approval.');`.
- `ROUTE-1895` / `ensureTodayTimesheet`: failure app/Http/Controllers/MyDayActionsController.php:93 `return back()->withErrors([`.

## Failure and recovery paths

- `snoozeAlert`: app/Http/Controllers/MyDayActionsController.php:464 `return back()->withErrors([`.
- `ensureTodayTimesheet`: app/Http/Controllers/MyDayActionsController.php:93 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/MyDayActionsController.php:425 `$alert->update([`; app/Http/Controllers/MyDayActionsController.php:476 `$alert->update([`; app/Http/Controllers/MyDayActionsController.php:35 `$task->update([`; app/Http/Controllers/MyDayActionsController.php:41 `$task->update([`; app/Http/Controllers/MyDayActionsController.php:177 `$timesheet->update([`; app/Http/Controllers/MyDayActionsController.php:105 `$timesheet = Timesheet::query()->create([`; responses app/Http/Controllers/MyDayActionsController.php:416 `return back();`; app/Http/Controllers/MyDayActionsController.php:422 `return back()->with('success', 'Alert already acknowledged.');`; app/Http/Controllers/MyDayActionsController.php:441 `return back()->with('success', 'Alert acknowledged.');`; app/Http/Controllers/MyDayActionsController.php:460 `return back();`; app/Http/Controllers/MyDayActionsController.php:464 `return back()->withErrors([`; app/Http/Controllers/MyDayActionsController.php:488 `return back()->with('success', 'Snoozed.');`; app/Http/Controllers/MyDayActionsController.php:48 `return back()->with('success', $task->is_completed ? 'Task completed.' : 'Task reopened.');`; app/Http/Controllers/MyDayActionsController.php:196 `return back()->with('success', 'Timesheet submitted for approval.');`; app/Http/Controllers/MyDayActionsController.php:93 `return back()->withErrors([`; app/Http/Controllers/MyDayActionsController.php:138 `return back()->with('open_timesheet_id', $timesheet->id);`; audit calls app/Http/Controllers/MyDayActionsController.php:435 `AuditLogger::log('controlRoom.alert.acknowledge', $alert, [`; app/Http/Controllers/MyDayActionsController.php:481 `AuditLogger::log('controlRoom.alert.snooze', $alert, [`; app/Http/Controllers/MyDayActionsController.php:190 `AuditLogger::log('timesheet.submit', $timesheet, [`; app/Http/Controllers/MyDayActionsController.php:129 `AuditLogger::log('timesheet.draft.ensure', $timesheet, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST my-day/alerts/{alert}/ack` — `my-day.alert.ack` — `App\Http\Controllers\MyDayActionsController@acknowledgeAlert` — `app/Http/Controllers/MyDayActionsController.php:409` — middleware `web, auth`
- `POST my-day/alerts/{alert}/snooze` — `my-day.alert.snooze` — `App\Http\Controllers\MyDayActionsController@snoozeAlert` — `app/Http/Controllers/MyDayActionsController.php:453` — middleware `web, auth`
- `POST my-tasks/shift-task/{task}/complete` — `my-day.shift-task.complete` — `App\Http\Controllers\MyDayActionsController@completeShiftTask` — `app/Http/Controllers/MyDayActionsController.php:28` — middleware `web, auth`
- `POST my-tasks/timesheet/{timesheet}/submit` — `my-day.timesheet.submit` — `App\Http\Controllers\MyDayActionsController@submitTimesheet` — `app/Http/Controllers/MyDayActionsController.php:156` — middleware `web, auth`
- `POST my-tasks/timesheet/ensure-today` — `my-day.timesheet.ensure-today` — `App\Http\Controllers\MyDayActionsController@ensureTodayTimesheet` — `app/Http/Controllers/MyDayActionsController.php:70` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/MyDayActionsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
