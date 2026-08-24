# SEC-MILESIGHT: Milesight

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`
- Owning module: Security and devices
- Legacy family: `SEC-MILESIGHT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/integrations/milesight` (`security-devices.integrations.milesight`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/integrations/milesight` (`security-devices.integrations.milesight`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE security-devices/integrations/milesight/key` (`security-devices.integrations.milesight.remove`, action `removeKey`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:211-231`; no exact validation fields extracted.
3. Invoke only the owning control for `POST security-devices/integrations/milesight/key` (`security-devices.integrations.milesight.key`, action `saveKey`). Source category: **mutation outcome source gap (saveKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:77-118`; `api_key`.
4. Invoke only the owning control for `POST security-devices/integrations/milesight/rotate` (`security-devices.integrations.milesight.rotate`, action `rotateKey`). Source category: **mutation outcome source gap (rotateKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:184-209`; `api_key`.
5. Invoke only the owning control for `POST security-devices/integrations/milesight/test` (`security-devices.integrations.milesight.test`, action `testKey`). Source category: **mutation outcome source gap (testKey)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:120-182`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2563` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:26`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeKey` / `ROUTE-2564` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:211`; it is not runtime-observed.
- **mutation outcome source gap (saveKey)** is applicable only to `saveKey` / `ROUTE-2565` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:77`; it is not runtime-observed.
- **mutation outcome source gap (rotateKey)** is applicable only to `rotateKey` / `ROUTE-2566` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:184`; it is not runtime-observed.
- **mutation outcome source gap (testKey)** is applicable only to `testKey` / `ROUTE-2567` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:120`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/integrations/milesight.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2564` / `removeKey`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:230 `return redirect()->back()->with('success', 'Milesight credentials removed.');`.
- `ROUTE-2565` / `saveKey`: fields `api_key`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:117 `return redirect()->back()->with('success', 'Milesight API key saved. Run Test Connection to verify.');`.
- `ROUTE-2566` / `rotateKey`: fields `api_key`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:208 `return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');`.
- `ROUTE-2567` / `testKey`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:159 `return redirect()->back()->with('success', 'Milesight connection test succeeded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:221 `->delete();`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:226 `->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:90 `IntegrationTenantSecret::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:105 `Integration::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:200 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:140 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:146 `Integration::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:162 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:168 `Integration::updateOrCreate(`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:61 `return Inertia::render('security-devices/integrations/milesight', [`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:230 `return redirect()->back()->with('success', 'Milesight credentials removed.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:117 `return redirect()->back()->with('success', 'Milesight API key saved. Run Test Connection to verify.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:208 `return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:133 `return redirect()->back()->with('error', 'Milesight adapter is not registered.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:159 `return redirect()->back()->with('success', 'Milesight connection test succeeded.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:181 `return redirect()->back()->with('error', 'Milesight connection test failed. Check the API key and server URL.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/integrations/milesight` — `security-devices.integrations.milesight` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController@index` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:26` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `DELETE security-devices/integrations/milesight/key` — `security-devices.integrations.milesight.remove` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController@removeKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:211` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/milesight/key` — `security-devices.integrations.milesight.key` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController@saveKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:77` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/milesight/rotate` — `security-devices.integrations.milesight.rotate` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController@rotateKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:184` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/milesight/test` — `security-devices.integrations.milesight.test` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController@testKey` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php:120` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/integrations/milesight.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
