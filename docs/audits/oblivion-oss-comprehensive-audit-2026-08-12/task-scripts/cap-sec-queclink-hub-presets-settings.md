# CAP-SEC-QUECLINK-HUB-PRESETS-SETTINGS: Queclink presets and hub settings

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
2. Invoke only the owning control for `POST security-devices/integrations/queclink/devices/{queclinkDevice}/presets/{preset}/apply` (`security-devices.integrations.queclink.presets.apply`, action `applyPreset`). Source category: **mutation outcome source gap (applyPreset)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:497-539`; no exact validation fields extracted.
3. Invoke only the owning control for `POST security-devices/integrations/queclink/presets` (`security-devices.integrations.queclink.presets.store`, action `storePreset`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:545-593`; `name`.
4. Invoke only the owning control for `DELETE security-devices/integrations/queclink/presets/{preset}` (`security-devices.integrations.queclink.presets.destroy`, action `destroyPreset`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:595-608`; no exact validation fields extracted.
5. Invoke only the owning control for `POST security-devices/integrations/queclink/settings` (`security-devices.integrations.queclink.settings`, action `saveSettings`). Source category: **mutation outcome source gap (saveSettings)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:145-167`; `port`.

## Source-applicable states and transitions

- **mutation outcome source gap (applyPreset)** is applicable only to `applyPreset` / `ROUTE-2580` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:497`; it is not runtime-observed.
- **created/recorded** is applicable only to `storePreset` / `ROUTE-2586` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:545`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyPreset` / `ROUTE-2587` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:595`; it is not runtime-observed.
- **mutation outcome source gap (saveSettings)** is applicable only to `saveSettings` / `ROUTE-2590` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:145`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2580` / `applyPreset`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:538 `return back()->with('success', "Preset \"{$preset->name}\" queued ({$queued} command".($queued === 1 ? '' : 's').').');`; failure app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:505 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:512 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:524 `throw ValidationException::withMessages(['preset' => $e->getMessage()]);`.
- `ROUTE-2586` / `storePreset`: fields `name`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:592 `return back()->with('success', "Preset \"{$preset->name}\" saved.");`; failure app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:562 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:570 `throw ValidationException::withMessages(['sections' => $e->getMessage()]);`.
- `ROUTE-2587` / `destroyPreset`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:607 `return back()->with('success', "Preset \"{$name}\" deleted.");`.
- `ROUTE-2590` / `saveSettings`: fields `port`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:166 `return back()->with('success', 'Listener settings saved.');`.

## Failure and recovery paths

- `applyPreset`: app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:505 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:512 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:524 `throw ValidationException::withMessages(['preset' => $e->getMessage()]);`.
- `storePreset`: app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:562 `throw ValidationException::withMessages([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:570 `throw ValidationException::withMessages(['sections' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:581 `$preset = QueclinkPreset::create([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:605 `$preset->delete();`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:155 `AppSetting::updateOrCreate(['key' => 'queclink.listener.port'], ['value' => (int) $validated['port']]);`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:156 `AppSetting::updateOrCreate(['key' => 'queclink.public_hostname'], ['value' => (string) ($validated['public_hostname'] ?? '')]);`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:538 `return back()->with('success', "Preset \"{$preset->name}\" queued ({$queued} command".($queued === 1 ? '' : 's').').');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:592 `return back()->with('success', "Preset \"{$preset->name}\" saved.");`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:607 `return back()->with('success', "Preset \"{$name}\" deleted.");`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:162 `return back()->with('warning', 'Settings saved, but the listener could not be restarted automatically: '.$e->getMessage());`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:166 `return back()->with('success', 'Listener settings saved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/integrations/queclink/devices/{queclinkDevice}/presets/{preset}/apply` — `security-devices.integrations.queclink.presets.apply` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@applyPreset` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:497` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/presets` — `security-devices.integrations.queclink.presets.store` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@storePreset` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:545` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `DELETE security-devices/integrations/queclink/presets/{preset}` — `security-devices.integrations.queclink.presets.destroy` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@destroyPreset` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:595` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/settings` — `security-devices.integrations.queclink.settings` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@saveSettings` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:145` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
