# FLEET-DEVICE: Device

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.trackers.manage`, `permission:fleet.manage|assets.trackers.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-DEVICE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/devices` (`fleet-assets.devices.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.trackers.manage`, `permission:fleet.manage|assets.trackers.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.trackers.manage`, `permission:fleet.manage|assets.trackers.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/devices` (`fleet-assets.devices.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/devices/{device}` (`fleet-assets.devices.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/DeviceController.php:145-151`.
3. Use `GET|HEAD fleet-assets/devices/consent` (`fleet-assets.devices.consent`, action `consentIndex`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/DeviceController.php:270-276`.
4. Invoke only the owning control for `POST fleet-assets/devices/{device}/consent/grant` (`fleet-assets.devices.consent.grant`, action `grantConsent`). Source category: **mutation outcome source gap (grantConsent)**; controller `app/Http/Controllers/FleetAssets/DeviceController.php:353-428`; `notes`.
5. Invoke only the owning control for `POST fleet-assets/devices/{device}/consent/revoke` (`fleet-assets.devices.consent.revoke`, action `revokeConsent`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/FleetAssets/DeviceController.php:430-470`; `reason`.
6. Invoke only the owning control for `POST fleet-assets/devices/{device}/unpair` (`fleet-assets.devices.unpair`, action `unpair`). Source category: **mutation outcome source gap (unpair)**; controller `app/Http/Controllers/FleetAssets/DeviceController.php:242-264`; no exact validation fields extracted.
7. Invoke only the owning control for `POST fleet-assets/devices/pair` (`fleet-assets.devices.pair`, action `pair`). Source category: **mutation outcome source gap (pair)**; controller `app/Http/Controllers/FleetAssets/DeviceController.php:202-237`; `asset_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0724` at `app/Http/Controllers/FleetAssets/DeviceController.php:30`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0725` at `app/Http/Controllers/FleetAssets/DeviceController.php:145`; it is not runtime-observed.
- **mutation outcome source gap (grantConsent)** is applicable only to `grantConsent` / `ROUTE-0726` at `app/Http/Controllers/FleetAssets/DeviceController.php:353`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `revokeConsent` / `ROUTE-0727` at `app/Http/Controllers/FleetAssets/DeviceController.php:430`; it is not runtime-observed.
- **mutation outcome source gap (unpair)** is applicable only to `unpair` / `ROUTE-0728` at `app/Http/Controllers/FleetAssets/DeviceController.php:242`; it is not runtime-observed.
- **information presented** is applicable only to `consentIndex` / `ROUTE-0729` at `app/Http/Controllers/FleetAssets/DeviceController.php:270`; it is not runtime-observed.
- **mutation outcome source gap (pair)** is applicable only to `pair` / `ROUTE-0730` at `app/Http/Controllers/FleetAssets/DeviceController.php:202`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/devices/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0726` / `grantConsent`: fields `notes`; success app/Http/Controllers/FleetAssets/DeviceController.php:427 `return back()->with('success', 'Location tracking consent granted.');`; failure app/Http/Controllers/FleetAssets/DeviceController.php:366 `return back()->withErrors(['consent' => 'A linked client is required before consent can be recorded.']);`.
- `ROUTE-0727` / `revokeConsent`: fields `reason`; success app/Http/Controllers/FleetAssets/DeviceController.php:469 `return back()->with('success', 'Location tracking consent revoked.');`; failure app/Http/Controllers/FleetAssets/DeviceController.php:446 `return back()->withErrors(['consent' => 'No active consent to revoke.']);`.
- `ROUTE-0728` / `unpair`: success app/Http/Controllers/FleetAssets/DeviceController.php:263 `return back()->with('success', 'Device unlinked from vehicle.');`; failure app/Http/Controllers/FleetAssets/DeviceController.php:255 `return back()->withErrors(['device' => $e->getMessage()]);`.
- `ROUTE-0730` / `pair`: fields `asset_id`; success app/Http/Controllers/FleetAssets/DeviceController.php:236 `return back()->with('success', 'Device linked to vehicle.');`; failure app/Http/Controllers/FleetAssets/DeviceController.php:218 `return back()->withErrors(['device_id' => 'Device is already linked to another asset. Unlink it first.']);`; app/Http/Controllers/FleetAssets/DeviceController.php:228 `return back()->withErrors(['device_id' => $e->getMessage()]);`.

## Failure and recovery paths

- `grantConsent`: app/Http/Controllers/FleetAssets/DeviceController.php:366 `return back()->withErrors(['consent' => 'A linked client is required before consent can be recorded.']);`.
- `revokeConsent`: app/Http/Controllers/FleetAssets/DeviceController.php:446 `return back()->withErrors(['consent' => 'No active consent to revoke.']);`.
- `unpair`: app/Http/Controllers/FleetAssets/DeviceController.php:255 `return back()->withErrors(['device' => $e->getMessage()]);`.
- `pair`: app/Http/Controllers/FleetAssets/DeviceController.php:218 `return back()->withErrors(['device_id' => 'Device is already linked to another asset. Unlink it first.']);`; app/Http/Controllers/FleetAssets/DeviceController.php:228 `return back()->withErrors(['device_id' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/DeviceController.php:374 `$consentType = ConsentType::create([`; app/Http/Controllers/FleetAssets/DeviceController.php:390 `$consent = ClientConsent::create([`; app/Http/Controllers/FleetAssets/DeviceController.php:407 `$oldConsent->update(['superseded_by_consent_id' => $consent->id]);`; app/Http/Controllers/FleetAssets/DeviceController.php:411 `$assignment->update(['consent_id' => $consent->id]);`; app/Http/Controllers/FleetAssets/DeviceController.php:415 `$tracker->update(['consent_id' => $consent->id]);`; app/Http/Controllers/FleetAssets/DeviceController.php:450 `$consent->update([`; responses app/Http/Controllers/FleetAssets/DeviceController.php:40 `return response()->streamDownload(function () use ($allDevices) {`; app/Http/Controllers/FleetAssets/DeviceController.php:101 `return Inertia::render('fleet-assets/devices/index', [`; app/Http/Controllers/FleetAssets/DeviceController.php:147 `return redirect()->route(`; app/Http/Controllers/FleetAssets/DeviceController.php:366 `return back()->withErrors(['consent' => 'A linked client is required before consent can be recorded.']);`; app/Http/Controllers/FleetAssets/DeviceController.php:427 `return back()->with('success', 'Location tracking consent granted.');`; app/Http/Controllers/FleetAssets/DeviceController.php:446 `return back()->withErrors(['consent' => 'No active consent to revoke.']);`; app/Http/Controllers/FleetAssets/DeviceController.php:469 `return back()->with('success', 'Location tracking consent revoked.');`; app/Http/Controllers/FleetAssets/DeviceController.php:247 `return back()->with('info', 'Device has no active asset link.');`; app/Http/Controllers/FleetAssets/DeviceController.php:255 `return back()->withErrors(['device' => $e->getMessage()]);`; app/Http/Controllers/FleetAssets/DeviceController.php:263 `return back()->with('success', 'Device unlinked from vehicle.');`; app/Http/Controllers/FleetAssets/DeviceController.php:272 `return redirect()->route(`; app/Http/Controllers/FleetAssets/DeviceController.php:218 `return back()->withErrors(['device_id' => 'Device is already linked to another asset. Unlink it first.']);`; app/Http/Controllers/FleetAssets/DeviceController.php:222 `return back()->with('info', 'Device is already linked to this asset.');`; app/Http/Controllers/FleetAssets/DeviceController.php:228 `return back()->withErrors(['device_id' => $e->getMessage()]);`; app/Http/Controllers/FleetAssets/DeviceController.php:236 `return back()->with('success', 'Device linked to vehicle.');`; audit calls app/Http/Controllers/FleetAssets/DeviceController.php:418 `AuditLogger::log('assets.tracker.consent.granted', $asset ?? $device, [`; app/Http/Controllers/FleetAssets/DeviceController.php:460 `AuditLogger::log('assets.tracker.consent.revoked', $asset ?? $device, [`; app/Http/Controllers/FleetAssets/DeviceController.php:258 `AuditLogger::log('assets.device.unlinked', $asset, [`; app/Http/Controllers/FleetAssets/DeviceController.php:231 `AuditLogger::log('assets.device.linked', $asset, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/devices` — `fleet-assets.devices.index` — `App\Http\Controllers\FleetAssets\DeviceController@index` — `app/Http/Controllers/FleetAssets/DeviceController.php:30` — middleware `web, auth, permission:fleet.viewAny|assets.trackers.manage`
- `GET|HEAD fleet-assets/devices/{device}` — `fleet-assets.devices.show` — `App\Http\Controllers\FleetAssets\DeviceController@show` — `app/Http/Controllers/FleetAssets/DeviceController.php:145` — middleware `web, auth, permission:fleet.viewAny|assets.trackers.manage`
- `POST fleet-assets/devices/{device}/consent/grant` — `fleet-assets.devices.consent.grant` — `App\Http\Controllers\FleetAssets\DeviceController@grantConsent` — `app/Http/Controllers/FleetAssets/DeviceController.php:353` — middleware `web, auth, permission:fleet.manage|assets.trackers.manage`
- `POST fleet-assets/devices/{device}/consent/revoke` — `fleet-assets.devices.consent.revoke` — `App\Http\Controllers\FleetAssets\DeviceController@revokeConsent` — `app/Http/Controllers/FleetAssets/DeviceController.php:430` — middleware `web, auth, permission:fleet.manage|assets.trackers.manage`
- `POST fleet-assets/devices/{device}/unpair` — `fleet-assets.devices.unpair` — `App\Http\Controllers\FleetAssets\DeviceController@unpair` — `app/Http/Controllers/FleetAssets/DeviceController.php:242` — middleware `web, auth, permission:fleet.manage|assets.trackers.manage`
- `GET|HEAD fleet-assets/devices/consent` — `fleet-assets.devices.consent` — `App\Http\Controllers\FleetAssets\DeviceController@consentIndex` — `app/Http/Controllers/FleetAssets/DeviceController.php:270` — middleware `web, auth, permission:fleet.viewAny|assets.trackers.manage`
- `POST fleet-assets/devices/pair` — `fleet-assets.devices.pair` — `App\Http\Controllers\FleetAssets\DeviceController@pair` — `app/Http/Controllers/FleetAssets/DeviceController.php:202` — middleware `web, auth, permission:fleet.manage|assets.trackers.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/DeviceController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/devices/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
