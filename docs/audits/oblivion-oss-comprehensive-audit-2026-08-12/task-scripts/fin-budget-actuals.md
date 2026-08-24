# FIN-BUDGET-ACTUALS: Budget Actuals

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`
- Owning module: Finance and funding
- Legacy family: `FIN-BUDGET-ACTUALS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/reports/budget-vs-actuals` (`finance.reports.budget-vs-actuals`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.reports.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/reports/budget-vs-actuals` (`finance.reports.budget-vs-actuals`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/reports/budget-vs-actuals/sync` (`finance.reports.budget-vs-actuals.sync`, action `sync`). Source category: **retried/replayed/reconciled**; controller `app/Domain/Finance/Http/Controllers/BudgetActualsController.php:41-54`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0685` at `app/Domain/Finance/Http/Controllers/BudgetActualsController.php:17`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `sync` / `ROUTE-0686` at `app/Domain/Finance/Http/Controllers/BudgetActualsController.php:41`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/reports/BudgetVsActuals.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0686` / `sync`: success app/Domain/Finance/Http/Controllers/BudgetActualsController.php:47 `return redirect()->back()->with('success', sprintf(`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/BudgetActualsController.php:34 `return Inertia::render('finance/reports/BudgetVsActuals', [`; app/Domain/Finance/Http/Controllers/BudgetActualsController.php:47 `return redirect()->back()->with('success', sprintf(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/reports/budget-vs-actuals` — `finance.reports.budget-vs-actuals` — `App\Domain\Finance\Http\Controllers\BudgetActualsController@index` — `app/Domain/Finance/Http/Controllers/BudgetActualsController.php:17` — middleware `web, auth, permission:finance.reports.view`
- `POST finance/reports/budget-vs-actuals/sync` — `finance.reports.budget-vs-actuals.sync` — `App\Domain\Finance\Http\Controllers\BudgetActualsController@sync` — `app/Domain/Finance/Http/Controllers/BudgetActualsController.php:41` — middleware `web, auth, permission:finance.reports.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BudgetActualsController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/reports/BudgetVsActuals.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
