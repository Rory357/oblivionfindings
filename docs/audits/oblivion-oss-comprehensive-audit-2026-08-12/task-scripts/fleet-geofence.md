# FLEET-GEOFENCE: Geofence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.geofences.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-GEOFENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/geofences` (`fleet-assets.geofences.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.geofences.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.geofences.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/geofences` (`fleet-assets.geofences.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/geofences/{geofence}/edit` (`fleet-assets.geofences.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/GeofenceController.php:149-180`.
3. Use `GET|HEAD fleet-assets/geofences/create` (`fleet-assets.geofences.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/GeofenceController.php:103-120`.
4. Invoke only the owning control for `POST fleet-assets/geofences` (`fleet-assets.geofences.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/GeofenceController.php:122-147`; `asset_id`.
5. Invoke only the owning control for `DELETE fleet-assets/geofences/{geofence}` (`fleet-assets.geofences.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/FleetAssets/GeofenceController.php:224-238`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT fleet-assets/geofences/{geofence}` (`fleet-assets.geofences.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/GeofenceController.php:182-205`; `asset_id`.
7. Invoke only the owning control for `POST fleet-assets/geofences/{geofence}/toggle` (`fleet-assets.geofences.toggle`, action `toggleActive`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/GeofenceController.php:207-222`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0736` at `app/Http/Controllers/FleetAssets/GeofenceController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0737` at `app/Http/Controllers/FleetAssets/GeofenceController.php:122`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0738` at `app/Http/Controllers/FleetAssets/GeofenceController.php:224`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0739` at `app/Http/Controllers/FleetAssets/GeofenceController.php:182`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0740` at `app/Http/Controllers/FleetAssets/GeofenceController.php:149`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleActive` / `ROUTE-0741` at `app/Http/Controllers/FleetAssets/GeofenceController.php:207`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0742` at `app/Http/Controllers/FleetAssets/GeofenceController.php:103`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/geofences/create.tsx`, `resources/js/pages/fleet-assets/geofences/edit.tsx`, `resources/js/pages/fleet-assets/geofences/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0737` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/GeofenceController.php:146 `->with('success', 'Geofence created successfully.');`.
- `ROUTE-0738` / `destroy`: success app/Http/Controllers/FleetAssets/GeofenceController.php:237 `->with('success', 'Geofence deleted.');`.
- `ROUTE-0739` / `update`: fields `asset_id`; success app/Http/Controllers/FleetAssets/GeofenceController.php:204 `->with('success', 'Geofence updated successfully.');`.
- `ROUTE-0741` / `toggleActive`: success app/Http/Controllers/FleetAssets/GeofenceController.php:221 `return back()->with('success', 'Geofence status updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/GeofenceController.php:137 `$geofence = AssetGeofence::create($data);`; app/Http/Controllers/FleetAssets/GeofenceController.php:234 `$geofence->delete();`; app/Http/Controllers/FleetAssets/GeofenceController.php:197 `$geofence->update($data);`; app/Http/Controllers/FleetAssets/GeofenceController.php:214 `$geofence->update(['is_active' => !$geofence->is_active]);`; responses app/Http/Controllers/FleetAssets/GeofenceController.php:91 `return Inertia::render('fleet-assets/geofences/index', [`; app/Http/Controllers/FleetAssets/GeofenceController.php:145 `return redirect()->route('fleet-assets.geofences.index')`; app/Http/Controllers/FleetAssets/GeofenceController.php:236 `return redirect()->route('fleet-assets.geofences.index')`; app/Http/Controllers/FleetAssets/GeofenceController.php:203 `return redirect()->route('fleet-assets.geofences.index')`; app/Http/Controllers/FleetAssets/GeofenceController.php:163 `return Inertia::render('fleet-assets/geofences/edit', [`; app/Http/Controllers/FleetAssets/GeofenceController.php:221 `return back()->with('success', 'Geofence status updated.');`; app/Http/Controllers/FleetAssets/GeofenceController.php:115 `return Inertia::render('fleet-assets/geofences/create', [`; audit calls app/Http/Controllers/FleetAssets/GeofenceController.php:139 `AuditLogger::log('fleet.geofence.create', $geofence, [`; app/Http/Controllers/FleetAssets/GeofenceController.php:229 `AuditLogger::log('fleet.geofence.delete', $geofence, [`; app/Http/Controllers/FleetAssets/GeofenceController.php:199 `AuditLogger::log('fleet.geofence.update', $geofence, [`; app/Http/Controllers/FleetAssets/GeofenceController.php:216 `AuditLogger::log('fleet.geofence.toggle', $geofence, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/geofences` — `fleet-assets.geofences.index` — `App\Http\Controllers\FleetAssets\GeofenceController@index` — `app/Http/Controllers/FleetAssets/GeofenceController.php:20` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `POST fleet-assets/geofences` — `fleet-assets.geofences.store` — `App\Http\Controllers\FleetAssets\GeofenceController@store` — `app/Http/Controllers/FleetAssets/GeofenceController.php:122` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `DELETE fleet-assets/geofences/{geofence}` — `fleet-assets.geofences.destroy` — `App\Http\Controllers\FleetAssets\GeofenceController@destroy` — `app/Http/Controllers/FleetAssets/GeofenceController.php:224` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `PUT fleet-assets/geofences/{geofence}` — `fleet-assets.geofences.update` — `App\Http\Controllers\FleetAssets\GeofenceController@update` — `app/Http/Controllers/FleetAssets/GeofenceController.php:182` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `GET|HEAD fleet-assets/geofences/{geofence}/edit` — `fleet-assets.geofences.edit` — `App\Http\Controllers\FleetAssets\GeofenceController@edit` — `app/Http/Controllers/FleetAssets/GeofenceController.php:149` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `POST fleet-assets/geofences/{geofence}/toggle` — `fleet-assets.geofences.toggle` — `App\Http\Controllers\FleetAssets\GeofenceController@toggleActive` — `app/Http/Controllers/FleetAssets/GeofenceController.php:207` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`
- `GET|HEAD fleet-assets/geofences/create` — `fleet-assets.geofences.create` — `App\Http\Controllers\FleetAssets\GeofenceController@create` — `app/Http/Controllers/FleetAssets/GeofenceController.php:103` — middleware `web, auth, permission:fleet.viewAny|assets.geofences.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/GeofenceController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/geofences/create.tsx`, `resources/js/pages/fleet-assets/geofences/edit.tsx`, `resources/js/pages/fleet-assets/geofences/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
