# CAP-SEC-UNIFI-DISCOVERY-SYNC: UniFi site and device synchronization

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
2. Invoke only the owning control for `POST security-devices/integrations/unifi/sync-devices` (`security-devices.integrations.unifi.sync-devices`, action `syncDevices`). Source category: **retried/replayed/reconciled**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:471-551`; `site_config_id`.
3. Invoke only the owning control for `POST security-devices/integrations/unifi/sync-sites` (`security-devices.integrations.unifi.sync-sites`, action `syncSites`). Source category: **retried/replayed/reconciled**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:337-410`; no exact validation fields extracted.

## Source-applicable states and transitions

- **retried/replayed/reconciled** is applicable only to `syncDevices` / `ROUTE-2600` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:471`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncSites` / `ROUTE-2601` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:337`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2600` / `syncDevices`: fields `site_config_id`.
- `ROUTE-2601` / `syncSites`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:395 `return redirect()->back()->with('success', 'UniFi sites synced successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:505 `$syncLog = IntegrationSyncLog::create([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:518 `$syncLog->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:533 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:353 `$syncLog = IntegrationSyncLog::create([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:382 `$secret->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:388 `$syncLog->update([`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:403 `$secret->update([`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:488 `return redirect()->back()->with('error', 'Map a UniFi site before syncing devices.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:498 `return redirect()->back()->with('error', 'Test and connect your UniFi API key before syncing devices.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:502 `return redirect()->back()->with('error', 'UniFi adapter is not registered.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:539 `return redirect()->back()->with('error', $result->error ?? 'UniFi device sync failed.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:542 `return redirect()->back()->with(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:549 `return redirect()->back()->with('error', 'Failed to sync UniFi devices: ' . $e->getMessage());`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:350 `return redirect()->back()->with('error', 'UniFi adapter is not registered.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:395 `return redirect()->back()->with('success', 'UniFi sites synced successfully.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:399 `return redirect()->back()->with('warning', 'No UniFi sites returned by API.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:408 `return redirect()->back()->with('error', 'Failed to sync UniFi sites: ' . $e->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/integrations/unifi/sync-devices` — `security-devices.integrations.unifi.sync-devices` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@syncDevices` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:471` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `POST security-devices/integrations/unifi/sync-sites` — `security-devices.integrations.unifi.sync-sites` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@syncSites` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:337` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
