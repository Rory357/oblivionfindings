# CAP-SITE-SITE-INTEGRATION-SYNC-OVERRIDES: Site integration synchronization events and overrides

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:integrations.manage_site_secrets`, `permission:siteHardware.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-INTEGRATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/integrations` (`sites.integrations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:integrations.manage_site_secrets`, `permission:siteHardware.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:integrations.manage_site_secrets`, `permission:siteHardware.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/integrations` (`sites.integrations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT sites/{site}/integrations/{provider}/overrides` (`sites.integrations.updateOverrides`, action `updateOverrides`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:441-458`; `overrides`.
3. Invoke only the owning control for `POST sites/{site}/integrations/{provider}/pull-events` (`sites.integrations.pullEvents`, action `pullEvents`). Source category: **mutation outcome source gap (pullEvents)**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:300-411`; no exact validation fields extracted.
4. Invoke only the owning control for `POST sites/{site}/integrations/{provider}/sync-devices` (`sites.integrations.syncDevices`, action `syncDevices`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:223-298`; no exact validation fields extracted.
5. Invoke only the owning control for `POST sites/{site}/integrations/{provider}/sync-sites` (`sites.integrations.syncSites`, action `syncSites`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:112-187`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `updateOverrides` / `ROUTE-2810` at `app/Http/Controllers/Sites/SiteIntegrationController.php:441`; it is not runtime-observed.
- **mutation outcome source gap (pullEvents)** is applicable only to `pullEvents` / `ROUTE-2811` at `app/Http/Controllers/Sites/SiteIntegrationController.php:300`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncDevices` / `ROUTE-2813` at `app/Http/Controllers/Sites/SiteIntegrationController.php:223`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncSites` / `ROUTE-2814` at `app/Http/Controllers/Sites/SiteIntegrationController.php:112`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2810` / `updateOverrides`: fields `overrides`; success app/Http/Controllers/Sites/SiteIntegrationController.php:457 `return redirect()->back()->with('success', 'Integration overrides updated successfully.');`.
- `ROUTE-2811` / `pullEvents`: success app/Http/Controllers/Sites/SiteIntegrationController.php:402 `return redirect()->back()->with('success', "Access events synced. Added {$created}, updated {$updated}.");`.
- `ROUTE-2814` / `syncSites`: success app/Http/Controllers/Sites/SiteIntegrationController.php:172 `return redirect()->back()->with('success', 'Integration sites synced successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteIntegrationController.php:453 `$config->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:309 `$siteConfig = IntegrationSiteConfig::firstOrCreate(`; app/Http/Controllers/Sites/SiteIntegrationController.php:366 `$model = TimelineEvent::updateOrCreate(`; app/Http/Controllers/Sites/SiteIntegrationController.php:397 `$accessSecret->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:404 `$accessSecret->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:254 `$syncLog = IntegrationSyncLog::create([`; app/Http/Controllers/Sites/SiteIntegrationController.php:266 `$syncLog->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:281 `$tenantSecret->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:129 `$syncLog = IntegrationSyncLog::create([`; app/Http/Controllers/Sites/SiteIntegrationController.php:159 `$tenantSecret->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:165 `$syncLog->update([`; app/Http/Controllers/Sites/SiteIntegrationController.php:180 `$tenantSecret->update([`; responses app/Http/Controllers/Sites/SiteIntegrationController.php:457 `return redirect()->back()->with('success', 'Integration overrides updated successfully.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:306 `return redirect()->back()->with('error', 'No adapter registered for this integration provider.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:328 `return redirect()->back()->with('error', 'Tenant credentials are not connected for this integration.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:339 `return redirect()->back()->with('error', 'Access API credentials are missing for this location.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:402 `return redirect()->back()->with('success', "Access events synced. Added {$created}, updated {$updated}.");`; app/Http/Controllers/Sites/SiteIntegrationController.php:409 `return redirect()->back()->with('error', 'Access event sync failed: ' . $e->getMessage());`; app/Http/Controllers/Sites/SiteIntegrationController.php:228 `return redirect()->back()->with('error', 'No adapter registered for this integration provider.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:241 `return redirect()->back()->with('error', 'Integration mapping is missing for this location.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:251 `return redirect()->back()->with('error', 'Tenant credentials are not connected for this integration.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:287 `return redirect()->back()->with('error', $result->error ?? 'Device sync failed.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:290 `return redirect()->back()->with(`; app/Http/Controllers/Sites/SiteIntegrationController.php:296 `return redirect()->back()->with('error', 'Device sync failed: ' . $e->getMessage());`; app/Http/Controllers/Sites/SiteIntegrationController.php:122 `return redirect()->back()->with('error', 'No tenant credentials found for this integration.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:126 `return redirect()->back()->with('error', 'No adapter registered for this integration provider.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:172 `return redirect()->back()->with('success', 'Integration sites synced successfully.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:176 `return redirect()->back()->with('warning', 'No sites returned by provider API.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:185 `return redirect()->back()->with('error', 'Failed to sync sites: ' . $e->getMessage());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PUT sites/{site}/integrations/{provider}/overrides` — `sites.integrations.updateOverrides` — `App\Http\Controllers\Sites\SiteIntegrationController@updateOverrides` — `app/Http/Controllers/Sites/SiteIntegrationController.php:441` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`
- `POST sites/{site}/integrations/{provider}/pull-events` — `sites.integrations.pullEvents` — `App\Http\Controllers\Sites\SiteIntegrationController@pullEvents` — `app/Http/Controllers/Sites/SiteIntegrationController.php:300` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`
- `POST sites/{site}/integrations/{provider}/sync-devices` — `sites.integrations.syncDevices` — `App\Http\Controllers\Sites\SiteIntegrationController@syncDevices` — `app/Http/Controllers/Sites/SiteIntegrationController.php:223` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.manage`
- `POST sites/{site}/integrations/{provider}/sync-sites` — `sites.integrations.syncSites` — `App\Http\Controllers\Sites\SiteIntegrationController@syncSites` — `app/Http/Controllers/Sites/SiteIntegrationController.php:112` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteIntegrationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
