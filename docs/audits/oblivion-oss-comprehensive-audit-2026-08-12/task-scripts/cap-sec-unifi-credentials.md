# CAP-SEC-UNIFI-CREDENTIALS: UniFi key setup testing and rotation

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
2. Invoke only the owning control for `POST security-devices/integrations/unifi/key` (`security-devices.integrations.unifi.key`, action `saveKey`). Source category: **mutation outcome source gap (saveKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:197-235`; `api_key`.
3. Invoke only the owning control for `POST security-devices/integrations/unifi/rotate` (`security-devices.integrations.unifi.rotate`, action `rotateKey`). Source category: **mutation outcome source gap (rotateKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:307-332`; `api_key`.
4. Invoke only the owning control for `POST security-devices/integrations/unifi/test` (`security-devices.integrations.unifi.test`, action `testKey`). Source category: **mutation outcome source gap (testKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:240-302`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (saveKey)** is applicable only to `saveKey` / `ROUTE-2596` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:197`; it is not runtime-observed.
- **mutation outcome source gap (rotateKey)** is applicable only to `rotateKey` / `ROUTE-2599` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:307`; it is not runtime-observed.
- **mutation outcome source gap (testKey)** is applicable only to `testKey` / `ROUTE-2602` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:240`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2596` / `saveKey`: fields `api_key`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:234 `return redirect()->back()->with('success', 'UniFi API key saved.');`.
- `ROUTE-2599` / `rotateKey`: fields `api_key`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:331 `return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');`.
- `ROUTE-2602` / `testKey`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:279 `return redirect()->back()->with('success', 'UniFi connection test succeeded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:208 `IntegrationTenantSecret::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:222 `Integration::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:323 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:260 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:266 `Integration::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:282 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:288 `Integration::updateOrCreate(`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:234 `return redirect()->back()->with('success', 'UniFi API key saved.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:331 `return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:253 `return redirect()->back()->with('error', 'UniFi adapter is not registered.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:279 `return redirect()->back()->with('success', 'UniFi connection test succeeded.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:301 `return redirect()->back()->with('error', 'UniFi connection test failed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/integrations/unifi/key` — `security-devices.integrations.unifi.key` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@saveKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:197` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/unifi/rotate` — `security-devices.integrations.unifi.rotate` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@rotateKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:307` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/unifi/test` — `security-devices.integrations.unifi.test` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@testKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:240` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
