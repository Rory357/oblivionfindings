# FIN-BANK-ACCOUNT: Bank Account

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-BANK-ACCOUNT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/bank-accounts` (`finance.bank-accounts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/bank-accounts` (`finance.bank-accounts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/bank-accounts/{bankAccount}` (`finance.bank-accounts.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BankAccountController.php:70-144`.
3. Invoke only the owning control for `POST finance/bank-accounts` (`finance.bank-accounts.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BankAccountController.php:56-68`; FormRequest `app/Domain/Finance/Http/Requests/StoreBankAccountRequest.php:15`; `name`, `bank_name`, `account_number`, `account_type`, `gl_account_id`, `opening_balance`, `is_primary`, `is_active`.
4. Invoke only the owning control for `PUT finance/bank-accounts/{bankAccount}` (`finance.bank-accounts.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/BankAccountController.php:156-164`; FormRequest `app/Domain/Finance/Http/Requests/UpdateBankAccountRequest.php:15`; `name`, `bank_name`, `account_number`, `account_type`, `gl_account_id`, `is_primary`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0475` at `app/Domain/Finance/Http/Controllers/BankAccountController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0476` at `app/Domain/Finance/Http/Controllers/BankAccountController.php:56`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0477` at `app/Domain/Finance/Http/Controllers/BankAccountController.php:70`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0478` at `app/Domain/Finance/Http/Controllers/BankAccountController.php:156`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/bank-accounts/Index.tsx`, `resources/js/pages/finance/bank-accounts/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0476` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreBankAccountRequest.php:15`; fields `name`, `bank_name`, `account_number`, `account_type`, `gl_account_id`, `opening_balance`, `is_primary`, `is_active`; success app/Domain/Finance/Http/Controllers/BankAccountController.php:67 `->with('success', 'Bank account created successfully.');`.
- `ROUTE-0478` / `update`: FormRequest `app/Domain/Finance/Http/Requests/UpdateBankAccountRequest.php:15`; fields `name`, `bank_name`, `account_number`, `account_type`, `gl_account_id`, `is_primary`, `is_active`; success app/Domain/Finance/Http/Controllers/BankAccountController.php:163 `->with('success', 'Bank account updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/BankAccountController.php:64 `FinBankAccount::create($validated);`; app/Domain/Finance/Http/Controllers/BankAccountController.php:160 `$bankAccount->update($validated);`; responses app/Domain/Finance/Http/Controllers/BankAccountController.php:48 `return Inertia::render('finance/bank-accounts/Index', [`; app/Domain/Finance/Http/Controllers/BankAccountController.php:66 `return redirect()->route('finance.bank-accounts.index')`; app/Domain/Finance/Http/Controllers/BankAccountController.php:118 `return Inertia::render('finance/bank-accounts/Show', [`; app/Domain/Finance/Http/Controllers/BankAccountController.php:162 `return redirect()->route('finance.bank-accounts.show', $bankAccount)`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/bank-accounts` — `finance.bank-accounts.index` — `App\Domain\Finance\Http\Controllers\BankAccountController@index` — `app/Domain/Finance/Http/Controllers/BankAccountController.php:15` — middleware `web, auth, permission:finance.bank.view`
- `POST finance/bank-accounts` — `finance.bank-accounts.store` — `App\Domain\Finance\Http\Controllers\BankAccountController@store` — `app/Domain/Finance/Http/Controllers/BankAccountController.php:56` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/bank-accounts/{bankAccount}` — `finance.bank-accounts.show` — `App\Domain\Finance\Http\Controllers\BankAccountController@show` — `app/Domain/Finance/Http/Controllers/BankAccountController.php:70` — middleware `web, auth, permission:finance.bank.view`
- `PUT finance/bank-accounts/{bankAccount}` — `finance.bank-accounts.update` — `App\Domain\Finance\Http\Controllers\BankAccountController@update` — `app/Domain/Finance/Http/Controllers/BankAccountController.php:156` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BankAccountController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/bank-accounts/Index.tsx`, `resources/js/pages/finance/bank-accounts/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
