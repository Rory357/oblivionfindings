# SITE-SITE-REPORTING: Site Reporting

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:reports.sites.view`, `permission:reports.sites.export`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-REPORTING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/reports` (`sites.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:reports.sites.view`, `permission:reports.sites.export`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:reports.sites.view`, `permission:reports.sites.export`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/reports` (`sites.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/reports/asset-condition` (`sites.reports.asset-condition`, action `assetConditionReport`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:338-381`.
3. Use `GET|HEAD sites/reports/checklist-trends` (`sites.reports.checklist-trends`, action `checklistTrends`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:305-336`.
4. Use `GET|HEAD sites/reports/export` (`sites.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Sites/SiteReportingController.php:153-185`.
5. Use `GET|HEAD sites/reports/facilities` (`sites.reports.facilities`, action `facilities`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:77-114`.
6. Use `GET|HEAD sites/reports/head-office` (`sites.reports.head-office`, action `headOffice`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:116-151`.
7. Use `GET|HEAD sites/reports/houses` (`sites.reports.houses`, action `houses`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:37-75`.
8. Use `GET|HEAD sites/reports/overdue-actions` (`sites.reports.overdue-actions`, action `overdueCorrectiveActions`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:277-303`.
9. Use `GET|HEAD sites/reports/site/{site}` (`sites.reports.site-detail`, action `perSiteDetail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:187-275`.
10. Use `GET|HEAD sites/reports/vendor-export` (`sites.reports.vendor-export`, action `vendorContactsExport`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteReportingController.php:383-426`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2904` at `app/Http/Controllers/Sites/SiteReportingController.php:21`; it is not runtime-observed.
- **information presented** is applicable only to `assetConditionReport` / `ROUTE-2905` at `app/Http/Controllers/Sites/SiteReportingController.php:338`; it is not runtime-observed.
- **information presented** is applicable only to `checklistTrends` / `ROUTE-2906` at `app/Http/Controllers/Sites/SiteReportingController.php:305`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2907` at `app/Http/Controllers/Sites/SiteReportingController.php:153`; it is not runtime-observed.
- **information presented** is applicable only to `facilities` / `ROUTE-2908` at `app/Http/Controllers/Sites/SiteReportingController.php:77`; it is not runtime-observed.
- **information presented** is applicable only to `headOffice` / `ROUTE-2909` at `app/Http/Controllers/Sites/SiteReportingController.php:116`; it is not runtime-observed.
- **information presented** is applicable only to `houses` / `ROUTE-2910` at `app/Http/Controllers/Sites/SiteReportingController.php:37`; it is not runtime-observed.
- **information presented** is applicable only to `overdueCorrectiveActions` / `ROUTE-2911` at `app/Http/Controllers/Sites/SiteReportingController.php:277`; it is not runtime-observed.
- **information presented** is applicable only to `perSiteDetail` / `ROUTE-2912` at `app/Http/Controllers/Sites/SiteReportingController.php:187`; it is not runtime-observed.
- **information presented** is applicable only to `vendorContactsExport` / `ROUTE-2913` at `app/Http/Controllers/Sites/SiteReportingController.php:383`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/reports/asset-condition.tsx`, `resources/js/pages/sites/reports/checklist-trends.tsx`, `resources/js/pages/sites/reports/facilities.tsx`, `resources/js/pages/sites/reports/head-office.tsx`, `resources/js/pages/sites/reports/houses.tsx`, `resources/js/pages/sites/reports/index.tsx`, `resources/js/pages/sites/reports/overdue-actions.tsx`, `resources/js/pages/sites/reports/site-detail.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/reports` — `sites.reports.index` — `App\Http\Controllers\Sites\SiteReportingController@index` — `app/Http/Controllers/Sites/SiteReportingController.php:21` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/asset-condition` — `sites.reports.asset-condition` — `App\Http\Controllers\Sites\SiteReportingController@assetConditionReport` — `app/Http/Controllers/Sites/SiteReportingController.php:338` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/checklist-trends` — `sites.reports.checklist-trends` — `App\Http\Controllers\Sites\SiteReportingController@checklistTrends` — `app/Http/Controllers/Sites/SiteReportingController.php:305` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/export` — `sites.reports.export` — `App\Http\Controllers\Sites\SiteReportingController@export` — `app/Http/Controllers/Sites/SiteReportingController.php:153` — middleware `web, auth, verified, permission:reports.sites.export`
- `GET|HEAD sites/reports/facilities` — `sites.reports.facilities` — `App\Http\Controllers\Sites\SiteReportingController@facilities` — `app/Http/Controllers/Sites/SiteReportingController.php:77` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/head-office` — `sites.reports.head-office` — `App\Http\Controllers\Sites\SiteReportingController@headOffice` — `app/Http/Controllers/Sites/SiteReportingController.php:116` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/houses` — `sites.reports.houses` — `App\Http\Controllers\Sites\SiteReportingController@houses` — `app/Http/Controllers/Sites/SiteReportingController.php:37` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/overdue-actions` — `sites.reports.overdue-actions` — `App\Http\Controllers\Sites\SiteReportingController@overdueCorrectiveActions` — `app/Http/Controllers/Sites/SiteReportingController.php:277` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/site/{site}` — `sites.reports.site-detail` — `App\Http\Controllers\Sites\SiteReportingController@perSiteDetail` — `app/Http/Controllers/Sites/SiteReportingController.php:187` — middleware `web, auth, verified, permission:reports.sites.view`
- `GET|HEAD sites/reports/vendor-export` — `sites.reports.vendor-export` — `App\Http\Controllers\Sites\SiteReportingController@vendorContactsExport` — `app/Http/Controllers/Sites/SiteReportingController.php:383` — middleware `web, auth, verified, permission:reports.sites.export`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteReportingController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/reports/asset-condition.tsx`, `resources/js/pages/sites/reports/checklist-trends.tsx`, `resources/js/pages/sites/reports/facilities.tsx`, `resources/js/pages/sites/reports/head-office.tsx`, `resources/js/pages/sites/reports/houses.tsx`, `resources/js/pages/sites/reports/index.tsx`, `resources/js/pages/sites/reports/overdue-actions.tsx`, `resources/js/pages/sites/reports/site-detail.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
