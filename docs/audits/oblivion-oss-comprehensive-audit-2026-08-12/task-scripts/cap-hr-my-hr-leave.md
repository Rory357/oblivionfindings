# CAP-HR-MY-HR-LEAVE: My leave requests and balance preview

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/leave` (`hr.my.leave`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/leave` (`hr.my.leave`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/leave/preview` (`hr.my.leave.preview`, action `previewLeave`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:569-587`.
3. Invoke only the owning control for `POST hr/my/leave` (`hr.my.leave.store`, action `submitLeave`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:531-564`; `leave_type`.
4. Invoke only the owning control for `DELETE hr/my/leave/{leaveRequest}` (`hr.my.leave.cancel`, action `cancelLeave`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/MyHrController.php:652-665`; FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `leave` / `ROUTE-1525` at `app/Http/Controllers/Hr/MyHrController.php:487`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitLeave` / `ROUTE-1526` at `app/Http/Controllers/Hr/MyHrController.php:531`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelLeave` / `ROUTE-1527` at `app/Http/Controllers/Hr/MyHrController.php:652`; it is not runtime-observed.
- **information presented** is applicable only to `previewLeave` / `ROUTE-1528` at `app/Http/Controllers/Hr/MyHrController.php:569`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/leave.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1525` / `leave`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`.
- `ROUTE-1526` / `submitLeave`: fields `leave_type`; success app/Http/Controllers/Hr/MyHrController.php:563 `return redirect()->back()->with('success', 'Leave request submitted.');`.
- `ROUTE-1527` / `cancelLeave`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; success app/Http/Controllers/Hr/MyHrController.php:664 `return redirect()->back()->with('success', 'Leave request cancelled.');`; failure app/Http/Controllers/Hr/MyHrController.php:661 `return redirect()->back()->withErrors(['leave_request' => $exception->getMessage()]);`.
- `ROUTE-1528` / `previewLeave`: fields `leave_type`.

## Failure and recovery paths

- `cancelLeave`: app/Http/Controllers/Hr/MyHrController.php:661 `return redirect()->back()->withErrors(['leave_request' => $exception->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:521 `return Inertia::render('hr/my/leave', [`; app/Http/Controllers/Hr/MyHrController.php:547 `return redirect()->back()->with('error', 'A half-day can only be requested for a single day.');`; app/Http/Controllers/Hr/MyHrController.php:560 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:563 `return redirect()->back()->with('success', 'Leave request submitted.');`; app/Http/Controllers/Hr/MyHrController.php:661 `return redirect()->back()->withErrors(['leave_request' => $exception->getMessage()]);`; app/Http/Controllers/Hr/MyHrController.php:664 `return redirect()->back()->with('success', 'Leave request cancelled.');`; app/Http/Controllers/Hr/MyHrController.php:583 `return response()->json(['error' => $e->getMessage()], 422);`; app/Http/Controllers/Hr/MyHrController.php:586 `return response()->json($preview);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/leave` — `hr.my.leave` — `App\Http\Controllers\Hr\MyHrController@leave` — `app/Http/Controllers/Hr/MyHrController.php:487` — middleware `web, auth`
- `POST hr/my/leave` — `hr.my.leave.store` — `App\Http\Controllers\Hr\MyHrController@submitLeave` — `app/Http/Controllers/Hr/MyHrController.php:531` — middleware `web, auth`
- `DELETE hr/my/leave/{leaveRequest}` — `hr.my.leave.cancel` — `App\Http\Controllers\Hr\MyHrController@cancelLeave` — `app/Http/Controllers/Hr/MyHrController.php:652` — middleware `web, auth`
- `GET|HEAD hr/my/leave/preview` — `hr.my.leave.preview` — `App\Http\Controllers\Hr\MyHrController@previewLeave` — `app/Http/Controllers/Hr/MyHrController.php:569` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/leave.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
