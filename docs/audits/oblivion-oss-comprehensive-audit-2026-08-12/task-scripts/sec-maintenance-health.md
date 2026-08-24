# SEC-MAINTENANCE-HEALTH: Maintenance Health

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.maintenance.manage`, `permission:securityDevices.maintenance.view`
- Owning module: Security and devices
- Legacy family: `SEC-MAINTENANCE-HEALTH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/maintenance-health` (`security-devices.maintenance-health`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.maintenance.manage`, `permission:securityDevices.maintenance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.maintenance.manage`, `permission:securityDevices.maintenance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/maintenance-health` (`security-devices.maintenance-health`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST security-devices/devices/{device}/maintenance` (`security-devices.maintenance.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:141-170`; `type`.
3. Invoke only the owning control for `PUT security-devices/maintenance/{record}` (`security-devices.maintenance.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:175-201`; `type`.
4. Invoke only the owning control for `POST security-devices/maintenance/{record}/complete` (`security-devices.maintenance.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:206-218`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2556` at `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:141`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-2604` at `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:18`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2605` at `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:175`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2606` at `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:206`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/maintenance-health.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2556` / `store`: fields `type`; success app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:169 `return back()->with('success', 'Maintenance record created.');`.
- `ROUTE-2605` / `update`: fields `type`; success app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:200 `return back()->with('success', 'Maintenance record updated.');`.
- `ROUTE-2606` / `complete`: success app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:217 `return back()->with('success', 'Maintenance marked as complete.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:167 `DeviceMaintenanceRecord::create($validated);`; app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:198 `$record->update($validated);`; app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:211 `$record->update([`; responses app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:169 `return back()->with('success', 'Maintenance record created.');`; app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:103 `return Inertia::render('security-devices/maintenance-health', [`; app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:200 `return back()->with('success', 'Maintenance record updated.');`; app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:217 `return back()->with('success', 'Maintenance marked as complete.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/devices/{device}/maintenance` — `security-devices.maintenance.store` — `App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController@store` — `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:141` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.maintenance.manage`
- `GET|HEAD security-devices/maintenance-health` — `security-devices.maintenance-health` — `App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController@index` — `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:18` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.maintenance.view`
- `PUT security-devices/maintenance/{record}` — `security-devices.maintenance.update` — `App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController@update` — `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:175` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.maintenance.manage`
- `POST security-devices/maintenance/{record}/complete` — `security-devices.maintenance.complete` — `App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController@complete` — `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php:206` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.maintenance.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/MaintenanceHealthController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/maintenance-health.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
