# HR-POSITION: Position

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`
- Owning module: Human resources
- Legacy family: `HR-POSITION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/positions` (`hr.positions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.employees.viewAny`, `permission:hr.employees.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/positions` (`hr.positions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/positions/{position}` (`hr.positions.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PositionController.php:126-172`.
3. Use `GET|HEAD hr/positions/{position}/edit` (`hr.positions.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PositionController.php:174-202`.
4. Use `GET|HEAD hr/positions/create` (`hr.positions.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PositionController.php:47-64`.
5. Invoke only the owning control for `POST hr/positions` (`hr.positions.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PositionController.php:66-124`; `title`.
6. Invoke only the owning control for `PUT hr/positions/{position}` (`hr.positions.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PositionController.php:204-230`; `title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1660` at `app/Http/Controllers/Hr/PositionController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1661` at `app/Http/Controllers/Hr/PositionController.php:66`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1662` at `app/Http/Controllers/Hr/PositionController.php:126`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1663` at `app/Http/Controllers/Hr/PositionController.php:204`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1664` at `app/Http/Controllers/Hr/PositionController.php:174`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1665` at `app/Http/Controllers/Hr/PositionController.php:47`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/positions/create.tsx`, `resources/js/pages/hr/positions/edit.tsx`, `resources/js/pages/hr/positions/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1661` / `store`: fields `title`; success app/Http/Controllers/Hr/PositionController.php:123 `return redirect()->back()->with('success', $message);`.
- `ROUTE-1663` / `update`: fields `title`; success app/Http/Controllers/Hr/PositionController.php:229 `return redirect()->back()->with('success', 'Position updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PositionController.php:103 `HrJobRequisition::create([`; responses app/Http/Controllers/Hr/PositionController.php:44 `return redirect()->route('hr.people.index', $params);`; app/Http/Controllers/Hr/PositionController.php:123 `return redirect()->back()->with('success', $message);`; app/Http/Controllers/Hr/PositionController.php:137 `return Inertia::render('hr/positions/show', [`; app/Http/Controllers/Hr/PositionController.php:229 `return redirect()->back()->with('success', 'Position updated.');`; app/Http/Controllers/Hr/PositionController.php:193 `return Inertia::render('hr/positions/edit', [`; app/Http/Controllers/Hr/PositionController.php:60 `return Inertia::render('hr/positions/create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/positions` — `hr.positions.index` — `App\Http\Controllers\Hr\PositionController@index` — `app/Http/Controllers/Hr/PositionController.php:28` — middleware `web, auth, permission:hr.employees.viewAny`
- `POST hr/positions` — `hr.positions.store` — `App\Http\Controllers\Hr\PositionController@store` — `app/Http/Controllers/Hr/PositionController.php:66` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/positions/{position}` — `hr.positions.show` — `App\Http\Controllers\Hr\PositionController@show` — `app/Http/Controllers/Hr/PositionController.php:126` — middleware `web, auth, permission:hr.employees.viewAny`
- `PUT hr/positions/{position}` — `hr.positions.update` — `App\Http\Controllers\Hr\PositionController@update` — `app/Http/Controllers/Hr/PositionController.php:204` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/positions/{position}/edit` — `hr.positions.edit` — `App\Http\Controllers\Hr\PositionController@edit` — `app/Http/Controllers/Hr/PositionController.php:174` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`
- `GET|HEAD hr/positions/create` — `hr.positions.create` — `App\Http\Controllers\Hr\PositionController@create` — `app/Http/Controllers/Hr/PositionController.php:47` — middleware `web, auth, permission:hr.employees.viewAny, permission:hr.employees.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PositionController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/positions/create.tsx`, `resources/js/pages/hr/positions/edit.tsx`, `resources/js/pages/hr/positions/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
