# FLEET-INSPECTION: Inspection

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-INSPECTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/inspections` (`fleet-assets.inspections.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/inspections` (`fleet-assets.inspections.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/inspections/{run}` (`fleet-assets.inspections.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/InspectionController.php:236-263`.
3. Use `GET|HEAD fleet-assets/inspections/create` (`fleet-assets.inspections.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/InspectionController.php:152-160`.
4. Invoke only the owning control for `POST fleet-assets/inspections` (`fleet-assets.inspections.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/InspectionController.php:165-231`; `asset_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0764` at `app/Http/Controllers/FleetAssets/InspectionController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0765` at `app/Http/Controllers/FleetAssets/InspectionController.php:165`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0766` at `app/Http/Controllers/FleetAssets/InspectionController.php:236`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0767` at `app/Http/Controllers/FleetAssets/InspectionController.php:152`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/inspections/index.tsx`, `resources/js/pages/fleet-assets/inspections/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0765` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/InspectionController.php:230 `->with('success', 'Inspection submitted successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/InspectionController.php:183 `$template = FleetChecklistTemplate::firstOrCreate(`; app/Http/Controllers/FleetAssets/InspectionController.php:212 `$run = FleetChecklistRun::create([`; responses app/Http/Controllers/FleetAssets/InspectionController.php:122 `return Inertia::render('fleet-assets/inspections/index', [`; app/Http/Controllers/FleetAssets/InspectionController.php:228 `return redirect()`; app/Http/Controllers/FleetAssets/InspectionController.php:242 `return Inertia::render('fleet-assets/inspections/show', [`; app/Http/Controllers/FleetAssets/InspectionController.php:154 `return redirect()->to('/fleet-assets/inspections?' . http_build_query(array_filter([`; audit calls app/Http/Controllers/FleetAssets/InspectionController.php:222 `AuditLogger::log('fleet.inspection.create', $run, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/inspections` — `fleet-assets.inspections.index` — `App\Http\Controllers\FleetAssets\InspectionController@index` — `app/Http/Controllers/FleetAssets/InspectionController.php:19` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/inspections` — `fleet-assets.inspections.store` — `App\Http\Controllers\FleetAssets\InspectionController@store` — `app/Http/Controllers/FleetAssets/InspectionController.php:165` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `GET|HEAD fleet-assets/inspections/{run}` — `fleet-assets.inspections.show` — `App\Http\Controllers\FleetAssets\InspectionController@show` — `app/Http/Controllers/FleetAssets/InspectionController.php:236` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/inspections/create` — `fleet-assets.inspections.create` — `App\Http\Controllers\FleetAssets\InspectionController@create` — `app/Http/Controllers/FleetAssets/InspectionController.php:152` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/InspectionController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/inspections/index.tsx`, `resources/js/pages/fleet-assets/inspections/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
