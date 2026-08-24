# FIN-FINANCIAL-REPORT: Financial Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`
- Owning module: Finance and funding
- Legacy family: `FIN-FINANCIAL-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/reports/aged-payables` (`finance.reports.aged-payables`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/reports/aged-payables` (`finance.reports.aged-payables`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/reports/aged-receivables` (`finance.reports.aged-receivables`, action `agedReceivables`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:91-100`.
3. Use `GET|HEAD finance/reports/balance-sheet` (`finance.reports.balance-sheet`, action `balanceSheet`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:48-61`.
4. Use `GET|HEAD finance/reports/cash-flow` (`finance.reports.cash-flow`, action `cashFlow`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:63-78`.
5. Use `GET|HEAD finance/reports/funding-stream-summary` (`finance.reports.funding-stream-summary`, action `fundingStreamSummary`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:102-134`.
6. Use `GET|HEAD finance/reports/profit-loss` (`finance.reports.profit-loss`, action `profitAndLoss`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:31-46`.
7. Use `GET|HEAD finance/reports/trial-balance` (`finance.reports.trial-balance`, action `trialBalance`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FinancialReportController.php:16-29`.

## Source-applicable states and transitions

- **information presented** is applicable only to `agedPayables` / `ROUTE-0682` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:80`; it is not runtime-observed.
- **information presented** is applicable only to `agedReceivables` / `ROUTE-0683` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:91`; it is not runtime-observed.
- **information presented** is applicable only to `balanceSheet` / `ROUTE-0684` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:48`; it is not runtime-observed.
- **information presented** is applicable only to `cashFlow` / `ROUTE-0687` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:63`; it is not runtime-observed.
- **information presented** is applicable only to `fundingStreamSummary` / `ROUTE-0688` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:102`; it is not runtime-observed.
- **information presented** is applicable only to `profitAndLoss` / `ROUTE-0689` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:31`; it is not runtime-observed.
- **information presented** is applicable only to `trialBalance` / `ROUTE-0690` at `app/Domain/Finance/Http/Controllers/FinancialReportController.php:16`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/reports/AgedPayables.tsx`, `resources/js/pages/finance/reports/AgedReceivables.tsx`, `resources/js/pages/finance/reports/BalanceSheet.tsx`, `resources/js/pages/finance/reports/CashFlow.tsx`, `resources/js/pages/finance/reports/FundingStreamSummary.tsx`, `resources/js/pages/finance/reports/ProfitAndLoss.tsx`, `resources/js/pages/finance/reports/TrialBalance.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/reports/aged-payables` — `finance.reports.aged-payables` — `App\Domain\Finance\Http\Controllers\FinancialReportController@agedPayables` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:80` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/aged-receivables` — `finance.reports.aged-receivables` — `App\Domain\Finance\Http\Controllers\FinancialReportController@agedReceivables` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:91` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/balance-sheet` — `finance.reports.balance-sheet` — `App\Domain\Finance\Http\Controllers\FinancialReportController@balanceSheet` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:48` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/cash-flow` — `finance.reports.cash-flow` — `App\Domain\Finance\Http\Controllers\FinancialReportController@cashFlow` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:63` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/funding-stream-summary` — `finance.reports.funding-stream-summary` — `App\Domain\Finance\Http\Controllers\FinancialReportController@fundingStreamSummary` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:102` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/profit-loss` — `finance.reports.profit-loss` — `App\Domain\Finance\Http\Controllers\FinancialReportController@profitAndLoss` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:31` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/reports/trial-balance` — `finance.reports.trial-balance` — `App\Domain\Finance\Http\Controllers\FinancialReportController@trialBalance` — `app/Domain/Finance/Http/Controllers/FinancialReportController.php:16` — middleware `web, auth, permission:finance.reports.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FinancialReportController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/reports/AgedPayables.tsx`, `resources/js/pages/finance/reports/AgedReceivables.tsx`, `resources/js/pages/finance/reports/BalanceSheet.tsx`, `resources/js/pages/finance/reports/CashFlow.tsx`, `resources/js/pages/finance/reports/FundingStreamSummary.tsx`, `resources/js/pages/finance/reports/ProfitAndLoss.tsx`, `resources/js/pages/finance/reports/TrialBalance.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
