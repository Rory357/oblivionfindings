# OPS-FAMILY-PORTAL: Family Portal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:family_portal.viewAny|clients.update`, `permission:family_portal.manage|clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-FAMILY-PORTAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/family-portal` (`operations.family_portal.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:family_portal.viewAny|clients.update`, `permission:family_portal.manage|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:family_portal.viewAny|clients.update`, `permission:family_portal.manage|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/family-portal` (`operations.family_portal.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/family-portal/{client}` (`operations.family_portal.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/FamilyPortalController.php:50-63`.
3. Use `GET|HEAD operations/family-portal/{client}/edit` (`operations.family_portal.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/FamilyPortalController.php:65-78`.
4. Invoke only the owning control for `PUT operations/family-portal/{client}` (`operations.family_portal.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/FamilyPortalController.php:80-134`; `show_shift_schedule`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2063` at `app/Http/Controllers/Operations/FamilyPortalController.php:13`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2064` at `app/Http/Controllers/Operations/FamilyPortalController.php:50`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2065` at `app/Http/Controllers/Operations/FamilyPortalController.php:80`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2066` at `app/Http/Controllers/Operations/FamilyPortalController.php:65`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/family-portal/Edit.tsx`, `resources/js/pages/operations/family-portal/Index.tsx`, `resources/js/pages/operations/family-portal/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2065` / `update`: fields `show_shift_schedule`; success app/Http/Controllers/Operations/FamilyPortalController.php:133 `return redirect()->back()->with('success', $message);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/FamilyPortalController.php:113 `FamilyPortalSetting::updateOrCreate(`; responses app/Http/Controllers/Operations/FamilyPortalController.php:28 `return [`; app/Http/Controllers/Operations/FamilyPortalController.php:45 `return inertia('operations/family-portal/Index', [`; app/Http/Controllers/Operations/FamilyPortalController.php:60 `return inertia('operations/family-portal/Show', [`; app/Http/Controllers/Operations/FamilyPortalController.php:133 `return redirect()->back()->with('success', $message);`; app/Http/Controllers/Operations/FamilyPortalController.php:75 `return inertia('operations/family-portal/Edit', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/family-portal` — `operations.family_portal.index` — `App\Http\Controllers\Operations\FamilyPortalController@index` — `app/Http/Controllers/Operations/FamilyPortalController.php:13` — middleware `web, auth, permission:family_portal.viewAny|clients.update`
- `GET|HEAD operations/family-portal/{client}` — `operations.family_portal.show` — `App\Http\Controllers\Operations\FamilyPortalController@show` — `app/Http/Controllers/Operations/FamilyPortalController.php:50` — middleware `web, auth, permission:family_portal.viewAny|clients.update`
- `PUT operations/family-portal/{client}` — `operations.family_portal.update` — `App\Http\Controllers\Operations\FamilyPortalController@update` — `app/Http/Controllers/Operations/FamilyPortalController.php:80` — middleware `web, auth, permission:family_portal.manage|clients.update`
- `GET|HEAD operations/family-portal/{client}/edit` — `operations.family_portal.edit` — `App\Http\Controllers\Operations\FamilyPortalController@edit` — `app/Http/Controllers/Operations/FamilyPortalController.php:65` — middleware `web, auth, permission:family_portal.manage|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/FamilyPortalController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/family-portal/Edit.tsx`, `resources/js/pages/operations/family-portal/Index.tsx`, `resources/js/pages/operations/family-portal/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
