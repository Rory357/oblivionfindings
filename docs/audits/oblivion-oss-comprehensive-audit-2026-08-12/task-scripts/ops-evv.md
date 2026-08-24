# OPS-EVV: Evv

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:evv.viewAny`, `permission:evv.verify`, `permission:evv.record`
- Owning module: Operations and rostering
- Legacy family: `OPS-EVV`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/evv` (`operations.evv.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:evv.viewAny`, `permission:evv.verify`, `permission:evv.record`.
- Exact middleware atoms: `web`, `auth`, `permission:evv.viewAny`, `permission:evv.verify`, `permission:evv.record`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/evv` (`operations.evv.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/evv/{record}` (`operations.evv.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/EvvController.php:47-60`.
3. Invoke only the owning control for `PATCH operations/evv/{record}/verify` (`operations.evv.verify`, action `verify`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/EvvController.php:140-161`; `verification_status`.
4. Invoke only the owning control for `POST operations/evv/check-in` (`operations.evv.check_in`, action `checkIn`). Source category: **mutation outcome source gap (checkIn)**; controller `app/Http/Controllers/Operations/EvvController.php:62-99`; `shift_id`.
5. Invoke only the owning control for `POST operations/evv/check-out` (`operations.evv.check_out`, action `checkOut`). Source category: **mutation outcome source gap (checkOut)**; controller `app/Http/Controllers/Operations/EvvController.php:101-138`; `record_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2058` at `app/Http/Controllers/Operations/EvvController.php:12`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2059` at `app/Http/Controllers/Operations/EvvController.php:47`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `verify` / `ROUTE-2060` at `app/Http/Controllers/Operations/EvvController.php:140`; it is not runtime-observed.
- **mutation outcome source gap (checkIn)** is applicable only to `checkIn` / `ROUTE-2061` at `app/Http/Controllers/Operations/EvvController.php:62`; it is not runtime-observed.
- **mutation outcome source gap (checkOut)** is applicable only to `checkOut` / `ROUTE-2062` at `app/Http/Controllers/Operations/EvvController.php:101`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/evv/Index.tsx`, `resources/js/pages/operations/evv/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2058` / `index`: fields `verification_status`.
- `ROUTE-2060` / `verify`: fields `verification_status`; success app/Http/Controllers/Operations/EvvController.php:160 `return redirect()->back()->with('success', 'Record ' . $data['verification_status'] . '.');`.
- `ROUTE-2061` / `checkIn`: fields `shift_id`; success app/Http/Controllers/Operations/EvvController.php:98 `return redirect()->back()->with('success', 'Checked in.');`.
- `ROUTE-2062` / `checkOut`: fields `record_id`; success app/Http/Controllers/Operations/EvvController.php:137 `return redirect()->back()->with('success', 'Checked out.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/EvvController.php:154 `$record->update([`; app/Http/Controllers/Operations/EvvController.php:86 `$record = EvvRecord::create([`; app/Http/Controllers/Operations/EvvController.php:130 `$record->update([`; responses app/Http/Controllers/Operations/EvvController.php:40 `return inertia('operations/evv/Index', [`; app/Http/Controllers/Operations/EvvController.php:57 `return inertia('operations/evv/Show', [`; app/Http/Controllers/Operations/EvvController.php:160 `return redirect()->back()->with('success', 'Record ' . $data['verification_status'] . '.');`; app/Http/Controllers/Operations/EvvController.php:98 `return redirect()->back()->with('success', 'Checked in.');`; app/Http/Controllers/Operations/EvvController.php:137 `return redirect()->back()->with('success', 'Checked out.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/evv` — `operations.evv.index` — `App\Http\Controllers\Operations\EvvController@index` — `app/Http/Controllers/Operations/EvvController.php:12` — middleware `web, auth, permission:evv.viewAny`
- `GET|HEAD operations/evv/{record}` — `operations.evv.show` — `App\Http\Controllers\Operations\EvvController@show` — `app/Http/Controllers/Operations/EvvController.php:47` — middleware `web, auth, permission:evv.viewAny`
- `PATCH operations/evv/{record}/verify` — `operations.evv.verify` — `App\Http\Controllers\Operations\EvvController@verify` — `app/Http/Controllers/Operations/EvvController.php:140` — middleware `web, auth, permission:evv.verify`
- `POST operations/evv/check-in` — `operations.evv.check_in` — `App\Http\Controllers\Operations\EvvController@checkIn` — `app/Http/Controllers/Operations/EvvController.php:62` — middleware `web, auth, permission:evv.record`
- `POST operations/evv/check-out` — `operations.evv.check_out` — `App\Http\Controllers\Operations\EvvController@checkOut` — `app/Http/Controllers/Operations/EvvController.php:101` — middleware `web, auth, permission:evv.record`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/EvvController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/evv/Index.tsx`, `resources/js/pages/operations/evv/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
