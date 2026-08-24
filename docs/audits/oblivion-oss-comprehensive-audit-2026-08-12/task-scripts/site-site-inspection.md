# SITE-SITE-INSPECTION: Site Inspection

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.view`, `permission:checklists.schedule`, `permission:checklists.run`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-INSPECTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/inspections` (`sites.inspections.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.view`, `permission:checklists.schedule`, `permission:checklists.run`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:checklists.view`, `permission:checklists.schedule`, `permission:checklists.run`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/inspections` (`sites.inspections.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/inspections` (`sites.inspections.global`, action `globalIndex`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteInspectionController.php:141-218`.
3. Invoke only the owning control for `POST sites/{site}/inspections` (`sites.inspections.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteInspectionController.php:42-85`; `inspection_type`, `title`, `description`, `frequency`, `custom_rrule`, `first_due_date`, `assigned_to_user_id`, `auto_create_calendar_event`.
4. Invoke only the owning control for `DELETE sites/{site}/inspections/{schedule}` (`sites.inspections.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteInspectionController.php:129-139`; no exact validation fields extracted.
5. Invoke only the owning control for `POST sites/{site}/inspections/{schedule}/complete` (`sites.inspections.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Sites/SiteInspectionController.php:87-127`; `result`, `findings`, `corrective_actions`, `evidence_photos`, `linked_hazard_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2804` at `app/Http/Controllers/Sites/SiteInspectionController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2805` at `app/Http/Controllers/Sites/SiteInspectionController.php:42`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2806` at `app/Http/Controllers/Sites/SiteInspectionController.php:129`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2807` at `app/Http/Controllers/Sites/SiteInspectionController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `globalIndex` / `ROUTE-2903` at `app/Http/Controllers/Sites/SiteInspectionController.php:141`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/inspections/global.tsx`, `resources/js/pages/sites/inspections/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2805` / `store`: fields `inspection_type`, `title`, `description`, `frequency`, `custom_rrule`, `first_due_date`, `assigned_to_user_id`, `auto_create_calendar_event`; success app/Http/Controllers/Sites/SiteInspectionController.php:84 `->with('success', 'Inspection schedule created.');`.
- `ROUTE-2806` / `destroy`: success app/Http/Controllers/Sites/SiteInspectionController.php:138 `->with('success', 'Inspection schedule deleted.');`.
- `ROUTE-2807` / `complete`: fields `result`, `findings`, `corrective_actions`, `evidence_photos`, `linked_hazard_id`; success app/Http/Controllers/Sites/SiteInspectionController.php:126 `->with('success', 'Inspection recorded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteInspectionController.php:57 `$schedule = SiteInspectionSchedule::create([`; app/Http/Controllers/Sites/SiteInspectionController.php:67 `SiteCalendarEvent::create([`; app/Http/Controllers/Sites/SiteInspectionController.php:134 `$schedule->delete();`; app/Http/Controllers/Sites/SiteInspectionController.php:100 `$record = SiteInspectionRecord::create([`; app/Http/Controllers/Sites/SiteInspectionController.php:120 `$schedule->update([`; responses app/Http/Controllers/Sites/SiteInspectionController.php:31 `return inertia('sites/inspections/index', [`; app/Http/Controllers/Sites/SiteInspectionController.php:82 `return redirect()`; app/Http/Controllers/Sites/SiteInspectionController.php:136 `return redirect()`; app/Http/Controllers/Sites/SiteInspectionController.php:124 `return redirect()`; app/Http/Controllers/Sites/SiteInspectionController.php:211 `return inertia('sites/inspections/global', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/inspections` — `sites.inspections.index` — `App\Http\Controllers\Sites\SiteInspectionController@index` — `app/Http/Controllers/Sites/SiteInspectionController.php:17` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.view`
- `POST sites/{site}/inspections` — `sites.inspections.store` — `App\Http\Controllers\Sites\SiteInspectionController@store` — `app/Http/Controllers/Sites/SiteInspectionController.php:42` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.schedule`
- `DELETE sites/{site}/inspections/{schedule}` — `sites.inspections.destroy` — `App\Http\Controllers\Sites\SiteInspectionController@destroy` — `app/Http/Controllers/Sites/SiteInspectionController.php:129` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.schedule`
- `POST sites/{site}/inspections/{schedule}/complete` — `sites.inspections.complete` — `App\Http\Controllers\Sites\SiteInspectionController@complete` — `app/Http/Controllers/Sites/SiteInspectionController.php:87` — middleware `web, auth, verified, permission:sites.viewAny, permission:checklists.run`
- `GET|HEAD sites/inspections` — `sites.inspections.global` — `App\Http\Controllers\Sites\SiteInspectionController@globalIndex` — `app/Http/Controllers/Sites/SiteInspectionController.php:141` — middleware `web, auth, verified, permission:checklists.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteInspectionController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/inspections/global.tsx`, `resources/js/pages/sites/inspections/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
