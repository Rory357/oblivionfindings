# CAP-HR-ASSET-REGISTER-LIFECYCLE: Employee asset register lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`
- Owning module: Human resources
- Legacy family: `HR-ASSET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/assets` (`hr.assets.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.assets.view`, `permission:hr.assets.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/assets` (`hr.assets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/assets/{asset}` (`hr.assets.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/AssetController.php:169-220`.
3. Use `GET|HEAD hr/assets/export` (`hr.assets.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/AssetController.php:549-586`.
4. Invoke only the owning control for `POST hr/assets` (`hr.assets.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/AssetController.php:226-242`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT hr/assets/{asset}` (`hr.assets.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/AssetController.php:244-254`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/assets/{asset}/maintenance` (`hr.assets.maintenance`, action `logMaintenance`). Source category: **mutation outcome source gap (logMaintenance)**; controller `app/Http/Controllers/Hr/AssetController.php:323-346`; `type`.
7. Invoke only the owning control for `POST hr/assets/{asset}/retire` (`hr.assets.retire`, action `retire`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/AssetController.php:371-391`; `disposal_reason`.
8. Invoke only the owning control for `POST hr/assets/{asset}/return-to-service` (`hr.assets.return-to-service`, action `returnToService`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/AssetController.php:348-369`; `outcome`.
9. Invoke only the owning control for `POST hr/assets/bulk` (`hr.assets.bulk`, action `bulk`). Source category: **mutation outcome source gap (bulk)**; controller `app/Http/Controllers/Hr/AssetController.php:507-547`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1279` at `app/Http/Controllers/Hr/AssetController.php:61`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1280` at `app/Http/Controllers/Hr/AssetController.php:226`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1281` at `app/Http/Controllers/Hr/AssetController.php:169`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1282` at `app/Http/Controllers/Hr/AssetController.php:244`; it is not runtime-observed.
- **mutation outcome source gap (logMaintenance)** is applicable only to `logMaintenance` / `ROUTE-1285` at `app/Http/Controllers/Hr/AssetController.php:323`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `retire` / `ROUTE-1287` at `app/Http/Controllers/Hr/AssetController.php:371`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnToService` / `ROUTE-1288` at `app/Http/Controllers/Hr/AssetController.php:348`; it is not runtime-observed.
- **mutation outcome source gap (bulk)** is applicable only to `bulk` / `ROUTE-1290` at `app/Http/Controllers/Hr/AssetController.php:507`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1293` at `app/Http/Controllers/Hr/AssetController.php:549`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/assets/index.tsx`, `resources/js/pages/hr/assets/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1280` / `store`: success app/Http/Controllers/Hr/AssetController.php:241 `return redirect()->back()->with('success', "Asset {$asset->asset_tag} created.");`.
- `ROUTE-1282` / `update`: success app/Http/Controllers/Hr/AssetController.php:253 `return redirect()->back()->with('success', "Asset {$asset->asset_tag} updated.");`.
- `ROUTE-1285` / `logMaintenance`: fields `type`; success app/Http/Controllers/Hr/AssetController.php:345 `return redirect()->back()->with('success', 'Repair logged.');`.
- `ROUTE-1287` / `retire`: fields `disposal_reason`; success app/Http/Controllers/Hr/AssetController.php:390 `return redirect()->back()->with('success', 'Asset retired.');`.
- `ROUTE-1288` / `returnToService`: fields `outcome`; success app/Http/Controllers/Hr/AssetController.php:368 `return redirect()->back()->with('success', 'Asset returned to service.');`.
- `ROUTE-1290` / `bulk`: fields `action`; success app/Http/Controllers/Hr/AssetController.php:546 `return redirect()->back()->with('success', "{$count} assets updated.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/AssetController.php:234 `$asset = HrAsset::create([`; app/Http/Controllers/Hr/AssetController.php:251 `$asset->update($data);`; app/Http/Controllers/Hr/AssetController.php:538 `$asset->update(['category' => $data['category']]);`; responses app/Http/Controllers/Hr/AssetController.php:138 `return Inertia::render('hr/assets/index', [`; app/Http/Controllers/Hr/AssetController.php:241 `return redirect()->back()->with('success', "Asset {$asset->asset_tag} created.");`; app/Http/Controllers/Hr/AssetController.php:209 `return Inertia::render('hr/assets/show', [`; app/Http/Controllers/Hr/AssetController.php:253 `return redirect()->back()->with('success', "Asset {$asset->asset_tag} updated.");`; app/Http/Controllers/Hr/AssetController.php:342 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/AssetController.php:345 `return redirect()->back()->with('success', 'Repair logged.');`; app/Http/Controllers/Hr/AssetController.php:387 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/AssetController.php:390 `return redirect()->back()->with('success', 'Asset retired.');`; app/Http/Controllers/Hr/AssetController.php:365 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/AssetController.php:368 `return redirect()->back()->with('success', 'Asset returned to service.');`; app/Http/Controllers/Hr/AssetController.php:546 `return redirect()->back()->with('success', "{$count} assets updated.");`; app/Http/Controllers/Hr/AssetController.php:562 `return response()->streamDownload(function () use ($assets) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/assets` — `hr.assets.index` — `App\Http\Controllers\Hr\AssetController@index` — `app/Http/Controllers/Hr/AssetController.php:61` — middleware `web, auth, permission:hr.assets.view`
- `POST hr/assets` — `hr.assets.store` — `App\Http\Controllers\Hr\AssetController@store` — `app/Http/Controllers/Hr/AssetController.php:226` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `GET|HEAD hr/assets/{asset}` — `hr.assets.show` — `App\Http\Controllers\Hr\AssetController@show` — `app/Http/Controllers/Hr/AssetController.php:169` — middleware `web, auth, permission:hr.assets.view`
- `PUT hr/assets/{asset}` — `hr.assets.update` — `App\Http\Controllers\Hr\AssetController@update` — `app/Http/Controllers/Hr/AssetController.php:244` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `POST hr/assets/{asset}/maintenance` — `hr.assets.maintenance` — `App\Http\Controllers\Hr\AssetController@logMaintenance` — `app/Http/Controllers/Hr/AssetController.php:323` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `POST hr/assets/{asset}/retire` — `hr.assets.retire` — `App\Http\Controllers\Hr\AssetController@retire` — `app/Http/Controllers/Hr/AssetController.php:371` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `POST hr/assets/{asset}/return-to-service` — `hr.assets.return-to-service` — `App\Http\Controllers\Hr\AssetController@returnToService` — `app/Http/Controllers/Hr/AssetController.php:348` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `POST hr/assets/bulk` — `hr.assets.bulk` — `App\Http\Controllers\Hr\AssetController@bulk` — `app/Http/Controllers/Hr/AssetController.php:507` — middleware `web, auth, permission:hr.assets.view, permission:hr.assets.manage`
- `GET|HEAD hr/assets/export` — `hr.assets.export` — `App\Http\Controllers\Hr\AssetController@export` — `app/Http/Controllers/Hr/AssetController.php:549` — middleware `web, auth, permission:hr.assets.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/AssetController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/assets/index.tsx`, `resources/js/pages/hr/assets/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
