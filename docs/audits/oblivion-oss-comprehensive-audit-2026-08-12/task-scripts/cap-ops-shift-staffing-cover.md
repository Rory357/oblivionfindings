# CAP-OPS-SHIFT-STAFFING-COVER: Shift assignment candidates auto-fill cover and replacement

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/shifts/{shift}/candidates` (`operations.shifts.candidates`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:shifts.manageAny`, `permission:shifts.update|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/shifts/{shift}/candidates` (`operations.shifts.candidates`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/shifts/{shift}/assign` (`operations.shifts.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/ShiftController.php:1908-1994`; `user_id`.
3. Invoke only the owning control for `POST operations/shifts/{shift}/auto-fill` (`operations.shifts.autoFill`, action `autoFill`). Source category: **mutation outcome source gap (autoFill)**; controller `app/Http/Controllers/ShiftController.php:1996-2077`; `return_to`.
4. Invoke only the owning control for `POST operations/shifts/{shift}/broadcast` (`operations.shifts.broadcast`, action `broadcastNeedsCover`). Source category: **mutation outcome source gap (broadcastNeedsCover)**; controller `app/Http/Controllers/ShiftController.php:1620-1698`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/shifts/{shift}/replacement-request` (`operations.shifts.replacement.request`, action `requestReplacement`). Source category: **mutation outcome source gap (requestReplacement)**; controller `app/Http/Controllers/ShiftController.php:2109-2127`; `reason`.
6. Invoke only the owning control for `PATCH operations/shifts/{shift}/replacement-request/cancel` (`operations.shifts.replacement.cancel`, action `cancelReplacement`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ShiftController.php:2129-2145`; no exact validation fields extracted.
7. Invoke only the owning control for `POST operations/shifts/{shift}/unassign` (`operations.shifts.unassign`, action `unassign`). Source category: **unassigned**; controller `app/Http/Controllers/ShiftController.php:2079-2107`; `reason`.

## Source-applicable states and transitions

- **assigned** is applicable only to `assign` / `ROUTE-2195` at `app/Http/Controllers/ShiftController.php:1908`; it is not runtime-observed.
- **mutation outcome source gap (autoFill)** is applicable only to `autoFill` / `ROUTE-2196` at `app/Http/Controllers/ShiftController.php:1996`; it is not runtime-observed.
- **mutation outcome source gap (broadcastNeedsCover)** is applicable only to `broadcastNeedsCover` / `ROUTE-2197` at `app/Http/Controllers/ShiftController.php:1620`; it is not runtime-observed.
- **information presented** is applicable only to `candidates` / `ROUTE-2199` at `app/Http/Controllers/ShiftController.php:1880`; it is not runtime-observed.
- **mutation outcome source gap (requestReplacement)** is applicable only to `requestReplacement` / `ROUTE-2208` at `app/Http/Controllers/ShiftController.php:2109`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelReplacement` / `ROUTE-2209` at `app/Http/Controllers/ShiftController.php:2129`; it is not runtime-observed.
- **unassigned** is applicable only to `unassign` / `ROUTE-2212` at `app/Http/Controllers/ShiftController.php:2079`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2195` / `assign`: fields `user_id`; success app/Http/Controllers/ShiftController.php:1993 `return redirect($data['return_to'] ?? url('/operations/rostering'))->with('success', 'Shift assigned.');`; failure app/Http/Controllers/ShiftController.php:1937 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1960 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1990 `throw $e;`.
- `ROUTE-2196` / `autoFill`: fields `return_to`; success app/Http/Controllers/ShiftController.php:2076 `return redirect($returnTo)->with('success', $message);`; failure app/Http/Controllers/ShiftController.php:2067 `throw $e;`.
- `ROUTE-2197` / `broadcastNeedsCover`: success app/Http/Controllers/ShiftController.php:1697 `return back()->with('success', "Broadcast sent to {$sent} eligible staff.");`.
- `ROUTE-2208` / `requestReplacement`: fields `reason`; success app/Http/Controllers/ShiftController.php:2126 `return back()->with('success', 'Replacement request created.');`.
- `ROUTE-2209` / `cancelReplacement`: success app/Http/Controllers/ShiftController.php:2144 `return back()->with('success', 'Replacement request cancelled.');`.
- `ROUTE-2212` / `unassign`: fields `reason`; success app/Http/Controllers/ShiftController.php:2106 `return redirect($returnTo)->with('success', 'Shift unassigned.');`.

## Failure and recovery paths

- `assign`: app/Http/Controllers/ShiftController.php:1937 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1960 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1990 `throw $e;`.
- `autoFill`: app/Http/Controllers/ShiftController.php:2067 `throw $e;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/ShiftController.php:1916 `return back()->with('error', 'This shift is locked and can no longer be reassigned.');`; app/Http/Controllers/ShiftController.php:1937 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1945 `return back()`; app/Http/Controllers/ShiftController.php:1960 `return back()->withErrors([`; app/Http/Controllers/ShiftController.php:1993 `return redirect($data['return_to'] ?? url('/operations/rostering'))->with('success', 'Shift assigned.');`; app/Http/Controllers/ShiftController.php:2003 `return back()->with('error', 'This shift is locked and cannot be auto-filled.');`; app/Http/Controllers/ShiftController.php:2007 `return back()->with('error', 'This shift is already assigned.');`; app/Http/Controllers/ShiftController.php:2024 `return back()->with('error', 'Auto-fill could not enumerate eligible candidates.');`; app/Http/Controllers/ShiftController.php:2053 `return back()->with('warning', 'No eligible candidate was found for auto-fill.');`; app/Http/Controllers/ShiftController.php:2076 `return redirect($returnTo)->with('success', $message);`; app/Http/Controllers/ShiftController.php:1627 `return back()->with('error', 'Only unassigned shifts can be broadcast for cover.');`; app/Http/Controllers/ShiftController.php:1631 `return back()->with('error', 'Locked shifts cannot be broadcast.');`; app/Http/Controllers/ShiftController.php:1635 `return back()->with('error', 'Shift must have a start and end time before broadcasting.');`; app/Http/Controllers/ShiftController.php:1652 `return back()->with('error', 'Unable to enumerate eligible candidates for broadcast.');`; app/Http/Controllers/ShiftController.php:1694 `return back()->with('warning', 'No eligible candidates were notified.');`; app/Http/Controllers/ShiftController.php:1697 `return back()->with('success', "Broadcast sent to {$sent} eligible staff.");`; app/Http/Controllers/ShiftController.php:1887 `return response()->json([`; app/Http/Controllers/ShiftController.php:1901 `return response()->json([`; app/Http/Controllers/ShiftController.php:2126 `return back()->with('success', 'Replacement request created.');`; app/Http/Controllers/ShiftController.php:2144 `return back()->with('success', 'Replacement request cancelled.');`; app/Http/Controllers/ShiftController.php:2086 `return back()->with('error', 'This shift is locked and can no longer be unassigned.');`; app/Http/Controllers/ShiftController.php:2090 `return back()->with('error', 'In-progress shifts cannot be unassigned. Use the replacement workflow instead.');`; app/Http/Controllers/ShiftController.php:2106 `return redirect($returnTo)->with('success', 'Shift unassigned.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/ShiftController.php:1675 `$candidate->notify(new ShiftBroadcastNotification(`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST operations/shifts/{shift}/assign` — `operations.shifts.assign` — `App\Http\Controllers\ShiftController@assign` — `app/Http/Controllers/ShiftController.php:1908` — middleware `web, auth, permission:shifts.manageAny`
- `POST operations/shifts/{shift}/auto-fill` — `operations.shifts.autoFill` — `App\Http\Controllers\ShiftController@autoFill` — `app/Http/Controllers/ShiftController.php:1996` — middleware `web, auth, permission:shifts.manageAny`
- `POST operations/shifts/{shift}/broadcast` — `operations.shifts.broadcast` — `App\Http\Controllers\ShiftController@broadcastNeedsCover` — `app/Http/Controllers/ShiftController.php:1620` — middleware `web, auth, permission:shifts.manageAny`
- `GET|HEAD operations/shifts/{shift}/candidates` — `operations.shifts.candidates` — `App\Http\Controllers\ShiftController@candidates` — `app/Http/Controllers/ShiftController.php:1880` — middleware `web, auth, permission:shifts.manageAny`
- `POST operations/shifts/{shift}/replacement-request` — `operations.shifts.replacement.request` — `App\Http\Controllers\ShiftController@requestReplacement` — `app/Http/Controllers/ShiftController.php:2109` — middleware `web, auth, permission:shifts.update|shifts.manageAny`
- `PATCH operations/shifts/{shift}/replacement-request/cancel` — `operations.shifts.replacement.cancel` — `App\Http\Controllers\ShiftController@cancelReplacement` — `app/Http/Controllers/ShiftController.php:2129` — middleware `web, auth, permission:shifts.update|shifts.manageAny`
- `POST operations/shifts/{shift}/unassign` — `operations.shifts.unassign` — `App\Http\Controllers\ShiftController@unassign` — `app/Http/Controllers/ShiftController.php:2079` — middleware `web, auth, permission:shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ShiftController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
