# SITE-BUDGET-FORECAST-API: Budget Forecast Api

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-BUDGET-FORECAST-API`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/api/budgets` (`finance.api.budgets`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.dashboard`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/api/budgets` (`finance.api.budgets`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/api/forecast` (`finance.api.forecast`, action `organisationForecast`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:138-147`.
3. Use `GET|HEAD finance/api/sites/{site}/budget` (`finance.api.sites.budget`, action `siteBudget`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:49-75`.
4. Use `GET|HEAD finance/api/sites/{site}/forecast` (`finance.api.sites.forecast`, action `siteForecast`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:154-162`.
5. Use `GET|HEAD finance/api/sites/{site}/variance` (`finance.api.sites.variance`, action `siteVariance`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:101-108`.
6. Use `GET|HEAD finance/api/sites/{site}/variance/trend` (`finance.api.sites.variance.trend`, action `siteVarianceTrend`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:115-127`.
7. Use `GET|HEAD finance/api/variance` (`finance.api.variance`, action `organisationVariance`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:86-94`.

## Source-applicable states and transitions

- **information presented** is applicable only to `budgetOverview` / `ROUTE-0455` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:34`; it is not runtime-observed.
- **information presented** is applicable only to `organisationForecast` / `ROUTE-0458` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:138`; it is not runtime-observed.
- **information presented** is applicable only to `siteBudget` / `ROUTE-0463` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:49`; it is not runtime-observed.
- **information presented** is applicable only to `siteForecast` / `ROUTE-0465` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:154`; it is not runtime-observed.
- **information presented** is applicable only to `siteVariance` / `ROUTE-0466` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:101`; it is not runtime-observed.
- **information presented** is applicable only to `siteVarianceTrend` / `ROUTE-0467` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:115`; it is not runtime-observed.
- **information presented** is applicable only to `organisationVariance` / `ROUTE-0469` at `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:86`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/api/budgets` — `finance.api.budgets` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@budgetOverview` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:34` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/forecast` — `finance.api.forecast` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@organisationForecast` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:138` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/{site}/budget` — `finance.api.sites.budget` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@siteBudget` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:49` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/{site}/forecast` — `finance.api.sites.forecast` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@siteForecast` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:154` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/{site}/variance` — `finance.api.sites.variance` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@siteVariance` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:101` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/{site}/variance/trend` — `finance.api.sites.variance.trend` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@siteVarianceTrend` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:115` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/variance` — `finance.api.variance` — `App\Domain\Finance\Http\Controllers\BudgetForecastApiController@organisationVariance` — `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php:86` — middleware `web, auth, permission:finance.dashboard`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BudgetForecastApiController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
