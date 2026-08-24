# CAP-SEC-UNIFI-DEFAULTS-HARDWARE: UniFi defaults and hardware-room assignment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`
- Owning module: Security and devices
- Legacy family: `SEC-UNIFI`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/integrations/unifi` (`security-devices.integrations.unifi`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/integrations/unifi` (`security-devices.integrations.unifi`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT security-devices/integrations/unifi/defaults` (`security-devices.integrations.unifi.defaults`, action `updateDefaults`). Source category: **updated/revised**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:603-632`; `config`.
3. Invoke only the owning control for `PUT security-devices/integrations/unifi/hardware/{hardware}/room` (`security-devices.integrations.unifi.assign-room`, action `assignHardwareRoom`). Source category: **mutation outcome source gap (assignHardwareRoom)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:559-598`; `room_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2593` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:28`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateDefaults` / `ROUTE-2594` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:603`; it is not runtime-observed.
- **mutation outcome source gap (assignHardwareRoom)** is applicable only to `assignHardwareRoom` / `ROUTE-2595` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:559`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/integrations/unifi.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2594` / `updateDefaults`: fields `config`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:631 `return redirect()->back()->with('success', 'Default settings updated.');`.
- `ROUTE-2595` / `assignHardwareRoom`: fields `room_id`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:597 `return redirect()->back()->with('success', 'Device room assignment updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:624 `$secret->update([`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:116 `return [`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:168 `return Inertia::render('security-devices/integrations/unifi', [`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:631 `return redirect()->back()->with('success', 'Default settings updated.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:586 `return redirect()->back()->with('error', 'Selected room does not belong to this tenant.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:591 `return redirect()->back()->with('error', 'Selected room does not belong to the device location.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:597 `return redirect()->back()->with('success', 'Device room assignment updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/integrations/unifi` — `security-devices.integrations.unifi` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@index` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:28` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `PUT security-devices/integrations/unifi/defaults` — `security-devices.integrations.unifi.defaults` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@updateDefaults` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:603` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `PUT security-devices/integrations/unifi/hardware/{hardware}/room` — `security-devices.integrations.unifi.assign-room` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@assignHardwareRoom` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:559` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/integrations/unifi.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
