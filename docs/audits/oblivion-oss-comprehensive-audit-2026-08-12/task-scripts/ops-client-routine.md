# OPS-CLIENT-ROUTINE: Client Routine

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-ROUTINE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/routines` (`operations.clients.routines.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/routines` (`operations.clients.routines.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/routines/{block}` (`operations.clients.routines.upsert`, action `upsertBlock`). Source category: **mutation outcome source gap (upsertBlock)**; controller `app/Http/Controllers/Operations/ClientRoutineController.php:34-59`; `body`.
3. Invoke only the owning control for `POST operations/clients/{client}/routines/reorder` (`operations.clients.routines.reorder`, action `reorder`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientRoutineController.php:61-80`; `blocks`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2046` at `app/Http/Controllers/Operations/ClientRoutineController.php:18`; it is not runtime-observed.
- **mutation outcome source gap (upsertBlock)** is applicable only to `upsertBlock` / `ROUTE-2047` at `app/Http/Controllers/Operations/ClientRoutineController.php:34`; it is not runtime-observed.
- **updated/revised** is applicable only to `reorder` / `ROUTE-2048` at `app/Http/Controllers/Operations/ClientRoutineController.php:61`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2047` / `upsertBlock`: fields `body`; success app/Http/Controllers/Operations/ClientRoutineController.php:58 `return back()->with('success', 'Routine updated.');`.
- `ROUTE-2048` / `reorder`: fields `blocks`; success app/Http/Controllers/Operations/ClientRoutineController.php:79 `return back()->with('success', 'Routine order updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientRoutineController.php:76 `->update(['display_order' => $block['display_order'], 'updated_by' => $request->user()?->id]);`; responses app/Http/Controllers/Operations/ClientRoutineController.php:27 `return ClientRoutine::query()`; app/Http/Controllers/Operations/ClientRoutineController.php:58 `return back()->with('success', 'Routine updated.');`; app/Http/Controllers/Operations/ClientRoutineController.php:79 `return back()->with('success', 'Routine order updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/routines` — `operations.clients.routines.index` — `App\Http\Controllers\Operations\ClientRoutineController@index` — `app/Http/Controllers/Operations/ClientRoutineController.php:18` — middleware `web, auth`
- `POST operations/clients/{client}/routines/{block}` — `operations.clients.routines.upsert` — `App\Http\Controllers\Operations\ClientRoutineController@upsertBlock` — `app/Http/Controllers/Operations/ClientRoutineController.php:34` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/routines/reorder` — `operations.clients.routines.reorder` — `App\Http\Controllers\Operations\ClientRoutineController@reorder` — `app/Http/Controllers/Operations/ClientRoutineController.php:61` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientRoutineController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
