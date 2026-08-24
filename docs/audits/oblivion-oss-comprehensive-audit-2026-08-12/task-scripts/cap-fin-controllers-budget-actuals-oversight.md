# CAP-FIN-CONTROLLERS-BUDGET-ACTUALS-OVERSIGHT: Budget actuals recording and oversight

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`
- Owning module: Finance and funding
- Legacy family: `FIN-CONTROLLERS-BUDGET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/budgets` (`governance.budgets.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/budgets` (`governance.budgets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/budgets/{budget}` (`governance.budgets.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BudgetController.php:43-77`.
3. Invoke only the owning control for `POST governance/budgets/{budget}/record-actuals` (`governance.budgets.record-actuals`, action `recordActuals`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:352-369`; `actuals`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0868` at `app/Domain/Governance/Http/Controllers/BudgetController.php:17`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0870` at `app/Domain/Governance/Http/Controllers/BudgetController.php:43`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordActuals` / `ROUTE-0884` at `app/Domain/Governance/Http/Controllers/BudgetController.php:352`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Budgets/Index.tsx`, `resources/js/pages/Governance/Budgets/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0884` / `recordActuals`: fields `actuals`; success app/Domain/Governance/Http/Controllers/BudgetController.php:368 `return redirect()->back()->with('success', 'Actual spend recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BudgetController.php:365 `->update(['actual_amount' => $actual['actual_amount']]);`; responses app/Domain/Governance/Http/Controllers/BudgetController.php:28 `return $budget;`; app/Domain/Governance/Http/Controllers/BudgetController.php:31 `return Inertia::render('Governance/Budgets/Index', [`; app/Domain/Governance/Http/Controllers/BudgetController.php:70 `return Inertia::render('Governance/Budgets/Show', [`; app/Domain/Governance/Http/Controllers/BudgetController.php:368 `return redirect()->back()->with('success', 'Actual spend recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/budgets` — `governance.budgets.index` — `App\Domain\Governance\Http\Controllers\BudgetController@index` — `app/Domain/Governance/Http/Controllers/BudgetController.php:17` — middleware `web, auth, permission:governance.budgets.view`
- `GET|HEAD governance/budgets/{budget}` — `governance.budgets.show` — `App\Domain\Governance\Http\Controllers\BudgetController@show` — `app/Domain/Governance/Http/Controllers/BudgetController.php:43` — middleware `web, auth, permission:governance.budgets.view`
- `POST governance/budgets/{budget}/record-actuals` — `governance.budgets.record-actuals` — `App\Domain\Governance\Http\Controllers\BudgetController@recordActuals` — `app/Domain/Governance/Http/Controllers/BudgetController.php:352` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BudgetController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Budgets/Index.tsx`, `resources/js/pages/Governance/Budgets/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
