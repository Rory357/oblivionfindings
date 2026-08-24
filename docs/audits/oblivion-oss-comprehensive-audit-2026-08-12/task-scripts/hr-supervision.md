# HR-SUPERVISION: Supervision

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-SUPERVISION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/supervision/{note}` (`hr.performance.supervision.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/supervision/{note}` (`hr.performance.supervision.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/performance/supervision/{note}/edit` (`hr.performance.supervision.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/SupervisionController.php:393-399`.
3. Use `GET|HEAD hr/performance/supervision/create` (`hr.performance.supervision.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/SupervisionController.php:322-328`.
4. Invoke only the owning control for `POST hr/performance/supervision` (`hr.performance.supervision.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/SupervisionController.php:353-387`; `employee_user_id`.
5. Invoke only the owning control for `PUT hr/performance/supervision/{note}` (`hr.performance.supervision.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/SupervisionController.php:404-432`; `session_date`.
6. Invoke only the owning control for `POST hr/performance/supervision/{note}/acknowledge` (`hr.performance.supervision.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/SupervisionController.php:437-455`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1654` at `app/Http/Controllers/Hr/SupervisionController.php:353`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1655` at `app/Http/Controllers/Hr/SupervisionController.php:333`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1656` at `app/Http/Controllers/Hr/SupervisionController.php:404`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1657` at `app/Http/Controllers/Hr/SupervisionController.php:437`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1658` at `app/Http/Controllers/Hr/SupervisionController.php:393`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1659` at `app/Http/Controllers/Hr/SupervisionController.php:322`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/performance/show-supervision.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1654` / `store`: fields `employee_user_id`; success app/Http/Controllers/Hr/SupervisionController.php:386 `return redirect()->back()->with('success', 'Supervision note recorded.');`; failure app/Http/Controllers/Hr/SupervisionController.php:376 `abort(404);`.
- `ROUTE-1656` / `update`: fields `session_date`; success app/Http/Controllers/Hr/SupervisionController.php:431 `return redirect()->back()->with('success', 'Supervision note updated.');`.
- `ROUTE-1657` / `acknowledge`: success app/Http/Controllers/Hr/SupervisionController.php:454 `return redirect()->back()->with('success', 'Supervision note acknowledged.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Hr/SupervisionController.php:376 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/SupervisionController.php:379 `HrSupervisionNote::create([`; app/Http/Controllers/Hr/SupervisionController.php:429 `$note->update($data);`; app/Http/Controllers/Hr/SupervisionController.php:447 `$note->update([`; responses app/Http/Controllers/Hr/SupervisionController.php:386 `return redirect()->back()->with('success', 'Supervision note recorded.');`; app/Http/Controllers/Hr/SupervisionController.php:342 `return Inertia::render('hr/performance/show-supervision', [`; app/Http/Controllers/Hr/SupervisionController.php:431 `return redirect()->back()->with('success', 'Supervision note updated.');`; app/Http/Controllers/Hr/SupervisionController.php:454 `return redirect()->back()->with('success', 'Supervision note acknowledged.');`; app/Http/Controllers/Hr/SupervisionController.php:398 `return redirect()->route('hr.performance.index');`; app/Http/Controllers/Hr/SupervisionController.php:327 `return redirect()->route('hr.performance.index');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/performance/supervision` — `hr.performance.supervision.store` — `App\Http\Controllers\Hr\SupervisionController@store` — `app/Http/Controllers/Hr/SupervisionController.php:353` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/supervision/{note}` — `hr.performance.supervision.show` — `App\Http\Controllers\Hr\SupervisionController@show` — `app/Http/Controllers/Hr/SupervisionController.php:333` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/performance/supervision/{note}` — `hr.performance.supervision.update` — `App\Http\Controllers\Hr\SupervisionController@update` — `app/Http/Controllers/Hr/SupervisionController.php:404` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/supervision/{note}/acknowledge` — `hr.performance.supervision.acknowledge` — `App\Http\Controllers\Hr\SupervisionController@acknowledge` — `app/Http/Controllers/Hr/SupervisionController.php:437` — middleware `web, auth, permission:hr.performance.view`
- `GET|HEAD hr/performance/supervision/{note}/edit` — `hr.performance.supervision.edit` — `App\Http\Controllers\Hr\SupervisionController@edit` — `app/Http/Controllers/Hr/SupervisionController.php:393` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/supervision/create` — `hr.performance.supervision.create` — `App\Http\Controllers\Hr\SupervisionController@create` — `app/Http/Controllers/Hr/SupervisionController.php:322` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/SupervisionController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/performance/show-supervision.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
