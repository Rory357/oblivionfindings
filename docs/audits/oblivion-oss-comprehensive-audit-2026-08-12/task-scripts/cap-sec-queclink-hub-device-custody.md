# CAP-SEC-QUECLINK-HUB-DEVICE-CUSTODY: Queclink device claim rejection release and bulk control

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`
- Owning module: Security and devices
- Legacy family: `SEC-QUECLINK-HUB`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/integrations/queclink` (`security-devices.integrations.queclink`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/integrations/queclink` (`security-devices.integrations.queclink`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST security-devices/integrations/queclink/bulk` (`security-devices.integrations.queclink.bulk`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:672-764`; FormRequest `app/Http/Requests/Queclink/BulkActionRequest.php:16`; `device_ids`, `action`, `section`, `preset_id`.
3. Invoke only the owning control for `POST security-devices/integrations/queclink/devices/{queclinkDevice}/claim` (`security-devices.integrations.queclink.claim`, action `claimDevice`). Source category: **mutation outcome source gap (claimDevice)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:169-248`; `pairing_type`.
4. Invoke only the owning control for `POST security-devices/integrations/queclink/devices/{queclinkDevice}/reject` (`security-devices.integrations.queclink.reject`, action `rejectDevice`). Source category: **rejected/returned**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:284-292`; no exact validation fields extracted.
5. Invoke only the owning control for `POST security-devices/integrations/queclink/devices/{queclinkDevice}/release` (`security-devices.integrations.queclink.release`, action `releaseDevice`). Source category: **completed/closed/released**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:294-325`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2568` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:53`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-2569` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:672`; it is not runtime-observed.
- **mutation outcome source gap (claimDevice)** is applicable only to `claimDevice` / `ROUTE-2572` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:169`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectDevice` / `ROUTE-2581` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:284`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `releaseDevice` / `ROUTE-2582` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:294`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/integrations/queclink-hub.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2569` / `bulkAction`: FormRequest `app/Http/Requests/Queclink/BulkActionRequest.php:16`; fields `device_ids`, `action`, `section`, `preset_id`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:763 `return back()->with('success', "Bulk action queued for {$queued} device(s).");`; failure app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:685 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:692 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:704 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:713 `throw ValidationException::withMessages([`.
- `ROUTE-2572` / `claimDevice`: fields `pairing_type`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:246 `return back()->with('success', "Device {$queclinkDevice->imei} paired.");`.
- `ROUTE-2581` / `rejectDevice`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:291 `return back()->with('success', "Device {$queclinkDevice->imei} rejected.");`.
- `ROUTE-2582` / `releaseDevice`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:324 `return back()->with('success', "Device {$queclinkDevice->imei} released — moved back to pending.");`.

## Failure and recovery paths

- `bulkAction`: app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:685 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:692 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:704 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:713 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:208 `DeviceAssignment::create([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:218 `AssetTracker::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:232 `$queclinkDevice->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:288 `$queclinkDevice->update(['status' => QueclinkDevice::STATUS_REJECTED]);`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:303 `->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:311 `->update(['status' => 'unpaired', 'unpaired_at' => now()]);`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:313 `$queclinkDevice->update([`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:81 `return Inertia::render('security-devices/integrations/queclink-hub', [`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:763 `return back()->with('success', "Bulk action queued for {$queued} device(s).");`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:181 `return DB::transaction(function () use ($queclinkDevice, $validated, $request) {`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:246 `return back()->with('success', "Device {$queclinkDevice->imei} paired.");`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:291 `return back()->with('success', "Device {$queclinkDevice->imei} rejected.");`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:324 `return back()->with('success', "Device {$queclinkDevice->imei} released — moved back to pending.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/integrations/queclink` — `security-devices.integrations.queclink` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@index` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:53` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/bulk` — `security-devices.integrations.queclink.bulk` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@bulkAction` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:672` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/devices/{queclinkDevice}/claim` — `security-devices.integrations.queclink.claim` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@claimDevice` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:169` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/devices/{queclinkDevice}/reject` — `security-devices.integrations.queclink.reject` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@rejectDevice` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:284` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/devices/{queclinkDevice}/release` — `security-devices.integrations.queclink.release` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@releaseDevice` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:294` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/integrations/queclink-hub.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
