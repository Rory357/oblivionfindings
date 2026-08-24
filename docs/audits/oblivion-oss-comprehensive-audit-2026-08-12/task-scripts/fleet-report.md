# FLEET-REPORT: Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|fleet.reports.view`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/reports` (`fleet-assets.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|fleet.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|fleet.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/reports` (`fleet-assets.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/reports/by-house` (`fleet-assets.reports.by-house`, action `byHouse`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ReportController.php:591-707`.
3. Use `GET|HEAD fleet-assets/reports/export` (`fleet-assets.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/FleetAssets/ReportController.php:401-419`.
4. Use `GET|HEAD fleet-assets/reports/reimbursement` (`fleet-assets.reports.reimbursement`, action `reimbursement`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ReportController.php:421-424`.
5. Use `GET|HEAD fleet-assets/reports/reimbursement/data` (`fleet-assets.reports.reimbursement.data`, action `reimbursementData`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ReportController.php:426-472`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0807` at `app/Http/Controllers/FleetAssets/ReportController.php:24`; it is not runtime-observed.
- **information presented** is applicable only to `byHouse` / `ROUTE-0808` at `app/Http/Controllers/FleetAssets/ReportController.php:591`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0811` at `app/Http/Controllers/FleetAssets/ReportController.php:401`; it is not runtime-observed.
- **information presented** is applicable only to `reimbursement` / `ROUTE-0812` at `app/Http/Controllers/FleetAssets/ReportController.php:421`; it is not runtime-observed.
- **information presented** is applicable only to `reimbursementData` / `ROUTE-0813` at `app/Http/Controllers/FleetAssets/ReportController.php:426`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/reports/by-house.tsx`, `resources/js/pages/fleet-assets/reports/index.tsx`, `resources/js/pages/fleet-assets/reports/reimbursement.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/reports` — `fleet-assets.reports.index` — `App\Http\Controllers\FleetAssets\ReportController@index` — `app/Http/Controllers/FleetAssets/ReportController.php:24` — middleware `web, auth, permission:fleet.viewAny|fleet.reports.view`
- `GET|HEAD fleet-assets/reports/by-house` — `fleet-assets.reports.by-house` — `App\Http\Controllers\FleetAssets\ReportController@byHouse` — `app/Http/Controllers/FleetAssets/ReportController.php:591` — middleware `web, auth, permission:fleet.viewAny|fleet.reports.view`
- `GET|HEAD fleet-assets/reports/export` — `fleet-assets.reports.export` — `App\Http\Controllers\FleetAssets\ReportController@export` — `app/Http/Controllers/FleetAssets/ReportController.php:401` — middleware `web, auth, permission:fleet.viewAny|fleet.reports.view`
- `GET|HEAD fleet-assets/reports/reimbursement` — `fleet-assets.reports.reimbursement` — `App\Http\Controllers\FleetAssets\ReportController@reimbursement` — `app/Http/Controllers/FleetAssets/ReportController.php:421` — middleware `web, auth, permission:fleet.viewAny|fleet.reports.view`
- `GET|HEAD fleet-assets/reports/reimbursement/data` — `fleet-assets.reports.reimbursement.data` — `App\Http\Controllers\FleetAssets\ReportController@reimbursementData` — `app/Http/Controllers/FleetAssets/ReportController.php:426` — middleware `web, auth, permission:fleet.viewAny|fleet.reports.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/ReportController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/reports/by-house.tsx`, `resources/js/pages/fleet-assets/reports/index.tsx`, `resources/js/pages/fleet-assets/reports/reimbursement.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
