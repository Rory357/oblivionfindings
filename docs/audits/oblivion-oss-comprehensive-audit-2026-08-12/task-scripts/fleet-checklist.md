# FLEET-CHECKLIST: Checklist

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-CHECKLIST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/maintenance/checklists` (`fleet-assets.checklists.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.maintenance.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/maintenance/checklists` (`fleet-assets.checklists.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/maintenance/checklists/run` (`fleet-assets.checklists.run-page`, action `runPage`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ChecklistController.php:92-121`.
3. Invoke only the owning control for `POST fleet-assets/maintenance/checklists` (`fleet-assets.checklists.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/ChecklistController.php:68-90`; `name`.
4. Invoke only the owning control for `POST fleet-assets/maintenance/checklists/{template}/run` (`fleet-assets.checklists.run`, action `run`). Source category: **mutation outcome source gap (run)**; controller `app/Http/Controllers/FleetAssets/ChecklistController.php:123-160`; `asset_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0772` at `app/Http/Controllers/FleetAssets/ChecklistController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0773` at `app/Http/Controllers/FleetAssets/ChecklistController.php:68`; it is not runtime-observed.
- **mutation outcome source gap (run)** is applicable only to `run` / `ROUTE-0774` at `app/Http/Controllers/FleetAssets/ChecklistController.php:123`; it is not runtime-observed.
- **information presented** is applicable only to `runPage` / `ROUTE-0775` at `app/Http/Controllers/FleetAssets/ChecklistController.php:92`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/maintenance/checklists/index.tsx`, `resources/js/pages/fleet-assets/maintenance/checklists/run.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0773` / `store`: fields `name`; success app/Http/Controllers/FleetAssets/ChecklistController.php:89 `return back()->with('success', 'Checklist template created.');`.
- `ROUTE-0774` / `run`: fields `asset_id`; success app/Http/Controllers/FleetAssets/ChecklistController.php:159 `return back()->with('success', 'Checklist completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/ChecklistController.php:79 `$template = FleetChecklistTemplate::create([`; app/Http/Controllers/FleetAssets/ChecklistController.php:144 `$run = FleetChecklistRun::create([`; responses app/Http/Controllers/FleetAssets/ChecklistController.php:58 `return Inertia::render('fleet-assets/maintenance/checklists/index', [`; app/Http/Controllers/FleetAssets/ChecklistController.php:89 `return back()->with('success', 'Checklist template created.');`; app/Http/Controllers/FleetAssets/ChecklistController.php:159 `return back()->with('success', 'Checklist completed.');`; app/Http/Controllers/FleetAssets/ChecklistController.php:113 `return Inertia::render('fleet-assets/maintenance/checklists/run', [`; audit calls app/Http/Controllers/FleetAssets/ChecklistController.php:85 `AuditLogger::log('fleet.checklist_template.create', $template, [`; app/Http/Controllers/FleetAssets/ChecklistController.php:154 `AuditLogger::log('fleet.checklist.run', $run, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/maintenance/checklists` — `fleet-assets.checklists.index` — `App\Http\Controllers\FleetAssets\ChecklistController@index` — `app/Http/Controllers/FleetAssets/ChecklistController.php:14` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/maintenance/checklists` — `fleet-assets.checklists.store` — `App\Http\Controllers\FleetAssets\ChecklistController@store` — `app/Http/Controllers/FleetAssets/ChecklistController.php:68` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `POST fleet-assets/maintenance/checklists/{template}/run` — `fleet-assets.checklists.run` — `App\Http\Controllers\FleetAssets\ChecklistController@run` — `app/Http/Controllers/FleetAssets/ChecklistController.php:123` — middleware `web, auth, permission:fleet.maintenance.manage|fleet.manage`
- `GET|HEAD fleet-assets/maintenance/checklists/run` — `fleet-assets.checklists.run-page` — `App\Http\Controllers\FleetAssets\ChecklistController@runPage` — `app/Http/Controllers/FleetAssets/ChecklistController.php:92` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/ChecklistController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/maintenance/checklists/index.tsx`, `resources/js/pages/fleet-assets/maintenance/checklists/run.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
