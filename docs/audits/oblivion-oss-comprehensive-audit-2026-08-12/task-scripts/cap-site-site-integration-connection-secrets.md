# CAP-SITE-SITE-INTEGRATION-CONNECTION-SECRETS: Site integration connection configuration secrets and testing

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:integrations.manage_site_secrets`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:integrations.manage_site_secrets`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:siteHardware.view`, `permission:integrations.manage_site_secrets`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/integrations` (`sites.integrations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/integrations/{provider}` (`sites.integrations.configure`, action `configure`). Source category: **mutation outcome source gap (configure)**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:59-110`; `mapped_external_site_id`, `mapped_external_site_name`, `protect_host_id`, `protect_host_name`, `access_host_id`, `access_host_name`, `is_active`.
3. Invoke only the owning control for `PUT sites/{site}/integrations/{provider}/secrets/{capability}` (`sites.integrations.updateSecret`, action `updateSecret`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:413-439`; `base_url`, `secret`, `is_enabled`.
4. Invoke only the owning control for `POST sites/{site}/integrations/{provider}/test` (`sites.integrations.test`, action `testConnection`). Source category: **mutation outcome source gap (testConnection)**; controller `app/Http/Controllers/Sites/SiteIntegrationController.php:189-221`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2808` at `app/Http/Controllers/Sites/SiteIntegrationController.php:18`; it is not runtime-observed.
- **mutation outcome source gap (configure)** is applicable only to `configure` / `ROUTE-2809` at `app/Http/Controllers/Sites/SiteIntegrationController.php:59`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSecret` / `ROUTE-2812` at `app/Http/Controllers/Sites/SiteIntegrationController.php:413`; it is not runtime-observed.
- **mutation outcome source gap (testConnection)** is applicable only to `testConnection` / `ROUTE-2815` at `app/Http/Controllers/Sites/SiteIntegrationController.php:189`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2809` / `configure`: fields `mapped_external_site_id`, `mapped_external_site_name`, `protect_host_id`, `protect_host_name`, `access_host_id`, `access_host_name`, `is_active`; success app/Http/Controllers/Sites/SiteIntegrationController.php:109 `return redirect()->back()->with('success', 'Integration configured successfully.');`.
- `ROUTE-2812` / `updateSecret`: fields `base_url`, `secret`, `is_enabled`; success app/Http/Controllers/Sites/SiteIntegrationController.php:438 `return redirect()->back()->with('success', 'Site credential saved successfully.');`.
- `ROUTE-2815` / `testConnection`: success app/Http/Controllers/Sites/SiteIntegrationController.php:217 `return redirect()->back()->with('success', 'Connection test passed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteIntegrationController.php:94 `IntegrationSiteConfig::updateOrCreate(`; app/Http/Controllers/Sites/SiteIntegrationController.php:424 `IntegrationSiteSecret::updateOrCreate(`; app/Http/Controllers/Sites/SiteIntegrationController.php:208 `$tenantSecret->update([`; responses app/Http/Controllers/Sites/SiteIntegrationController.php:34 `return [`; app/Http/Controllers/Sites/SiteIntegrationController.php:52 `return response()->json([`; app/Http/Controllers/Sites/SiteIntegrationController.php:109 `return redirect()->back()->with('success', 'Integration configured successfully.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:438 `return redirect()->back()->with('success', 'Site credential saved successfully.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:199 `return redirect()->back()->with('error', 'No tenant credentials found for this integration.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:203 `return redirect()->back()->with('error', 'No adapter registered for this integration provider.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:217 `return redirect()->back()->with('success', 'Connection test passed.');`; app/Http/Controllers/Sites/SiteIntegrationController.php:220 `return redirect()->back()->with('error', 'Connection test failed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/integrations` — `sites.integrations.index` — `App\Http\Controllers\Sites\SiteIntegrationController@index` — `app/Http/Controllers/Sites/SiteIntegrationController.php:18` — middleware `web, auth, verified, permission:sites.viewAny, permission:siteHardware.view`
- `POST sites/{site}/integrations/{provider}` — `sites.integrations.configure` — `App\Http\Controllers\Sites\SiteIntegrationController@configure` — `app/Http/Controllers/Sites/SiteIntegrationController.php:59` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`
- `PUT sites/{site}/integrations/{provider}/secrets/{capability}` — `sites.integrations.updateSecret` — `App\Http\Controllers\Sites\SiteIntegrationController@updateSecret` — `app/Http/Controllers/Sites/SiteIntegrationController.php:413` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`
- `POST sites/{site}/integrations/{provider}/test` — `sites.integrations.test` — `App\Http\Controllers\Sites\SiteIntegrationController@testConnection` — `app/Http/Controllers/Sites/SiteIntegrationController.php:189` — middleware `web, auth, verified, permission:sites.viewAny, permission:integrations.manage_site_secrets`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteIntegrationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
