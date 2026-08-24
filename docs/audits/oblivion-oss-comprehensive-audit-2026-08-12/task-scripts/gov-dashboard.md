# GOV-DASHBOARD: Dashboard

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.view`
- Owning module: Governance
- Legacy family: `GOV-DASHBOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/dashboard` (`governance.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/dashboard` (`governance.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/dashboard/data` (`governance.dashboard.data`, action `data`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/DashboardController.php:41-105`.
3. Use `GET|HEAD governance/dashboard/widget/{widget}` (`governance.dashboard.widget`, action `widget`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/DashboardController.php:107-126`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0911` at `app/Domain/Governance/Http/Controllers/DashboardController.php:22`; it is not runtime-observed.
- **information presented** is applicable only to `data` / `ROUTE-0913` at `app/Domain/Governance/Http/Controllers/DashboardController.php:41`; it is not runtime-observed.
- **information presented** is applicable only to `widget` / `ROUTE-0914` at `app/Domain/Governance/Http/Controllers/DashboardController.php:107`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Dashboard.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0913` / `data`: fields `period`.
- `ROUTE-0914` / `widget`: fields `period`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/dashboard` — `governance.dashboard` — `App\Domain\Governance\Http\Controllers\DashboardController@index` — `app/Domain/Governance/Http/Controllers/DashboardController.php:22` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/dashboard/data` — `governance.dashboard.data` — `App\Domain\Governance\Http\Controllers\DashboardController@data` — `app/Domain/Governance/Http/Controllers/DashboardController.php:41` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/dashboard/widget/{widget}` — `governance.dashboard.widget` — `App\Domain\Governance\Http\Controllers\DashboardController@widget` — `app/Domain/Governance/Http/Controllers/DashboardController.php:107` — middleware `web, auth, permission:governance.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/DashboardController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Dashboard.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
