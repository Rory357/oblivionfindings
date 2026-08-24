# CAP-OPS-ATTENDANCE-HANDOVER: Attendance handover submission and acknowledgement

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
2. Invoke only the owning control for `POST attendance/handover` (`attendance.handover.submit`, action `submitHandover`). Source category: **created/recorded**; controller `app/Http/Controllers/AttendanceController.php:489-547`; `shift_id`.
3. Invoke only the owning control for `PATCH attendance/handover/{handover}/acknowledge` (`attendance.handover.acknowledge`, action `acknowledgeHandover`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/AttendanceController.php:557-590`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `submitHandover` / `ROUTE-0070` at `app/Http/Controllers/AttendanceController.php:489`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeHandover` / `ROUTE-0071` at `app/Http/Controllers/AttendanceController.php:557`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0070` / `submitHandover`: fields `shift_id`; success app/Http/Controllers/AttendanceController.php:546 `return redirect()->back()->with('success', 'Handover saved for the next shift.');`; failure app/Http/Controllers/AttendanceController.php:510 `abort(403);`; app/Http/Controllers/AttendanceController.php:541 `return redirect()->back()->withErrors([`.
- `ROUTE-0071` / `acknowledgeHandover`: success app/Http/Controllers/AttendanceController.php:589 `return redirect()->back()->with('success', 'Handover marked as read.');`; failure app/Http/Controllers/AttendanceController.php:584 `return redirect()->back()->withErrors([`.

## Failure and recovery paths

- `submitHandover`: app/Http/Controllers/AttendanceController.php:510 `abort(403);`; app/Http/Controllers/AttendanceController.php:541 `return redirect()->back()->withErrors([`.
- `acknowledgeHandover`: app/Http/Controllers/AttendanceController.php:584 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/AttendanceController.php:539 `$this->handoverService->save($shift, $auth, $payload);`; app/Http/Controllers/AttendanceController.php:579 `$handover->forceFill(['incoming_staff_id' => $auth->id])->save();`; responses app/Http/Controllers/AttendanceController.php:541 `return redirect()->back()->withErrors([`; app/Http/Controllers/AttendanceController.php:546 `return redirect()->back()->with('success', 'Handover saved for the next shift.');`; app/Http/Controllers/AttendanceController.php:584 `return redirect()->back()->withErrors([`; app/Http/Controllers/AttendanceController.php:589 `return redirect()->back()->with('success', 'Handover marked as read.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST attendance/handover` — `attendance.handover.submit` — `App\Http\Controllers\AttendanceController@submitHandover` — `app/Http/Controllers/AttendanceController.php:489` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`
- `PATCH attendance/handover/{handover}/acknowledge` — `attendance.handover.acknowledge` — `App\Http\Controllers\AttendanceController@acknowledgeHandover` — `app/Http/Controllers/AttendanceController.php:557` — middleware `web, auth, permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/AttendanceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
