# FLEET-WORK-ORDER: Work Order

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-WORK-ORDER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/maintenance/work-orders` (`fleet-assets.work-orders.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/maintenance/work-orders` (`fleet-assets.work-orders.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/maintenance/work-orders/{workOrder}` (`fleet-assets.work-orders.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/WorkOrderController.php:162-173`.
3. Use `GET|HEAD fleet-assets/maintenance/work-orders/create` (`fleet-assets.work-orders.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/WorkOrderController.php:121-128`.
4. Invoke only the owning control for `POST fleet-assets/maintenance/work-orders` (`fleet-assets.work-orders.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/WorkOrderController.php:130-160`; `asset_id`.
5. Invoke only the owning control for `PUT fleet-assets/maintenance/work-orders/{workOrder}` (`fleet-assets.work-orders.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/WorkOrderController.php:175-197`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/maintenance/work-orders/bulk-action` (`fleet-assets.work-orders.bulk-action`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/FleetAssets/WorkOrderController.php:199-233`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0781` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0782` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:130`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0783` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:162`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0784` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:175`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-0785` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:199`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0786` at `app/Http/Controllers/FleetAssets/WorkOrderController.php:121`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx`, `resources/js/pages/fleet-assets/maintenance/work-orders/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0782` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/WorkOrderController.php:159 `->with('success', 'Work order created.');`.
- `ROUTE-0784` / `update`: success app/Http/Controllers/FleetAssets/WorkOrderController.php:196 `return back()->with('success', 'Work order updated.');`.
- `ROUTE-0785` / `bulkAction`: fields `action`; success app/Http/Controllers/FleetAssets/WorkOrderController.php:232 `return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' work order(s).');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/WorkOrderController.php:151 `$workOrder = FleetWorkOrder::create($data);`; app/Http/Controllers/FleetAssets/WorkOrderController.php:190 `$workOrder->update($data);`; app/Http/Controllers/FleetAssets/WorkOrderController.php:224 `FleetWorkOrder::whereIn('id', $data['ids'])->update($updateData);`; responses app/Http/Controllers/FleetAssets/WorkOrderController.php:23 `return response()->streamDownload(function () use ($all) {`; app/Http/Controllers/FleetAssets/WorkOrderController.php:89 `return Inertia::render('fleet-assets/maintenance/work-orders/index', [`; app/Http/Controllers/FleetAssets/WorkOrderController.php:158 `return redirect()->route('fleet-assets.work-orders.show', $workOrder)`; app/Http/Controllers/FleetAssets/WorkOrderController.php:170 `return Inertia::render('fleet-assets/maintenance/work-orders/show', [`; app/Http/Controllers/FleetAssets/WorkOrderController.php:196 `return back()->with('success', 'Work order updated.');`; app/Http/Controllers/FleetAssets/WorkOrderController.php:232 `return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' work order(s).');`; app/Http/Controllers/FleetAssets/WorkOrderController.php:123 `return redirect()->to('/fleet-assets/maintenance/work-orders?' . http_build_query(array_filter([`; audit calls app/Http/Controllers/FleetAssets/WorkOrderController.php:153 `AuditLogger::log('fleet.work_order.create', $workOrder, [`; app/Http/Controllers/FleetAssets/WorkOrderController.php:192 `AuditLogger::log('fleet.work_order.update', $workOrder, [`; app/Http/Controllers/FleetAssets/WorkOrderController.php:227 `AuditLogger::log('fleet.work_orders.bulk_action', null, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/maintenance/work-orders` — `fleet-assets.work-orders.index` — `App\Http\Controllers\FleetAssets\WorkOrderController@index` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:15` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/maintenance/work-orders` — `fleet-assets.work-orders.store` — `App\Http\Controllers\FleetAssets\WorkOrderController@store` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:130` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/maintenance/work-orders/{workOrder}` — `fleet-assets.work-orders.show` — `App\Http\Controllers\FleetAssets\WorkOrderController@show` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:162` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `PUT fleet-assets/maintenance/work-orders/{workOrder}` — `fleet-assets.work-orders.update` — `App\Http\Controllers\FleetAssets\WorkOrderController@update` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:175` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `POST fleet-assets/maintenance/work-orders/bulk-action` — `fleet-assets.work-orders.bulk-action` — `App\Http\Controllers\FleetAssets\WorkOrderController@bulkAction` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:199` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `GET|HEAD fleet-assets/maintenance/work-orders/create` — `fleet-assets.work-orders.create` — `App\Http\Controllers\FleetAssets\WorkOrderController@create` — `app/Http/Controllers/FleetAssets/WorkOrderController.php:121` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/WorkOrderController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx`, `resources/js/pages/fleet-assets/maintenance/work-orders/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
