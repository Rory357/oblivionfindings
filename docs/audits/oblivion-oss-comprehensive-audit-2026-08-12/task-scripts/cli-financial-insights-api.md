# CLI-FINANCIAL-INSIGHTS-API: Financial Insights Api

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`
- Owning module: Clients and supported people
- Legacy family: `CLI-FINANCIAL-INSIGHTS-API`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/api/clients/{client}/financial-summary` (`finance.api.clients.financial-summary`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.dashboard`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/api/clients/{client}/financial-summary` (`finance.api.clients.financial-summary`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/api/clients/{client}/ledger` (`finance.api.clients.ledger`, action `clientLedger`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:87-95`.
3. Use `GET|HEAD finance/api/insights` (`finance.api.insights`, action `insights`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:155-165`.
4. Use `GET|HEAD finance/api/kpis` (`finance.api.kpis`, action `kpis`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:106-114`.
5. Use `GET|HEAD finance/api/kpis/clients` (`finance.api.kpis.clients`, action `clientKpis`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:136-144`.
6. Use `GET|HEAD finance/api/kpis/sites` (`finance.api.kpis.sites`, action `siteKpis`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:121-129`.
7. Use `GET|HEAD finance/api/sites/{site}/financial-summary` (`finance.api.sites.financial-summary`, action `siteFinancialSummary`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:40-47`.
8. Use `GET|HEAD finance/api/sites/overview` (`finance.api.sites.overview`, action `sitesOverview`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:54-62`.

## Source-applicable states and transitions

- **information presented** is applicable only to `clientFinancialSummary` / `ROUTE-0456` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `clientLedger` / `ROUTE-0457` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `insights` / `ROUTE-0459` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:155`; it is not runtime-observed.
- **information presented** is applicable only to `kpis` / `ROUTE-0460` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:106`; it is not runtime-observed.
- **information presented** is applicable only to `clientKpis` / `ROUTE-0461` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:136`; it is not runtime-observed.
- **information presented** is applicable only to `siteKpis` / `ROUTE-0462` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:121`; it is not runtime-observed.
- **information presented** is applicable only to `siteFinancialSummary` / `ROUTE-0464` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:40`; it is not runtime-observed.
- **information presented** is applicable only to `sitesOverview` / `ROUTE-0468` at `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:54`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/api/clients/{client}/financial-summary` — `finance.api.clients.financial-summary` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@clientFinancialSummary` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:73` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/clients/{client}/ledger` — `finance.api.clients.ledger` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@clientLedger` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:87` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/insights` — `finance.api.insights` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@insights` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:155` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/kpis` — `finance.api.kpis` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@kpis` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:106` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/kpis/clients` — `finance.api.kpis.clients` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@clientKpis` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:136` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/kpis/sites` — `finance.api.kpis.sites` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@siteKpis` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:121` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/{site}/financial-summary` — `finance.api.sites.financial-summary` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@siteFinancialSummary` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:40` — middleware `web, auth, permission:finance.dashboard`
- `GET|HEAD finance/api/sites/overview` — `finance.api.sites.overview` — `App\Domain\Finance\Http\Controllers\FinancialInsightsApiController@sitesOverview` — `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php:54` — middleware `web, auth, permission:finance.dashboard`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FinancialInsightsApiController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
