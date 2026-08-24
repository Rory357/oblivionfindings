# OPS-SHIFT-SERIES: Shift Series

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny|shifts.viewAny|shifts.manageAny`, `permission:shifts.create`, `permission:rostering.viewAny|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT-SERIES`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/shifts/series` (`operations.shifts.series.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny|shifts.viewAny|shifts.manageAny`, `permission:shifts.create`, `permission:rostering.viewAny|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `role_scope:my-day`, `permission:rostering.viewAny|shifts.viewAny|shifts.manageAny`, `permission:shifts.create`, `permission:rostering.viewAny|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/shifts/series` (`operations.shifts.series.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/shifts/series/{series}` (`operations.shifts.series.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ShiftSeriesController.php:111-129`.
3. Invoke only the owning control for `POST operations/shifts/series` (`operations.shifts.series.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ShiftSeriesController.php:168-427`; `client_id`.
4. Invoke only the owning control for `PATCH operations/shifts/series/{series}/cancel-future` (`operations.shifts.series.cancel_future`, action `cancelFuture`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ShiftSeriesController.php:131-166`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2215` at `app/Http/Controllers/ShiftSeriesController.php:34`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2216` at `app/Http/Controllers/ShiftSeriesController.php:168`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2217` at `app/Http/Controllers/ShiftSeriesController.php:111`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelFuture` / `ROUTE-2218` at `app/Http/Controllers/ShiftSeriesController.php:131`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/shifts/series/Index.tsx`, `resources/js/pages/operations/shifts/series/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2216` / `store`: fields `client_id`; success app/Http/Controllers/ShiftSeriesController.php:426 `->with('success', 'Recurring shifts created ('.$result['count'].').');`; failure app/Http/Controllers/ShiftSeriesController.php:250 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:278 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:326 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:406 `throw $e;`.

## Failure and recovery paths

- `store`: app/Http/Controllers/ShiftSeriesController.php:250 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:278 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:326 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:406 `throw $e;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ShiftSeriesController.php:353 `$series = ShiftSeries::create([`; app/Http/Controllers/ShiftSeriesController.php:365 `$shift = Shift::create([`; app/Http/Controllers/ShiftSeriesController.php:388 `ShiftTask::create([`; app/Http/Controllers/ShiftSeriesController.php:154 `->update(['status' => 'cancelled']);`; app/Http/Controllers/ShiftSeriesController.php:155 `$shift->update(['status' => 'cancelled']);`; app/Http/Controllers/ShiftSeriesController.php:159 `$series->update(['status' => 'cancelled']);`; responses app/Http/Controllers/ShiftSeriesController.php:44 `return redirect()->route('operations.rostering.index', ['tab' => 'recurring']);`; app/Http/Controllers/ShiftSeriesController.php:72 `return inertia('operations/shifts/series/Index', [`; app/Http/Controllers/ShiftSeriesController.php:241 `return [`; app/Http/Controllers/ShiftSeriesController.php:250 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:278 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:320 `return response()->json([`; app/Http/Controllers/ShiftSeriesController.php:326 `return back()->withErrors([`; app/Http/Controllers/ShiftSeriesController.php:399 `return [`; app/Http/Controllers/ShiftSeriesController.php:419 `return response()->json([`; app/Http/Controllers/ShiftSeriesController.php:425 `return redirect($data['return_to'] ?? route('operations.shifts.index'))`; app/Http/Controllers/ShiftSeriesController.php:119 `return redirect()->route('operations.rostering.index', [`; app/Http/Controllers/ShiftSeriesController.php:125 `return inertia('operations/shifts/series/Show', [`; app/Http/Controllers/ShiftSeriesController.php:144 `return back()->with('error', 'There are no future active occurrences left to cancel.');`; app/Http/Controllers/ShiftSeriesController.php:162 `return redirect()->route('operations.shifts.series.show', $series)->with(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/shifts/series` — `operations.shifts.series.index` — `App\Http\Controllers\ShiftSeriesController@index` — `app/Http/Controllers/ShiftSeriesController.php:34` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny|shifts.viewAny|shifts.manageAny`
- `POST operations/shifts/series` — `operations.shifts.series.store` — `App\Http\Controllers\ShiftSeriesController@store` — `app/Http/Controllers/ShiftSeriesController.php:168` — middleware `web, auth, permission:shifts.create`
- `GET|HEAD operations/shifts/series/{series}` — `operations.shifts.series.show` — `App\Http\Controllers\ShiftSeriesController@show` — `app/Http/Controllers/ShiftSeriesController.php:111` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny|shifts.viewAny|shifts.manageAny`
- `PATCH operations/shifts/series/{series}/cancel-future` — `operations.shifts.series.cancel_future` — `App\Http\Controllers\ShiftSeriesController@cancelFuture` — `app/Http/Controllers/ShiftSeriesController.php:131` — middleware `web, auth, permission:rostering.viewAny|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ShiftSeriesController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/shifts/series/Index.tsx`, `resources/js/pages/operations/shifts/series/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
