# CAP-SEC-UNIFI-SITE-MAPPING: UniFi site mapping and removal

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
2. Invoke only the owning control for `POST security-devices/integrations/unifi/map-site` (`security-devices.integrations.unifi.map-site`, action `mapSite`). Source category: **mutation outcome source gap (mapSite)**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:415-447`; `site_id`.
3. Invoke only the owning control for `DELETE security-devices/integrations/unifi/map-site/{siteConfig}` (`security-devices.integrations.unifi.remove-mapping`, action `removeSiteMapping`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:452-466`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (mapSite)** is applicable only to `mapSite` / `ROUTE-2597` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:415`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeSiteMapping` / `ROUTE-2598` at `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:452`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2597` / `mapSite`: fields `site_id`; success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:446 `return redirect()->back()->with('success', 'Site mapping saved.');`.
- `ROUTE-2598` / `removeSiteMapping`: success app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:465 `return redirect()->back()->with('success', 'Site mapping removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:432 `IntegrationSiteConfig::updateOrCreate(`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:463 `$siteConfig->delete();`; responses app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:446 `return redirect()->back()->with('success', 'Site mapping saved.');`; app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:465 `return redirect()->back()->with('success', 'Site mapping removed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/integrations/unifi/map-site` — `security-devices.integrations.unifi.map-site` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@mapSite` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:415` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`
- `DELETE security-devices/integrations/unifi/map-site/{siteConfig}` — `security-devices.integrations.unifi.remove-mapping` — `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController@removeSiteMapping` — `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php:452` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.integrations.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
