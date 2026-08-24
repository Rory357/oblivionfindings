# CAP-SEC-QUECLINK-HUB-COMMANDS-DIAGNOSTICS: Queclink commands frames and live stream

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`
- Owning module: Security and devices
- Legacy family: `SEC-QUECLINK-HUB`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/integrations/queclink/frames` (`security-devices.integrations.queclink.frames`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.integrations.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/integrations/queclink/frames` (`security-devices.integrations.queclink.frames`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD security-devices/integrations/queclink/stream` (`security-devices.integrations.queclink.stream`, action `stream`) only from its authorised surface; inspect the returned information. Source: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:824-904`.
3. Invoke only the owning control for `POST security-devices/integrations/queclink/commands/{command}/cancel` (`security-devices.integrations.queclink.commands.cancel`, action `cancelCommand`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:618-635`; no exact validation fields extracted.
4. Invoke only the owning control for `POST security-devices/integrations/queclink/commands/{command}/retry` (`security-devices.integrations.queclink.commands.retry`, action `retryCommand`). Source category: **retried/replayed/reconciled**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:637-670`; no exact validation fields extracted.
5. Invoke only the owning control for `POST security-devices/integrations/queclink/devices/{queclinkDevice}/command` (`security-devices.integrations.queclink.command`, action `sendCommand`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:327-368`; `mode`.

## Source-applicable states and transitions

- **cancelled/removed/archived** is applicable only to `cancelCommand` / `ROUTE-2570` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:618`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `retryCommand` / `ROUTE-2571` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:637`; it is not runtime-observed.
- **created/recorded** is applicable only to `sendCommand` / `ROUTE-2573` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:327`; it is not runtime-observed.
- **information presented** is applicable only to `frames` / `ROUTE-2583` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:770`; it is not runtime-observed.
- **information presented** is applicable only to `stream` / `ROUTE-2591` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:824`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2570` / `cancelCommand`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:634 `return back()->with('success', 'Queued command cancelled.');`.
- `ROUTE-2571` / `retryCommand`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:669 `return back()->with('success', 'Command retry queued.');`.
- `ROUTE-2573` / `sendCommand`: fields `mode`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:367 `return back()->with('success', 'Command queued — it will be sent on the device\'s next frame.');`; failure app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:347 `default => throw new \InvalidArgumentException('Unknown preset'),`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:352 `return back()->withErrors(['raw' => $e->getMessage()]);`.
- `ROUTE-2591` / `stream`: failure app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:836 `ignore_user_abort(false);`.

## Failure and recovery paths

- `sendCommand`: app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:347 `default => throw new \InvalidArgumentException('Unknown preset'),`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:352 `return back()->withErrors(['raw' => $e->getMessage()]);`.
- `stream`: app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:836 `ignore_user_abort(false);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:646 `$retry = QueclinkPendingCommand::create([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:355 `QueclinkPendingCommand::create([`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:634 `return back()->with('success', 'Queued command cancelled.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:669 `return back()->with('success', 'Command retry queued.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:352 `return back()->withErrors(['raw' => $e->getMessage()]);`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:367 `return back()->with('success', 'Command queued — it will be sent on the device\'s next frame.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:805 `return response()->json([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:835 `return new StreamedResponse(function () use (&$cursor, $imei, $direction, $commandWord, $parseStatus, $search) {`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/integrations/queclink/commands/{command}/cancel` — `security-devices.integrations.queclink.commands.cancel` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@cancelCommand` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:618` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/commands/{command}/retry` — `security-devices.integrations.queclink.commands.retry` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@retryCommand` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:637` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/queclink/devices/{queclinkDevice}/command` — `security-devices.integrations.queclink.command` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@sendCommand` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:327` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `GET|HEAD security-devices/integrations/queclink/frames` — `security-devices.integrations.queclink.frames` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@frames` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:770` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `GET|HEAD security-devices/integrations/queclink/stream` — `security-devices.integrations.queclink.stream` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController@stream` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php:824` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
