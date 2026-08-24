# SEC-DEVICE-ASSIGNMENT: Device Assignment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.assign`, `permission:securityDevices.devices.view`
- Owning module: Security and devices
- Legacy family: `SEC-DEVICE-ASSIGNMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/devices/{device}/assignments` (`security-devices.devices.assignments`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.assign`, `permission:securityDevices.devices.view`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.assign`, `permission:securityDevices.devices.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/devices/{device}/assignments` (`security-devices.devices.assignments`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST security-devices/devices/{device}/assign` (`security-devices.devices.assign`, action `assign`). Source category: **assigned**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:25-62`; `assignable_type`.
3. Invoke only the owning control for `POST security-devices/devices/{device}/release` (`security-devices.devices.release`, action `release`). Source category: **completed/closed/released**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:115-127`; no exact validation fields extracted.

## Source-applicable states and transitions

- **assigned** is applicable only to `assign` / `ROUTE-2549` at `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:25`; it is not runtime-observed.
- **information presented** is applicable only to `history` / `ROUTE-2550` at `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:132`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `release` / `ROUTE-2559` at `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:115`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2549` / `assign`: fields `assignable_type`; success app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:61 `return back()->with('success', 'Device assigned successfully.');`; failure app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:58 `return back()->withErrors(['assignable_type' => $e->getMessage()]);`.
- `ROUTE-2559` / `release`: success app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:126 `return back()->with('success', 'Device released to pool.');`.

## Failure and recovery paths

- `assign`: app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:58 `return back()->withErrors(['assignable_type' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:58 `return back()->withErrors(['assignable_type' => $e->getMessage()]);`; app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:61 `return back()->with('success', 'Device assigned successfully.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:143 `return response()->json([`; app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:123 `return back()->with('info', 'Device has no active assignment to release.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:126 `return back()->with('success', 'Device released to pool.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/devices/{device}/assign` — `security-devices.devices.assign` — `App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController@assign` — `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:25` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.assign`
- `GET|HEAD security-devices/devices/{device}/assignments` — `security-devices.devices.assignments` — `App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController@history` — `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:132` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.view`
- `POST security-devices/devices/{device}/release` — `security-devices.devices.release` — `App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController@release` — `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php:115` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.assign`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
