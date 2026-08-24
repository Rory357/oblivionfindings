# FIN-BANK-TRANSACTION: Bank Transaction

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-BANK-TRANSACTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/bank-transactions` (`finance.bank-transactions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/bank-transactions` (`finance.bank-transactions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/bank-transactions` (`finance.bank-transactions.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BankTransactionController.php:75-96`; `bank_account_id`.
3. Invoke only the owning control for `POST finance/bank-transactions/import` (`finance.bank-transactions.import`, action `import`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BankTransactionController.php:98-120`; `bank_account_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0494` at `app/Domain/Finance/Http/Controllers/BankTransactionController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0495` at `app/Domain/Finance/Http/Controllers/BankTransactionController.php:75`; it is not runtime-observed.
- **created/recorded** is applicable only to `import` / `ROUTE-0496` at `app/Domain/Finance/Http/Controllers/BankTransactionController.php:98`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/bank-transactions/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0495` / `store`: fields `bank_account_id`; success app/Domain/Finance/Http/Controllers/BankTransactionController.php:95 `->with('success', 'Transaction added successfully.');`.
- `ROUTE-0496` / `import`: fields `bank_account_id`; success app/Domain/Finance/Http/Controllers/BankTransactionController.php:119 `->with('success', "Imported {$result['imported']} transactions. {$result['skipped']} rows skipped.");`; failure app/Domain/Finance/Http/Controllers/BankTransactionController.php:115 `->withErrors(['file' => 'Failed to import transactions: ' . $e->getMessage()]);`.

## Failure and recovery paths

- `import`: app/Domain/Finance/Http/Controllers/BankTransactionController.php:115 `->withErrors(['file' => 'Failed to import transactions: ' . $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/BankTransactionController.php:92 `FinBankTransaction::create($validated);`; responses app/Domain/Finance/Http/Controllers/BankTransactionController.php:63 `return Inertia::render('finance/bank-transactions/Index', [`; app/Domain/Finance/Http/Controllers/BankTransactionController.php:94 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankTransactionController.php:114 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/BankTransactionController.php:118 `return redirect()->back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/bank-transactions` — `finance.bank-transactions.index` — `App\Domain\Finance\Http\Controllers\BankTransactionController@index` — `app/Domain/Finance/Http/Controllers/BankTransactionController.php:18` — middleware `web, auth, permission:finance.bank.view`
- `POST finance/bank-transactions` — `finance.bank-transactions.store` — `App\Domain\Finance\Http\Controllers\BankTransactionController@store` — `app/Domain/Finance/Http/Controllers/BankTransactionController.php:75` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/bank-transactions/import` — `finance.bank-transactions.import` — `App\Domain\Finance\Http\Controllers\BankTransactionController@import` — `app/Domain/Finance/Http/Controllers/BankTransactionController.php:98` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BankTransactionController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/bank-transactions/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
