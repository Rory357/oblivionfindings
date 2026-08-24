# CAP-SITE-SITE-CHECKLIST-ASSIGNMENT-RUN-CREATION: Site checklist assignment removal and run creation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.schedule`, `permission:checklists.run`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CHECKLIST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/checklists` (`sites.checklists.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.schedule`, `permission:checklists.run`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.schedule`, `permission:checklists.run`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/checklists` (`sites.checklists.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/checklists/assign` (`sites.checklists.assign`, action `assignChecklist`). Source category: **mutation outcome source gap (assignChecklist)**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:298-329`; `template_id`, `frequency`.
3. Invoke only the owning control for `DELETE sites/{site}/checklists/assignments/{assignment}` (`sites.checklists.removeAssignment`, action `removeAssignment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:331-342`; no exact validation fields extracted.
4. Invoke only the owning control for `POST sites/{site}/checklists/assignments/{assignment}/run` (`sites.checklists.createRun`, action `createRun`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteChecklistController.php:344-383`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2744` at `app/Http/Controllers/Sites/SiteChecklistController.php:21`; it is not runtime-observed.
- **mutation outcome source gap (assignChecklist)** is applicable only to `assignChecklist` / `ROUTE-2745` at `app/Http/Controllers/Sites/SiteChecklistController.php:298`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeAssignment` / `ROUTE-2746` at `app/Http/Controllers/Sites/SiteChecklistController.php:331`; it is not runtime-observed.
- **created/recorded** is applicable only to `createRun` / `ROUTE-2747` at `app/Http/Controllers/Sites/SiteChecklistController.php:344`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/checklists/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2745` / `assignChecklist`: fields `template_id`, `frequency`; success app/Http/Controllers/Sites/SiteChecklistController.php:328 `->with('success', 'Checklist assigned successfully.');`.
- `ROUTE-2746` / `removeAssignment`: success app/Http/Controllers/Sites/SiteChecklistController.php:341 `->with('success', 'Checklist assignment removed.');`.
- `ROUTE-2747` / `createRun`: success app/Http/Controllers/Sites/SiteChecklistController.php:367 `->with('success', 'Resumed in-progress checklist run.');`; app/Http/Controllers/Sites/SiteChecklistController.php:382 `->with('success', 'New checklist run started.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteChecklistController.php:307 `$assignment = SiteChecklistAssignment::create([`; app/Http/Controllers/Sites/SiteChecklistController.php:317 `SiteChecklistRun::create([`; app/Http/Controllers/Sites/SiteChecklistController.php:337 `$assignment->update(['is_active' => false]);`; app/Http/Controllers/Sites/SiteChecklistController.php:359 `$existing->update([`; app/Http/Controllers/Sites/SiteChecklistController.php:370 `$run = SiteChecklistRun::create([`; responses app/Http/Controllers/Sites/SiteChecklistController.php:25 `return inertia('sites/checklists/index', array_merge(`; app/Http/Controllers/Sites/SiteChecklistController.php:326 `return redirect()`; app/Http/Controllers/Sites/SiteChecklistController.php:339 `return redirect()`; app/Http/Controllers/Sites/SiteChecklistController.php:365 `return redirect()`; app/Http/Controllers/Sites/SiteChecklistController.php:380 `return redirect()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/checklists` — `sites.checklists.index` — `App\Http\Controllers\Sites\SiteChecklistController@index` — `app/Http/Controllers/Sites/SiteChecklistController.php:21` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/checklists/assign` — `sites.checklists.assign` — `App\Http\Controllers\Sites\SiteChecklistController@assignChecklist` — `app/Http/Controllers/Sites/SiteChecklistController.php:298` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.schedule`
- `DELETE sites/{site}/checklists/assignments/{assignment}` — `sites.checklists.removeAssignment` — `App\Http\Controllers\Sites\SiteChecklistController@removeAssignment` — `app/Http/Controllers/Sites/SiteChecklistController.php:331` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.schedule`
- `POST sites/{site}/checklists/assignments/{assignment}/run` — `sites.checklists.createRun` — `App\Http\Controllers\Sites\SiteChecklistController@createRun` — `app/Http/Controllers/Sites/SiteChecklistController.php:344` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.run`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteChecklistController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/checklists/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
