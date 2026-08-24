# RESP-RESPITE-RESOURCE-ALLOCATION: Respite Resource Allocation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.resources.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-RESOURCE-ALLOCATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/resources` (`respite.resources.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.resources.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.resources.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/resources` (`respite.resources.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST respite/resources` (`respite.resources.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:37-58`; `booking_id`, `resource_type`, `resource_id`, `start_at`, `end_at`.
3. Invoke only the owning control for `DELETE respite/resources/{allocation}` (`respite.resources.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:60-65`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2428` at `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2429` at `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:37`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2430` at `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:60`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/resources/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2429` / `store`: fields `booking_id`, `resource_type`, `resource_id`, `start_at`, `end_at`; success app/Http/Controllers/Respite/RespiteResourceAllocationController.php:57 `return back()->with('success', 'Resource allocation saved.');`.
- `ROUTE-2430` / `destroy`: success app/Http/Controllers/Respite/RespiteResourceAllocationController.php:64 `return back()->with('success', 'Resource allocation removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteResourceAllocationController.php:49 `RespiteResourceAllocation::create($validated);`; app/Http/Controllers/Respite/RespiteResourceAllocationController.php:62 `$allocation->delete();`; responses app/Http/Controllers/Respite/RespiteResourceAllocationController.php:26 `return Inertia::render('respite/resources/index', [`; app/Http/Controllers/Respite/RespiteResourceAllocationController.php:57 `return back()->with('success', 'Resource allocation saved.');`; app/Http/Controllers/Respite/RespiteResourceAllocationController.php:64 `return back()->with('success', 'Resource allocation removed.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteResourceAllocationController.php:51 `event(new RespiteEvent('respite.resource.allocated', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/resources` — `respite.resources.index` — `App\Http\Controllers\Respite\RespiteResourceAllocationController@index` — `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:16` — middleware `web, auth, permission:respite.resources.manage`
- `POST respite/resources` — `respite.resources.store` — `App\Http\Controllers\Respite\RespiteResourceAllocationController@store` — `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:37` — middleware `web, auth, permission:respite.resources.manage`
- `DELETE respite/resources/{allocation}` — `respite.resources.destroy` — `App\Http\Controllers\Respite\RespiteResourceAllocationController@destroy` — `app/Http/Controllers/Respite/RespiteResourceAllocationController.php:60` — middleware `web, auth, permission:respite.resources.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteResourceAllocationController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/resources/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
