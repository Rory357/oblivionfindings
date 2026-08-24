# CAP-SEC-DEVICE-IDENTITY-ATTRIBUTES: Security-device identity lifecycle and fields

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.view`, `permission:securityDevices.devices.create`, `permission:securityDevices.devices.delete`, `permission:securityDevices.devices.update`
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

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.view`, `permission:securityDevices.devices.create`, `permission:securityDevices.devices.delete`, `permission:securityDevices.devices.update`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.view`, `permission:securityDevices.devices.create`, `permission:securityDevices.devices.delete`, `permission:securityDevices.devices.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/devices` (`security-devices.devices.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD security-devices/devices/{device}` (`security-devices.devices.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:100-256`.
3. Use `GET|HEAD security-devices/devices/{device}/edit` (`security-devices.devices.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:438-449`.
4. Use `GET|HEAD security-devices/devices/create` (`security-devices.devices.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:387-403`.
5. Invoke only the owning control for `POST security-devices/devices` (`security-devices.devices.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:405-436`; `name`.
6. Invoke only the owning control for `DELETE security-devices/devices/{device}` (`security-devices.devices.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:506-516`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT security-devices/devices/{device}` (`security-devices.devices.store.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:475-504`; `name`.
8. Invoke only the owning control for `PATCH security-devices/devices/{device}/fields` (`security-devices.devices.patch-fields`, action `patchFields`). Source category: **updated/revised**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:458-473`; `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2542` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:35`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2543` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:405`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2544` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:506`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2545` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:100`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2546` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:475`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2554` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:438`; it is not runtime-observed.
- **updated/revised** is applicable only to `patchFields` / `ROUTE-2555` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:458`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2560` at `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:387`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/devices/create.tsx`, `resources/js/pages/security-devices/devices/edit.tsx`, `resources/js/pages/security-devices/devices/index.tsx`, `resources/js/pages/security-devices/devices/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2543` / `store`: fields `name`; success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:435 `->with('success', "Device '{$device->name}' registered.");`.
- `ROUTE-2544` / `destroy`: success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:515 `->with('success', "Device '{$device->name}' decommissioned.");`.
- `ROUTE-2546` / `update`: fields `name`; success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:503 `->with('success', "Device '{$device->name}' updated.");`.
- `ROUTE-2555` / `patchFields`: fields `notes`; success app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:472 `return redirect()->back()->with('success', 'Device updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:432 `$device = Device::create($validated);`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:511 `$device->update(['status' => DeviceStatus::Decommissioned->value]);`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:512 `$device->delete(); // soft delete`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:500 `$device->update($validated);`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:470 `$device->update($validated);`; responses app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:79 `return Inertia::render('security-devices/devices/index', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:434 `return redirect()->route('security-devices.devices.show', $device)`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:514 `return redirect()->route('security-devices.devices.index')`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:128 `return Inertia::render('security-devices/devices/show', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:502 `return redirect()->route('security-devices.devices.show', $device)`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:443 `return Inertia::render('security-devices/devices/edit', [`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:472 `return redirect()->back()->with('success', 'Device updated.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:397 `return Inertia::render('security-devices/devices/create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/devices` — `security-devices.devices.index` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@index` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:35` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.view`
- `POST security-devices/devices` — `security-devices.devices.store` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@store` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:405` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.create`
- `DELETE security-devices/devices/{device}` — `security-devices.devices.destroy` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@destroy` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:506` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.delete`
- `GET|HEAD security-devices/devices/{device}` — `security-devices.devices.show` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@show` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:100` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.view`
- `PUT security-devices/devices/{device}` — `security-devices.devices.store.update` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@update` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:475` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `GET|HEAD security-devices/devices/{device}/edit` — `security-devices.devices.edit` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@edit` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:438` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `PATCH security-devices/devices/{device}/fields` — `security-devices.devices.patch-fields` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@patchFields` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:458` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `GET|HEAD security-devices/devices/create` — `security-devices.devices.create` — `App\Domain\SecurityDevices\Http\Controllers\DeviceController@create` — `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:387` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.create`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/devices/create.tsx`, `resources/js/pages/security-devices/devices/edit.tsx`, `resources/js/pages/security-devices/devices/index.tsx`, `resources/js/pages/security-devices/devices/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
