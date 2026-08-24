# CAP-OPS-ATTENDANCE-BREAKS: Attendance break start and end

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-ATTENDANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `attendance` (`attendance.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD attendance` (`attendance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST attendance/break/end` (`attendance.break.end`, action `endBreak`). Source category: **mutation outcome source gap (endBreak)**; controller `app/Http/Controllers/AttendanceController.php:425-446`; `session_id`.
3. Invoke only the owning control for `POST attendance/break/start` (`attendance.break.start`, action `startBreak`). Source category: **created/recorded**; controller `app/Http/Controllers/AttendanceController.php:402-423`; `session_id`.

## Source-applicable states and transitions

- **mutation outcome source gap (endBreak)** is applicable only to `endBreak` / `ROUTE-0066` at `app/Http/Controllers/AttendanceController.php:425`; it is not runtime-observed.
- **created/recorded** is applicable only to `startBreak` / `ROUTE-0067` at `app/Http/Controllers/AttendanceController.php:402`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0066` / `endBreak`: fields `session_id`; success app/Http/Controllers/AttendanceController.php:445 `return redirect()->back()->with('success', 'Break ended.');`; failure app/Http/Controllers/AttendanceController.php:442 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`.
- `ROUTE-0067` / `startBreak`: fields `session_id`; success app/Http/Controllers/AttendanceController.php:422 `return redirect()->back()->with('success', 'Break started.');`; failure app/Http/Controllers/AttendanceController.php:419 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`.

## Failure and recovery paths

- `endBreak`: app/Http/Controllers/AttendanceController.php:442 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`.
- `startBreak`: app/Http/Controllers/AttendanceController.php:419 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/AttendanceController.php:442 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:445 `return redirect()->back()->with('success', 'Break ended.');`; app/Http/Controllers/AttendanceController.php:419 `return redirect()->back()->withErrors(['break' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:422 `return redirect()->back()->with('success', 'Break started.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST attendance/break/end` — `attendance.break.end` — `App\Http\Controllers\AttendanceController@endBreak` — `app/Http/Controllers/AttendanceController.php:425` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST attendance/break/start` — `attendance.break.start` — `App\Http\Controllers\AttendanceController@startBreak` — `app/Http/Controllers/AttendanceController.php:402` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AttendanceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
