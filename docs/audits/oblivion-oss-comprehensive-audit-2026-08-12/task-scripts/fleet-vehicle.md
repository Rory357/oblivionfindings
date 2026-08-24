# FLEET-VEHICLE: Vehicle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny`, `permission:fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-VEHICLE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/fuel` (`fleet-assets.fuel.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny`, `permission:fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny`, `permission:fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/fuel` (`fleet-assets.fuel.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/trips` (`fleet-assets.trips.index`, action `trips`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleController.php:489-728`.
3. Use `GET|HEAD fleet-assets/vehicles` (`fleet-assets.vehicles.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleController.php:45-206`.
4. Use `GET|HEAD fleet-assets/vehicles/{asset}` (`fleet-assets.vehicles.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleController.php:208-390`.
5. Use `GET|HEAD fleet-assets/vehicles/{asset}/alerts-config` (`fleet-assets.vehicles.alerts-config`, action `alertsConfig`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleController.php:941-966`.
6. Invoke only the owning control for `POST fleet-assets/fuel` (`fleet-assets.fuel.store`, action `storeFuel`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/VehicleController.php:885-907`; `asset_id`.
7. Invoke only the owning control for `POST fleet-assets/trips/{trip}/toggle-personal` (`fleet-assets.trips.toggle-personal`, action `markPersonal`). Source category: **mutation outcome source gap (markPersonal)**; controller `app/Http/Controllers/FleetAssets/VehicleController.php:478-487`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT fleet-assets/vehicles/{asset}` (`fleet-assets.vehicles.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/VehicleController.php:437-476`; `home_site_id`.
9. Invoke only the owning control for `POST fleet-assets/vehicles/{asset}/alerts-config` (`fleet-assets.vehicles.alerts-config.save`, action `saveAlertsConfig`). Source category: **mutation outcome source gap (saveAlertsConfig)**; controller `app/Http/Controllers/FleetAssets/VehicleController.php:968-985`; `config`.
10. Invoke only the owning control for `POST fleet-assets/vehicles/bulk-action` (`fleet-assets.vehicles.bulk-action`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/FleetAssets/VehicleController.php:909-939`; `action`.

## Source-applicable states and transitions

- **information presented** is applicable only to `fuel` / `ROUTE-0734` at `app/Http/Controllers/FleetAssets/VehicleController.php:730`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeFuel` / `ROUTE-0735` at `app/Http/Controllers/FleetAssets/VehicleController.php:885`; it is not runtime-observed.
- **information presented** is applicable only to `trips` / `ROUTE-0831` at `app/Http/Controllers/FleetAssets/VehicleController.php:489`; it is not runtime-observed.
- **mutation outcome source gap (markPersonal)** is applicable only to `markPersonal` / `ROUTE-0834` at `app/Http/Controllers/FleetAssets/VehicleController.php:478`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-0835` at `app/Http/Controllers/FleetAssets/VehicleController.php:45`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0836` at `app/Http/Controllers/FleetAssets/VehicleController.php:208`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0837` at `app/Http/Controllers/FleetAssets/VehicleController.php:437`; it is not runtime-observed.
- **information presented** is applicable only to `alertsConfig` / `ROUTE-0838` at `app/Http/Controllers/FleetAssets/VehicleController.php:941`; it is not runtime-observed.
- **mutation outcome source gap (saveAlertsConfig)** is applicable only to `saveAlertsConfig` / `ROUTE-0839` at `app/Http/Controllers/FleetAssets/VehicleController.php:968`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-0840` at `app/Http/Controllers/FleetAssets/VehicleController.php:909`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/fuel/index.tsx`, `resources/js/pages/fleet-assets/trips/index.tsx`, `resources/js/pages/fleet-assets/vehicles/alerts-config.tsx`, `resources/js/pages/fleet-assets/vehicles/index.tsx`, `resources/js/pages/fleet-assets/vehicles/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0735` / `storeFuel`: fields `asset_id`; success app/Http/Controllers/FleetAssets/VehicleController.php:906 `return back()->with('success', 'Fuel log recorded successfully.');`.
- `ROUTE-0834` / `markPersonal`: success app/Http/Controllers/FleetAssets/VehicleController.php:486 `return back()->with('success', $trip->is_personal ? 'Trip marked as personal.' : 'Trip marked as business.');`.
- `ROUTE-0837` / `update`: fields `home_site_id`; success app/Http/Controllers/FleetAssets/VehicleController.php:475 `return back()->with('success', 'Vehicle updated successfully.');`.
- `ROUTE-0839` / `saveAlertsConfig`: fields `config`; success app/Http/Controllers/FleetAssets/VehicleController.php:984 `return back()->with('success', 'Alert configuration saved.');`.
- `ROUTE-0840` / `bulkAction`: fields `action`; success app/Http/Controllers/FleetAssets/VehicleController.php:938 `return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' vehicle(s).');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/VehicleController.php:904 `FleetFuelLog::create($data);`; app/Http/Controllers/FleetAssets/VehicleController.php:480 `$trip->update([`; app/Http/Controllers/FleetAssets/VehicleController.php:469 `$asset->update($safeData);`; app/Http/Controllers/FleetAssets/VehicleController.php:975 `$asset->update(['alert_config' => json_encode($data['config'])]);`; app/Http/Controllers/FleetAssets/VehicleController.php:923 `Asset::whereIn('id', $data['ids'])->update(['home_site_id' => $data['site_id']]);`; app/Http/Controllers/FleetAssets/VehicleController.php:925 `Asset::whereIn('id', $data['ids'])->update(['site_id' => $data['site_id']]);`; app/Http/Controllers/FleetAssets/VehicleController.php:929 `FleetVehicleStateSnapshot::whereIn('asset_id', $data['ids'])->update(['status' => 'offline']);`; responses app/Http/Controllers/FleetAssets/VehicleController.php:767 `return response($csv, 200, [`; app/Http/Controllers/FleetAssets/VehicleController.php:805 `return [`; app/Http/Controllers/FleetAssets/VehicleController.php:833 `return Inertia::render('fleet-assets/fuel/index', [`; app/Http/Controllers/FleetAssets/VehicleController.php:906 `return back()->with('success', 'Fuel log recorded successfully.');`; app/Http/Controllers/FleetAssets/VehicleController.php:549 `return response($csv, 200, [`; app/Http/Controllers/FleetAssets/VehicleController.php:674 `return Inertia::render('fleet-assets/trips/index', [`; app/Http/Controllers/FleetAssets/VehicleController.php:486 `return back()->with('success', $trip->is_personal ? 'Trip marked as personal.' : 'Trip marked as business.');`; app/Http/Controllers/FleetAssets/VehicleController.php:64 `return response()->streamDownload(function () use ($allVehicles, $hasFleetFields) {`; app/Http/Controllers/FleetAssets/VehicleController.php:157 `return Inertia::render('fleet-assets/vehicles/index', [`; app/Http/Controllers/FleetAssets/VehicleController.php:300 `return Inertia::render('fleet-assets/vehicles/show', [`; app/Http/Controllers/FleetAssets/VehicleController.php:475 `return back()->with('success', 'Vehicle updated successfully.');`; app/Http/Controllers/FleetAssets/VehicleController.php:954 `return Inertia::render('fleet-assets/vehicles/alerts-config', [`; app/Http/Controllers/FleetAssets/VehicleController.php:977 `return back()->with('error', 'Vehicle alert configuration is not available until the fleet schema is updated.');`; app/Http/Controllers/FleetAssets/VehicleController.php:984 `return back()->with('success', 'Alert configuration saved.');`; app/Http/Controllers/FleetAssets/VehicleController.php:938 `return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' vehicle(s).');`; audit calls app/Http/Controllers/FleetAssets/VehicleController.php:471 `AuditLogger::log('fleet.vehicle.update', $asset, [`; app/Http/Controllers/FleetAssets/VehicleController.php:980 `AuditLogger::log('fleet.vehicle.alerts_config', $asset, [`; app/Http/Controllers/FleetAssets/VehicleController.php:933 `AuditLogger::log('fleet.vehicles.bulk_action', null, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/fuel` — `fleet-assets.fuel.index` — `App\Http\Controllers\FleetAssets\VehicleController@fuel` — `app/Http/Controllers/FleetAssets/VehicleController.php:730` — middleware `web, auth, permission:fleet.viewAny`
- `POST fleet-assets/fuel` — `fleet-assets.fuel.store` — `App\Http\Controllers\FleetAssets\VehicleController@storeFuel` — `app/Http/Controllers/FleetAssets/VehicleController.php:885` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/trips` — `fleet-assets.trips.index` — `App\Http\Controllers\FleetAssets\VehicleController@trips` — `app/Http/Controllers/FleetAssets/VehicleController.php:489` — middleware `web, auth, permission:fleet.viewAny`
- `POST fleet-assets/trips/{trip}/toggle-personal` — `fleet-assets.trips.toggle-personal` — `App\Http\Controllers\FleetAssets\VehicleController@markPersonal` — `app/Http/Controllers/FleetAssets/VehicleController.php:478` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/vehicles` — `fleet-assets.vehicles.index` — `App\Http\Controllers\FleetAssets\VehicleController@index` — `app/Http/Controllers/FleetAssets/VehicleController.php:45` — middleware `web, auth, permission:fleet.viewAny`
- `GET|HEAD fleet-assets/vehicles/{asset}` — `fleet-assets.vehicles.show` — `App\Http\Controllers\FleetAssets\VehicleController@show` — `app/Http/Controllers/FleetAssets/VehicleController.php:208` — middleware `web, auth, permission:fleet.viewAny`
- `PUT fleet-assets/vehicles/{asset}` — `fleet-assets.vehicles.update` — `App\Http\Controllers\FleetAssets\VehicleController@update` — `app/Http/Controllers/FleetAssets/VehicleController.php:437` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/vehicles/{asset}/alerts-config` — `fleet-assets.vehicles.alerts-config` — `App\Http\Controllers\FleetAssets\VehicleController@alertsConfig` — `app/Http/Controllers/FleetAssets/VehicleController.php:941` — middleware `web, auth, permission:fleet.viewAny`
- `POST fleet-assets/vehicles/{asset}/alerts-config` — `fleet-assets.vehicles.alerts-config.save` — `App\Http\Controllers\FleetAssets\VehicleController@saveAlertsConfig` — `app/Http/Controllers/FleetAssets/VehicleController.php:968` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/vehicles/bulk-action` — `fleet-assets.vehicles.bulk-action` — `App\Http\Controllers\FleetAssets\VehicleController@bulkAction` — `app/Http/Controllers/FleetAssets/VehicleController.php:909` — middleware `web, auth, permission:fleet.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/VehicleController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/fuel/index.tsx`, `resources/js/pages/fleet-assets/trips/index.tsx`, `resources/js/pages/fleet-assets/vehicles/alerts-config.tsx`, `resources/js/pages/fleet-assets/vehicles/index.tsx`, `resources/js/pages/fleet-assets/vehicles/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
