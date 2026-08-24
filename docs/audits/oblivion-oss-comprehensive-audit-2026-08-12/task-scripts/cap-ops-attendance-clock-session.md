# CAP-OPS-ATTENDANCE-CLOCK-SESSION: Attendance clock and session correction lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny`
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

- Actor satisfying exact route middleware `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:timesheets.viewAny|timesheets.viewAssigned|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`, `permission:timesheets.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD attendance` (`attendance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST attendance/clock-in` (`attendance.clockIn`, action `clockIn`). Source category: **created/recorded**; controller `app/Http/Controllers/AttendanceController.php:260-295`; `shift_id`.
3. Invoke only the owning control for `POST attendance/clock-out` (`attendance.clockOut`, action `clockOut`). Source category: **completed/closed/released**; controller `app/Http/Controllers/AttendanceController.php:297-369`; `session_id`.
4. Invoke only the owning control for `POST attendance/sessions/{session}/correct` (`attendance.sessions.correct`, action `correctSession`). Source category: **mutation outcome source gap (correctSession)**; controller `app/Http/Controllers/AttendanceController.php:225-258`; `clock_out_at`.
5. Invoke only the owning control for `POST attendance/sessions/{session}/end` (`attendance.sessions.end`, action `endSession`). Source category: **mutation outcome source gap (endSession)**; controller `app/Http/Controllers/AttendanceController.php:375-400`; `reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0065` at `app/Http/Controllers/AttendanceController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `clockIn` / `ROUTE-0068` at `app/Http/Controllers/AttendanceController.php:260`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `clockOut` / `ROUTE-0069` at `app/Http/Controllers/AttendanceController.php:297`; it is not runtime-observed.
- **mutation outcome source gap (correctSession)** is applicable only to `correctSession` / `ROUTE-0072` at `app/Http/Controllers/AttendanceController.php:225`; it is not runtime-observed.
- **mutation outcome source gap (endSession)** is applicable only to `endSession` / `ROUTE-0073` at `app/Http/Controllers/AttendanceController.php:375`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/attendance/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0065` / `index`: fields `week`.
- `ROUTE-0068` / `clockIn`: fields `shift_id`; success app/Http/Controllers/AttendanceController.php:294 `return redirect()->back()->with('success', 'Clocked in successfully.');`; failure app/Http/Controllers/AttendanceController.php:281 `abort(403);`; app/Http/Controllers/AttendanceController.php:291 `return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);`.
- `ROUTE-0069` / `clockOut`: fields `session_id`; success app/Http/Controllers/AttendanceController.php:365 `return redirect()->back()->with('success', "Clocked out. Draft timesheet #{$closed->timesheet->id} synced.");`; app/Http/Controllers/AttendanceController.php:368 `return redirect()->back()->with('success', 'Clocked out successfully.');`; failure app/Http/Controllers/AttendanceController.php:334 `abort(403);`; app/Http/Controllers/AttendanceController.php:343 `->withErrors(['clock_out' => $exception->getMessage()])`; app/Http/Controllers/AttendanceController.php:355 `->withErrors(['clock_out' => $exception->getMessage()])`; app/Http/Controllers/AttendanceController.php:358 `return redirect()->back()->withErrors(['clock_out' => $exception->getMessage()]);`.
- `ROUTE-0072` / `correctSession`: fields `clock_out_at`; success app/Http/Controllers/AttendanceController.php:254 `return redirect()->back()->with('success', "{$name} corrected. Timesheet #{$corrected->timesheet->id} recalculated.");`; app/Http/Controllers/AttendanceController.php:257 `return redirect()->back()->with('success', "{$name} corrected. The reason was recorded in the audit log.");`; failure app/Http/Controllers/AttendanceController.php:249 `return redirect()->back()->withErrors(['correct_session' => $exception->getMessage()]);`.
- `ROUTE-0073` / `endSession`: fields `reason`; success app/Http/Controllers/AttendanceController.php:396 `return redirect()->back()->with('success', "Session ended for {$name}. Draft timesheet #{$closed->timesheet->id} synced.");`; app/Http/Controllers/AttendanceController.php:399 `return redirect()->back()->with('success', "Session ended for {$name}.");`; failure app/Http/Controllers/AttendanceController.php:391 `return redirect()->back()->withErrors(['end_session' => $exception->getMessage()]);`.

## Failure and recovery paths

- `clockIn`: app/Http/Controllers/AttendanceController.php:281 `abort(403);`; app/Http/Controllers/AttendanceController.php:291 `return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);`.
- `clockOut`: app/Http/Controllers/AttendanceController.php:334 `abort(403);`; app/Http/Controllers/AttendanceController.php:343 `->withErrors(['clock_out' => $exception->getMessage()])`; app/Http/Controllers/AttendanceController.php:355 `->withErrors(['clock_out' => $exception->getMessage()])`; app/Http/Controllers/AttendanceController.php:358 `return redirect()->back()->withErrors(['clock_out' => $exception->getMessage()]);`.
- `correctSession`: app/Http/Controllers/AttendanceController.php:249 `return redirect()->back()->withErrors(['correct_session' => $exception->getMessage()]);`.
- `endSession`: app/Http/Controllers/AttendanceController.php:391 `return redirect()->back()->withErrors(['end_session' => $exception->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/AttendanceController.php:159 `return $mapped;`; app/Http/Controllers/AttendanceController.php:162 `return Inertia::render('attendance/index', [`; app/Http/Controllers/AttendanceController.php:291 `return redirect()->back()->withErrors(['clock_in' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:294 `return redirect()->back()->with('success', 'Clocked in successfully.');`; app/Http/Controllers/AttendanceController.php:342 `return redirect()->back()`; app/Http/Controllers/AttendanceController.php:348 `return response()->json([`; app/Http/Controllers/AttendanceController.php:354 `return redirect()->to(route('my-day').'#clock')`; app/Http/Controllers/AttendanceController.php:358 `return redirect()->back()->withErrors(['clock_out' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:365 `return redirect()->back()->with('success', "Clocked out. Draft timesheet #{$closed->timesheet->id} synced.");`; app/Http/Controllers/AttendanceController.php:368 `return redirect()->back()->with('success', 'Clocked out successfully.');`; app/Http/Controllers/AttendanceController.php:249 `return redirect()->back()->withErrors(['correct_session' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:254 `return redirect()->back()->with('success', "{$name} corrected. Timesheet #{$corrected->timesheet->id} recalculated.");`; app/Http/Controllers/AttendanceController.php:257 `return redirect()->back()->with('success', "{$name} corrected. The reason was recorded in the audit log.");`; app/Http/Controllers/AttendanceController.php:385 `return redirect()->back()->with('info', 'This session was already closed.');`; app/Http/Controllers/AttendanceController.php:391 `return redirect()->back()->withErrors(['end_session' => $exception->getMessage()]);`; app/Http/Controllers/AttendanceController.php:396 `return redirect()->back()->with('success', "Session ended for {$name}. Draft timesheet #{$closed->timesheet->id} synced.");`; app/Http/Controllers/AttendanceController.php:399 `return redirect()->back()->with('success', "Session ended for {$name}.");`; audit calls app/Http/Controllers/AttendanceController.php:275 `AuditLogger::log('attendance.clockIn.unauthorized', $shift, [`; app/Http/Controllers/AttendanceController.php:327 `AuditLogger::log('attendance.clockOut.unauthorized', $session, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD attendance` — `attendance.index` — `App\Http\Controllers\AttendanceController@index` — `app/Http/Controllers/AttendanceController.php:28` — middleware `web, auth, permission:timesheets.viewAny|timesheets.viewAssigned|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST attendance/clock-in` — `attendance.clockIn` — `App\Http\Controllers\AttendanceController@clockIn` — `app/Http/Controllers/AttendanceController.php:260` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST attendance/clock-out` — `attendance.clockOut` — `App\Http\Controllers\AttendanceController@clockOut` — `app/Http/Controllers/AttendanceController.php:297` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST attendance/sessions/{session}/correct` — `attendance.sessions.correct` — `App\Http\Controllers\AttendanceController@correctSession` — `app/Http/Controllers/AttendanceController.php:225` — middleware `web, auth, permission:timesheets.manageAny|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `POST attendance/sessions/{session}/end` — `attendance.sessions.end` — `App\Http\Controllers\AttendanceController@endSession` — `app/Http/Controllers/AttendanceController.php:375` — middleware `web, auth, permission:timesheets.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AttendanceController.php`.
- Exact render/action page relationships: `resources/js/pages/attendance/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
