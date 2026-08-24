# FIN-BANK-RECONCILIATION: Bank Reconciliation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-BANK-RECONCILIATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/bank-reconciliation` (`finance.bank-reconciliation.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/bank-reconciliation` (`finance.bank-reconciliation.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/bank-reconciliation/{reconciliation}` (`finance.bank-reconciliation.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:93-185`.
3. Use `GET|HEAD finance/bank-reconciliation/create` (`finance.bank-reconciliation.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:54-69`.
4. Invoke only the owning control for `POST finance/bank-reconciliation` (`finance.bank-reconciliation.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:71-91`; `bank_account_id`.
5. Invoke only the owning control for `POST finance/bank-reconciliation/{reconciliation}/complete` (`finance.bank-reconciliation.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:228-241`; no exact validation fields extracted.
6. Invoke only the owning control for `POST finance/bank-reconciliation/{reconciliation}/match` (`finance.bank-reconciliation.match`, action `match`). Source category: **mutation outcome source gap (match)**; controller `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:187-206`; `bank_transaction_id`.
7. Invoke only the owning control for `POST finance/bank-reconciliation/{reconciliation}/unmatch` (`finance.bank-reconciliation.unmatch`, action `unmatch`). Source category: **mutation outcome source gap (unmatch)**; controller `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:208-226`; `line_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0487` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0488` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:71`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0489` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:93`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0490` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:228`; it is not runtime-observed.
- **mutation outcome source gap (match)** is applicable only to `match` / `ROUTE-0491` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:187`; it is not runtime-observed.
- **mutation outcome source gap (unmatch)** is applicable only to `unmatch` / `ROUTE-0492` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:208`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0493` at `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:54`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/bank-reconciliation/Create.tsx`, `resources/js/pages/finance/bank-reconciliation/Index.tsx`, `resources/js/pages/finance/bank-reconciliation/Reconcile.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0488` / `store`: fields `bank_account_id`; success app/Domain/Finance/Http/Controllers/BankReconciliationController.php:90 `->with('success', 'Reconciliation started.');`.
- `ROUTE-0490` / `complete`: success app/Domain/Finance/Http/Controllers/BankReconciliationController.php:240 `->with('success', 'Reconciliation completed successfully.');`; failure app/Domain/Finance/Http/Controllers/BankReconciliationController.php:236 `->withErrors(['reconciliation' => $e->getMessage()]);`.
- `ROUTE-0491` / `match`: fields `bank_transaction_id`; success app/Domain/Finance/Http/Controllers/BankReconciliationController.php:205 `->with('success', 'Transaction matched.');`.
- `ROUTE-0492` / `unmatch`: fields `line_id`; success app/Domain/Finance/Http/Controllers/BankReconciliationController.php:225 `->with('success', 'Transaction unmatched.');`; failure app/Domain/Finance/Http/Controllers/BankReconciliationController.php:219 `abort(403, 'Line does not belong to this reconciliation.');`.

## Failure and recovery paths

- `complete`: app/Domain/Finance/Http/Controllers/BankReconciliationController.php:236 `->withErrors(['reconciliation' => $e->getMessage()]);`.
- `unmatch`: app/Domain/Finance/Http/Controllers/BankReconciliationController.php:219 `abort(403, 'Line does not belong to this reconciliation.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/BankReconciliationController.php:44 `return Inertia::render('finance/bank-reconciliation/Index', [`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:89 `return redirect()->route('finance.bank-reconciliation.show', $reconciliation)`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:160 `return Inertia::render('finance/bank-reconciliation/Reconcile', [`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:235 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:239 `return redirect()->route('finance.bank-reconciliation.show', $reconciliation)`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:204 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:224 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankReconciliationController.php:65 `return Inertia::render('finance/bank-reconciliation/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/bank-reconciliation` — `finance.bank-reconciliation.index` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@index` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:20` — middleware `web, auth, permission:finance.bank.view`
- `POST finance/bank-reconciliation` — `finance.bank-reconciliation.store` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@store` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:71` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/bank-reconciliation/{reconciliation}` — `finance.bank-reconciliation.show` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@show` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:93` — middleware `web, auth, permission:finance.bank.view`
- `POST finance/bank-reconciliation/{reconciliation}/complete` — `finance.bank-reconciliation.complete` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@complete` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:228` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-reconciliation/{reconciliation}/match` — `finance.bank-reconciliation.match` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@match` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:187` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-reconciliation/{reconciliation}/unmatch` — `finance.bank-reconciliation.unmatch` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@unmatch` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:208` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/bank-reconciliation/create` — `finance.bank-reconciliation.create` — `App\Domain\Finance\Http\Controllers\BankReconciliationController@create` — `app/Domain/Finance/Http/Controllers/BankReconciliationController.php:54` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BankReconciliationController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/bank-reconciliation/Create.tsx`, `resources/js/pages/finance/bank-reconciliation/Index.tsx`, `resources/js/pages/finance/bank-reconciliation/Reconcile.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
