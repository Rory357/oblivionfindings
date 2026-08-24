# CAP-HS-SITE-HAZARD-REGISTER: Site and global hazard register

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.view`, `permission:sites.viewAny`, `permission:hazards.create`
- Owning module: Health and safety
- Legacy family: `HS-SITE-HAZARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `compliance/hazards` (`compliance.hazards`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:hazards.view`, `permission:sites.viewAny`, `permission:hazards.create`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:hazards.view`, `permission:sites.viewAny`, `permission:hazards.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD compliance/hazards` (`compliance.hazards`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD compliance/hazards/export` (`compliance.hazards.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Sites/SiteHazardController.php:46-86`.
3. Use `GET|HEAD sites/{site}/hazards` (`sites.hazards.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteHazardController.php:88-102`.
4. Use `GET|HEAD sites/{site}/hazards/create` (`sites.hazards.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteHazardController.php:295-303`.
5. Invoke only the owning control for `POST sites/{site}/hazards` (`sites.hazards.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteHazardController.php:325-376`; `hazard_type`, `custom_hazard_type`, `severity`, `likelihood`, `description`, `location`, `witnesses`, `immediate_action_applied`, `immediate_action_taken`, `assigned_to_user_id`, `due_date`, `photos`, `photo_paths`.

## Source-applicable states and transitions

- **information presented** is applicable only to `globalIndex` / `ROUTE-0209` at `app/Http/Controllers/Sites/SiteHazardController.php:38`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0210` at `app/Http/Controllers/Sites/SiteHazardController.php:46`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2800` at `app/Http/Controllers/Sites/SiteHazardController.php:88`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2801` at `app/Http/Controllers/Sites/SiteHazardController.php:325`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2802` at `app/Http/Controllers/Sites/SiteHazardController.php:295`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/compliance/hazards/index.tsx`, `resources/js/pages/sites/hazards/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2801` / `store`: fields `hazard_type`, `custom_hazard_type`, `severity`, `likelihood`, `description`, `location`, `witnesses`, `immediate_action_applied`, `immediate_action_taken`, `assigned_to_user_id`, `due_date`, `photos`, `photo_paths`; success app/Http/Controllers/Sites/SiteHazardController.php:375 `return back()->with('success', "Hazard {$hazard->reference_number} logged at {$site->name}.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteHazardController.php:349 `$hazard = SiteHazard::create([`; responses app/Http/Controllers/Sites/SiteHazardController.php:42 `return inertia('compliance/hazards/index', $this->registerProps($request, null));`; app/Http/Controllers/Sites/SiteHazardController.php:82 `return response($csv, 200, [`; app/Http/Controllers/Sites/SiteHazardController.php:92 `return inertia('sites/hazards/index', [`; app/Http/Controllers/Sites/SiteHazardController.php:375 `return back()->with('success', "Hazard {$hazard->reference_number} logged at {$site->name}.");`; app/Http/Controllers/Sites/SiteHazardController.php:302 `return redirect()->to("/sites/{$site->id}/hazards?action=add");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD compliance/hazards` — `compliance.hazards` — `App\Http\Controllers\Sites\SiteHazardController@globalIndex` — `app/Http/Controllers/Sites/SiteHazardController.php:38` — middleware `web, auth, verified, permission:hazards.view`
- `GET|HEAD compliance/hazards/export` — `compliance.hazards.export` — `App\Http\Controllers\Sites\SiteHazardController@export` — `app/Http/Controllers/Sites/SiteHazardController.php:46` — middleware `web, auth, verified, permission:hazards.view`
- `GET|HEAD sites/{site}/hazards` — `sites.hazards.index` — `App\Http\Controllers\Sites\SiteHazardController@index` — `app/Http/Controllers/Sites/SiteHazardController.php:88` — middleware `web, auth, verified, permission:sites.viewAny, permission:hazards.view`
- `POST sites/{site}/hazards` — `sites.hazards.store` — `App\Http\Controllers\Sites\SiteHazardController@store` — `app/Http/Controllers/Sites/SiteHazardController.php:325` — middleware `web, auth, verified, permission:sites.viewAny, permission:hazards.create`
- `GET|HEAD sites/{site}/hazards/create` — `sites.hazards.create` — `App\Http\Controllers\Sites\SiteHazardController@create` — `app/Http/Controllers/Sites/SiteHazardController.php:295` — middleware `web, auth, verified, permission:sites.viewAny, permission:hazards.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteHazardController.php`.
- Exact render/action page relationships: `resources/js/pages/compliance/hazards/index.tsx`, `resources/js/pages/sites/hazards/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
