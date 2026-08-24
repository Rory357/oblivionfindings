# CAP-HR-LEAVE-REQUEST-DECISION: Leave requests approval and cancellation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`, `permission:hr.leave.approve|hr.leave.manage`
- Owning module: Human resources
- Legacy family: `HR-LEAVE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/leave` (`hr.leave.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`, `permission:hr.leave.approve|hr.leave.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`, `permission:hr.leave.approve|hr.leave.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/leave` (`hr.leave.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/leave/{leaveRequest}` (`hr.leave.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/LeaveController.php:522-564`.
3. Use `GET|HEAD hr/leave/create` (`hr.leave.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/LeaveController.php:509-516`.
4. Use `GET|HEAD hr/leave/export` (`hr.leave.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/LeaveController.php:400-434`.
5. Use `GET|HEAD hr/leave/preview` (`hr.leave.preview`, action `previewLeave`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/LeaveController.php:283-310`.
6. Invoke only the owning control for `POST hr/leave` (`hr.leave.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/LeaveController.php:570-613`; FormRequest `app/Http/Requests/Hr/StoreLeaveRequestFormRequest.php:16`; `user_id`, `leave_type`, `period`, `starts_at`, `ends_at`, `hours_requested`, `reason`, `supporting_doc`.
7. Invoke only the owning control for `POST hr/leave/{leaveRequest}/approve` (`hr.leave.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/LeaveController.php:619-651`; FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; `review_notes`.
8. Invoke only the owning control for `POST hr/leave/{leaveRequest}/decline` (`hr.leave.decline`, action `decline`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/LeaveController.php:657-687`; FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; `review_notes`.
9. Invoke only the owning control for `POST hr/leave/{leaveRequest}/sla-due` (`hr.leave.sla-due`, action `setSlaDue`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/LeaveController.php:773-804`; FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; `hours`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1487` at `app/Http/Controllers/Hr/LeaveController.php:33`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1488` at `app/Http/Controllers/Hr/LeaveController.php:570`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1489` at `app/Http/Controllers/Hr/LeaveController.php:522`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1490` at `app/Http/Controllers/Hr/LeaveController.php:619`; it is not runtime-observed.
- **rejected/returned** is applicable only to `decline` / `ROUTE-1491` at `app/Http/Controllers/Hr/LeaveController.php:657`; it is not runtime-observed.
- **updated/revised** is applicable only to `setSlaDue` / `ROUTE-1492` at `app/Http/Controllers/Hr/LeaveController.php:773`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1499` at `app/Http/Controllers/Hr/LeaveController.php:509`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1501` at `app/Http/Controllers/Hr/LeaveController.php:400`; it is not runtime-observed.
- **information presented** is applicable only to `previewLeave` / `ROUTE-1506` at `app/Http/Controllers/Hr/LeaveController.php:283`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/leave/index.tsx`, `resources/js/pages/hr/leave/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1488` / `store`: FormRequest `app/Http/Requests/Hr/StoreLeaveRequestFormRequest.php:16`; fields `user_id`, `leave_type`, `period`, `starts_at`, `ends_at`, `hours_requested`, `reason`, `supporting_doc`; success app/Http/Controllers/Hr/LeaveController.php:612 `return redirect()->back()->with('success', 'Leave request submitted.');`; failure app/Http/Controllers/Hr/LeaveController.php:591 `abort(404);`; app/Http/Controllers/Hr/LeaveController.php:609 `return redirect()->back()->withErrors(['leave_request' => $e->getMessage()]);`.
- `ROUTE-1489` / `show`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`.
- `ROUTE-1490` / `approve`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; fields `review_notes`; success app/Http/Controllers/Hr/LeaveController.php:650 `return redirect()->back()->with('success', 'Leave request approved.');`.
- `ROUTE-1491` / `decline`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; fields `review_notes`; success app/Http/Controllers/Hr/LeaveController.php:686 `return redirect()->back()->with('success', 'Leave request declined.');`.
- `ROUTE-1492` / `setSlaDue`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`; fields `hours`; success app/Http/Controllers/Hr/LeaveController.php:803 `return redirect()->back()->with('success', 'SLA due date updated.');`; failure app/Http/Controllers/Hr/LeaveController.php:781 `return redirect()->back()->withErrors([`.
- `ROUTE-1501` / `export`: FormRequest `app/Domain/Hr/Models/HrLeaveRequest.php:line unresolved`.
- `ROUTE-1506` / `previewLeave`: fields `user_id`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/LeaveController.php:591 `abort(404);`; app/Http/Controllers/Hr/LeaveController.php:609 `return redirect()->back()->withErrors(['leave_request' => $e->getMessage()]);`.
- `setSlaDue`: app/Http/Controllers/Hr/LeaveController.php:781 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/LeaveController.php:790 `$leaveRequest->update([`; responses app/Http/Controllers/Hr/LeaveController.php:121 `return Inertia::render('hr/leave/index', [`; app/Http/Controllers/Hr/LeaveController.php:609 `return redirect()->back()->withErrors(['leave_request' => $e->getMessage()]);`; app/Http/Controllers/Hr/LeaveController.php:612 `return redirect()->back()->with('success', 'Leave request submitted.');`; app/Http/Controllers/Hr/LeaveController.php:541 `return Inertia::render('hr/leave/show', [`; app/Http/Controllers/Hr/LeaveController.php:647 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/LeaveController.php:650 `return redirect()->back()->with('success', 'Leave request approved.');`; app/Http/Controllers/Hr/LeaveController.php:683 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/LeaveController.php:686 `return redirect()->back()->with('success', 'Leave request declined.');`; app/Http/Controllers/Hr/LeaveController.php:781 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/LeaveController.php:803 `return redirect()->back()->with('success', 'SLA due date updated.');`; app/Http/Controllers/Hr/LeaveController.php:515 `return redirect()->route('hr.leave.index');`; app/Http/Controllers/Hr/LeaveController.php:427 `return $this->streamLeaveExport(`; app/Http/Controllers/Hr/LeaveController.php:306 `return response()->json(['error' => $e->getMessage()], 422);`; app/Http/Controllers/Hr/LeaveController.php:309 `return response()->json($preview);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/leave` — `hr.leave.index` — `App\Http\Controllers\Hr\LeaveController@index` — `app/Http/Controllers/Hr/LeaveController.php:33` — middleware `web, auth, permission:hr.leave.viewAny`
- `POST hr/leave` — `hr.leave.store` — `App\Http\Controllers\Hr\LeaveController@store` — `app/Http/Controllers/Hr/LeaveController.php:570` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `GET|HEAD hr/leave/{leaveRequest}` — `hr.leave.show` — `App\Http\Controllers\Hr\LeaveController@show` — `app/Http/Controllers/Hr/LeaveController.php:522` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `POST hr/leave/{leaveRequest}/approve` — `hr.leave.approve` — `App\Http\Controllers\Hr\LeaveController@approve` — `app/Http/Controllers/Hr/LeaveController.php:619` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `POST hr/leave/{leaveRequest}/decline` — `hr.leave.decline` — `App\Http\Controllers\Hr\LeaveController@decline` — `app/Http/Controllers/Hr/LeaveController.php:657` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `POST hr/leave/{leaveRequest}/sla-due` — `hr.leave.sla-due` — `App\Http\Controllers\Hr\LeaveController@setSlaDue` — `app/Http/Controllers/Hr/LeaveController.php:773` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.approve|hr.leave.manage`
- `GET|HEAD hr/leave/create` — `hr.leave.create` — `App\Http\Controllers\Hr\LeaveController@create` — `app/Http/Controllers/Hr/LeaveController.php:509` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `GET|HEAD hr/leave/export` — `hr.leave.export` — `App\Http\Controllers\Hr\LeaveController@export` — `app/Http/Controllers/Hr/LeaveController.php:400` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `GET|HEAD hr/leave/preview` — `hr.leave.preview` — `App\Http\Controllers\Hr\LeaveController@previewLeave` — `app/Http/Controllers/Hr/LeaveController.php:283` — middleware `web, auth, permission:hr.leave.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/LeaveController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/leave/index.tsx`, `resources/js/pages/hr/leave/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
