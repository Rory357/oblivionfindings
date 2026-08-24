# CAP-SEC-DEVICE-LINKAGE: Security-device asset and related-device linkage

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`
- Owning module: Security and devices
- Legacy family: `SEC-DEVICE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/devices` (`security-devices.devices.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/devices` (`security-devices.devices.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST security-devices/devices/{device}/asset-links` (`security-devices.devices.asset-links.store`, action `linkAsset`). Source category: **mutation outcome source gap (linkAsset)**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:261-287`; `asset_id`.
3. Invoke only the owning control for `DELETE security-devices/devices/{device}/asset-links/{link}` (`security-devices.devices.asset-links.destroy`, action `unlinkAsset`). Source category: **mutation outcome source gap (unlinkAsset)**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:367-385`; no exact validation fields extracted.
4. Invoke only the owning control for `POST security-devices/devices/{device}/relationships` (`security-devices.devices.relationships.store`, action `linkRelated`). Source category: **mutation outcome source gap (linkRelated)**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:296-338`; `other_device_id`.
5. Invoke only the owning control for `DELETE security-devices/devices/{device}/relationships/{relationship}` (`security-devices.devices.relationships.destroy`, action `unlinkRelated`). Source category: **mutation outcome source gap (unlinkRelated)**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:344-360`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (linkAsset)** is applicable only to `linkAsset` / `ROUTE-2547` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:261`; it is not runtime-observed.
- **mutation outcome source gap (unlinkAsset)** is applicable only to `unlinkAsset` / `ROUTE-2548` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:367`; it is not runtime-observed.
- **mutation outcome source gap (linkRelated)** is applicable only to `linkRelated` / `ROUTE-2557` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:296`; it is not runtime-observed.
- **mutation outcome source gap (unlinkRelated)** is applicable only to `unlinkRelated` / `ROUTE-2558` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:344`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2547` / `linkAsset`: fields `asset_id`; success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:286 `return redirect()->back()->with('success', "Device linked to asset {$asset->name}.");`.
- `ROUTE-2548` / `unlinkAsset`: success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:384 `return redirect()->back()->with('success', 'Asset unlinked.');`.
- `ROUTE-2557` / `linkRelated`: fields `other_device_id`; success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:337 `return redirect()->back()->with('success', 'Relationship added.');`.
- `ROUTE-2558` / `unlinkRelated`: success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:359 `return redirect()->back()->with('success', 'Relationship removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:329 `DeviceRelationship::create([`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:357 `$relationship->delete();`; responses app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:283 `return redirect()->back()->with('error', $e->getMessage());`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:286 `return redirect()->back()->with('success', "Device linked to asset {$asset->name}.");`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:381 `return redirect()->back()->with('error', $e->getMessage());`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:384 `return redirect()->back()->with('success', 'Asset unlinked.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:313 `return redirect()->back()->with('error', 'Cannot link devices from different tenants.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:326 `return redirect()->back()->with('error', 'That relationship already exists.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:337 `return redirect()->back()->with('success', 'Relationship added.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:359 `return redirect()->back()->with('success', 'Relationship removed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/devices/{device}/asset-links` — `security-devices.devices.asset-links.store` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@linkAsset` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:261` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `DELETE security-devices/devices/{device}/asset-links/{link}` — `security-devices.devices.asset-links.destroy` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@unlinkAsset` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:367` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `POST security-devices/devices/{device}/relationships` — `security-devices.devices.relationships.store` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@linkRelated` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:296` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `DELETE security-devices/devices/{device}/relationships/{relationship}` — `security-devices.devices.relationships.destroy` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@unlinkRelated` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:344` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
