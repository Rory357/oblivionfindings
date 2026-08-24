# SITE-SITE-RESOURCE: Site Resource

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-RESOURCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/resources` (`sites.resources.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/resources` (`sites.resources.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/resources` (`sites.resources.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteResourceController.php:38-59`; `name`, `resource_type`, `capacity`, `amenities`, `calendar_email`.
3. Invoke only the owning control for `DELETE sites/{site}/resources/{resource}` (`sites.resources.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteResourceController.php:79-87`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/resources/{resource}` (`sites.resources.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteResourceController.php:61-77`; `name`, `resource_type`, `capacity`, `amenities`, `calendar_email`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2868` at `app/Http/Controllers/Sites/SiteResourceController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2869` at `app/Http/Controllers/Sites/SiteResourceController.php:38`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2870` at `app/Http/Controllers/Sites/SiteResourceController.php:79`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2871` at `app/Http/Controllers/Sites/SiteResourceController.php:61`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/resources/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2869` / `store`: fields `name`, `resource_type`, `capacity`, `amenities`, `calendar_email`; success app/Http/Controllers/Sites/SiteResourceController.php:58 `return redirect()->back()->with('success', 'Resource added.');`.
- `ROUTE-2870` / `destroy`: success app/Http/Controllers/Sites/SiteResourceController.php:86 `return redirect()->back()->with('success', 'Resource deactivated.');`.
- `ROUTE-2871` / `update`: fields `name`, `resource_type`, `capacity`, `amenities`, `calendar_email`; success app/Http/Controllers/Sites/SiteResourceController.php:76 `return redirect()->back()->with('success', 'Resource updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteResourceController.php:50 `SiteHoResource::create([`; app/Http/Controllers/Sites/SiteResourceController.php:84 `$resource->update(['is_active' => false]);`; app/Http/Controllers/Sites/SiteResourceController.php:74 `$resource->update($validated);`; responses app/Http/Controllers/Sites/SiteResourceController.php:20 `return inertia('sites/resources/index', [`; app/Http/Controllers/Sites/SiteResourceController.php:58 `return redirect()->back()->with('success', 'Resource added.');`; app/Http/Controllers/Sites/SiteResourceController.php:86 `return redirect()->back()->with('success', 'Resource deactivated.');`; app/Http/Controllers/Sites/SiteResourceController.php:76 `return redirect()->back()->with('success', 'Resource updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/resources` — `sites.resources.index` — `App\Http\Controllers\Sites\SiteResourceController@index` — `app/Http/Controllers/Sites/SiteResourceController.php:12` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/resources` — `sites.resources.store` — `App\Http\Controllers\Sites\SiteResourceController@store` — `app/Http/Controllers/Sites/SiteResourceController.php:38` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/resources/{resource}` — `sites.resources.destroy` — `App\Http\Controllers\Sites\SiteResourceController@destroy` — `app/Http/Controllers/Sites/SiteResourceController.php:79` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/resources/{resource}` — `sites.resources.update` — `App\Http\Controllers\Sites\SiteResourceController@update` — `app/Http/Controllers/Sites/SiteResourceController.php:61` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteResourceController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/resources/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
