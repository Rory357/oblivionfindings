# SITE-SITE-ZONE: Site Zone

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-ZONE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/zones` (`sites.zones.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/zones` (`sites.zones.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/zones` (`sites.zones.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteZoneController.php:35-53`; `name`, `description`, `zone_type`.
3. Invoke only the owning control for `DELETE sites/{site}/zones/{zone}` (`sites.zones.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteZoneController.php:71-79`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT sites/{site}/zones/{zone}` (`sites.zones.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteZoneController.php:55-69`; `name`, `description`, `zone_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2893` at `app/Http/Controllers/Sites/SiteZoneController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2894` at `app/Http/Controllers/Sites/SiteZoneController.php:35`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2895` at `app/Http/Controllers/Sites/SiteZoneController.php:71`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2896` at `app/Http/Controllers/Sites/SiteZoneController.php:55`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/zones/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2894` / `store`: fields `name`, `description`, `zone_type`; success app/Http/Controllers/Sites/SiteZoneController.php:52 `return redirect()->back()->with('success', 'Zone added.');`.
- `ROUTE-2895` / `destroy`: success app/Http/Controllers/Sites/SiteZoneController.php:78 `return redirect()->back()->with('success', 'Zone deactivated.');`.
- `ROUTE-2896` / `update`: fields `name`, `description`, `zone_type`; success app/Http/Controllers/Sites/SiteZoneController.php:68 `return redirect()->back()->with('success', 'Zone updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteZoneController.php:45 `SiteFacilityZone::create([`; app/Http/Controllers/Sites/SiteZoneController.php:76 `$zone->update(['is_active' => false]);`; app/Http/Controllers/Sites/SiteZoneController.php:66 `$zone->update($validated);`; responses app/Http/Controllers/Sites/SiteZoneController.php:20 `return inertia('sites/zones/index', [`; app/Http/Controllers/Sites/SiteZoneController.php:52 `return redirect()->back()->with('success', 'Zone added.');`; app/Http/Controllers/Sites/SiteZoneController.php:78 `return redirect()->back()->with('success', 'Zone deactivated.');`; app/Http/Controllers/Sites/SiteZoneController.php:68 `return redirect()->back()->with('success', 'Zone updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/zones` — `sites.zones.index` — `App\Http\Controllers\Sites\SiteZoneController@index` — `app/Http/Controllers/Sites/SiteZoneController.php:12` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/zones` — `sites.zones.store` — `App\Http\Controllers\Sites\SiteZoneController@store` — `app/Http/Controllers/Sites/SiteZoneController.php:35` — middleware `web, auth, verified, permission:sites.update`
- `DELETE sites/{site}/zones/{zone}` — `sites.zones.destroy` — `App\Http\Controllers\Sites\SiteZoneController@destroy` — `app/Http/Controllers/Sites/SiteZoneController.php:71` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/zones/{zone}` — `sites.zones.update` — `App\Http\Controllers\Sites\SiteZoneController@update` — `app/Http/Controllers/Sites/SiteZoneController.php:55` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteZoneController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/zones/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
