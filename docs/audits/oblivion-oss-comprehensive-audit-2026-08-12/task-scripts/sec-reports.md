# SEC-REPORTS: Reports

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.reports.view`
- Owning module: Security and devices
- Legacy family: `SEC-REPORTS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/reports` (`security-devices.reports`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/reports` (`security-devices.reports`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD security-devices/reports/devices.csv` (`security-devices.reports.devices`, action `exportDevices`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:59-109`.
3. Use `GET|HEAD security-devices/reports/events.csv` (`security-devices.reports.events`, action `exportEvents`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:111-150`.
4. Use `GET|HEAD security-devices/reports/maintenance.csv` (`security-devices.reports.maintenance`, action `exportMaintenance`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:152-194`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2607` at `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:31`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportDevices` / `ROUTE-2608` at `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:59`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportEvents` / `ROUTE-2609` at `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:111`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportMaintenance` / `ROUTE-2610` at `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:152`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/security-devices/reports.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD security-devices/reports` — `security-devices.reports` — `App\Domain\SecurityDevices\Http\Controllers\ReportsController@index` — `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:31` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.reports.view`
- `GET|HEAD security-devices/reports/devices.csv` — `security-devices.reports.devices` — `App\Domain\SecurityDevices\Http\Controllers\ReportsController@exportDevices` — `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:59` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.reports.view`
- `GET|HEAD security-devices/reports/events.csv` — `security-devices.reports.events` — `App\Domain\SecurityDevices\Http\Controllers\ReportsController@exportEvents` — `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:111` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.reports.view`
- `GET|HEAD security-devices/reports/maintenance.csv` — `security-devices.reports.maintenance` — `App\Domain\SecurityDevices\Http\Controllers\ReportsController@exportMaintenance` — `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php:152` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.reports.view`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/ReportsController.php`.
- Exact render/action page relationships: `resources/js/pages/security-devices/reports.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
