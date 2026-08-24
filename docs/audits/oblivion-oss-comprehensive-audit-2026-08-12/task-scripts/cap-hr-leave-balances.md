# CAP-HR-LEAVE-BALANCES: Leave balances ledger and adjustments

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`
- Owning module: Human resources
- Legacy family: `HR-LEAVE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/leave/balances` (`hr.leave.balances`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/leave/balances` (`hr.leave.balances`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/leave/balances/{user}/ledger` (`hr.leave.balances.ledger`, action `ledger`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/LeaveController.php:355-394`.
3. Use `GET|HEAD hr/leave/balances/export` (`hr.leave.balances.export`, action `exportBalances`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/LeaveController.php:436-468`.
4. Invoke only the owning control for `POST hr/leave/balances/adjust` (`hr.leave.balances.adjust`, action `adjustBalance`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/LeaveController.php:316-353`; `user_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `balances` / `ROUTE-1493` at `app/Http/Controllers/Hr/LeaveController.php:213`; it is not runtime-observed.
- **information presented** is applicable only to `ledger` / `ROUTE-1494` at `app/Http/Controllers/Hr/LeaveController.php:355`; it is not runtime-observed.
- **updated/revised** is applicable only to `adjustBalance` / `ROUTE-1495` at `app/Http/Controllers/Hr/LeaveController.php:316`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportBalances` / `ROUTE-1496` at `app/Http/Controllers/Hr/LeaveController.php:436`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/leave/balances.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1494` / `ledger`: failure app/Http/Controllers/Hr/LeaveController.php:360 `abort(403);`; app/Http/Controllers/Hr/LeaveController.php:367 `abort(404);`.
- `ROUTE-1495` / `adjustBalance`: fields `user_id`; success app/Http/Controllers/Hr/LeaveController.php:352 `return redirect()->back()->with('success', 'Balance adjusted — ledger entry recorded.');`; failure app/Http/Controllers/Hr/LeaveController.php:334 `abort(404);`; app/Http/Controllers/Hr/LeaveController.php:349 `return redirect()->back()->withErrors(['adjust' => $e->getMessage()]);`.

## Failure and recovery paths

- `ledger`: app/Http/Controllers/Hr/LeaveController.php:360 `abort(403);`; app/Http/Controllers/Hr/LeaveController.php:367 `abort(404);`.
- `adjustBalance`: app/Http/Controllers/Hr/LeaveController.php:334 `abort(404);`; app/Http/Controllers/Hr/LeaveController.php:349 `return redirect()->back()->withErrors(['adjust' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/LeaveController.php:241 `return [`; app/Http/Controllers/Hr/LeaveController.php:248 `return [`; app/Http/Controllers/Hr/LeaveController.php:262 `return Inertia::render('hr/leave/balances', [`; app/Http/Controllers/Hr/LeaveController.php:378 `return response()->json([`; app/Http/Controllers/Hr/LeaveController.php:349 `return redirect()->back()->withErrors(['adjust' => $e->getMessage()]);`; app/Http/Controllers/Hr/LeaveController.php:352 `return redirect()->back()->with('success', 'Balance adjusted — ledger entry recorded.');`; app/Http/Controllers/Hr/LeaveController.php:461 `return $this->streamLeaveExport(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/leave/balances` — `hr.leave.balances` — `App\Http\Controllers\Hr\LeaveController@balances` — `app/Http/Controllers/Hr/LeaveController.php:213` — middleware `web, auth, permission:hr.leave.viewAny`
- `GET|HEAD hr/leave/balances/{user}/ledger` — `hr.leave.balances.ledger` — `App\Http\Controllers\Hr\LeaveController@ledger` — `app/Http/Controllers/Hr/LeaveController.php:355` — middleware `web, auth, permission:hr.leave.viewAny`
- `POST hr/leave/balances/adjust` — `hr.leave.balances.adjust` — `App\Http\Controllers\Hr\LeaveController@adjustBalance` — `app/Http/Controllers/Hr/LeaveController.php:316` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `GET|HEAD hr/leave/balances/export` — `hr.leave.balances.export` — `App\Http\Controllers\Hr\LeaveController@exportBalances` — `app/Http/Controllers/Hr/LeaveController.php:436` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/LeaveController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/leave/balances.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
