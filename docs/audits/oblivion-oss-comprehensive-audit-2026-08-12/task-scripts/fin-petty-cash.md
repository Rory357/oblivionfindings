# FIN-PETTY-CASH: Petty Cash

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.petty_cash.view`, `permission:finance.petty_cash.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PETTY-CASH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/petty-cash` (`finance.petty-cash.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.petty_cash.view`, `permission:finance.petty_cash.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.petty_cash.view`, `permission:finance.petty_cash.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/petty-cash` (`finance.petty-cash.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/petty-cash/{fund}` (`finance.petty-cash.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PettyCashController.php:81-97`.
3. Invoke only the owning control for `POST finance/petty-cash` (`finance.petty-cash.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PettyCashController.php:63-79`; `name`, `float_amount`, `gl_account_id`, `custodian_user_id`.
4. Invoke only the owning control for `POST finance/petty-cash/{fund}/transaction` (`finance.petty-cash.transaction`, action `storeTransaction`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PettyCashController.php:99-118`; `transaction_date`, `type`, `amount`, `description`, `account_id`, `receipt_path`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0639` at `app/Domain/Finance/Http/Controllers/PettyCashController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0640` at `app/Domain/Finance/Http/Controllers/PettyCashController.php:63`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0641` at `app/Domain/Finance/Http/Controllers/PettyCashController.php:81`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeTransaction` / `ROUTE-0642` at `app/Domain/Finance/Http/Controllers/PettyCashController.php:99`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/petty-cash/Index.tsx`, `resources/js/pages/finance/petty-cash/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0640` / `store`: fields `name`, `float_amount`, `gl_account_id`, `custodian_user_id`; success app/Domain/Finance/Http/Controllers/PettyCashController.php:78 `->with('success', 'Petty cash fund created successfully.');`.
- `ROUTE-0642` / `storeTransaction`: fields `transaction_date`, `type`, `amount`, `description`, `account_id`, `receipt_path`; success app/Domain/Finance/Http/Controllers/PettyCashController.php:117 `->with('success', 'Transaction recorded successfully.');`; failure app/Domain/Finance/Http/Controllers/PettyCashController.php:113 `return back()->withErrors(['transaction' => $e->getMessage()]);`.

## Failure and recovery paths

- `storeTransaction`: app/Domain/Finance/Http/Controllers/PettyCashController.php:113 `return back()->withErrors(['transaction' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/PettyCashController.php:40 `return Inertia::render('finance/petty-cash/Index', [`; app/Domain/Finance/Http/Controllers/PettyCashController.php:77 `return redirect()->route('finance.petty-cash.show', $fund)`; app/Domain/Finance/Http/Controllers/PettyCashController.php:93 `return Inertia::render('finance/petty-cash/Show', [`; app/Domain/Finance/Http/Controllers/PettyCashController.php:113 `return back()->withErrors(['transaction' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PettyCashController.php:116 `return redirect()->route('finance.petty-cash.show', $fund)`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/petty-cash` — `finance.petty-cash.index` — `App\Domain\Finance\Http\Controllers\PettyCashController@index` — `app/Domain/Finance/Http/Controllers/PettyCashController.php:20` — middleware `web, auth, permission:finance.petty_cash.view`
- `POST finance/petty-cash` — `finance.petty-cash.store` — `App\Domain\Finance\Http\Controllers\PettyCashController@store` — `app/Domain/Finance/Http/Controllers/PettyCashController.php:63` — middleware `web, auth, permission:finance.petty_cash.manage`
- `GET|HEAD finance/petty-cash/{fund}` — `finance.petty-cash.show` — `App\Domain\Finance\Http\Controllers\PettyCashController@show` — `app/Domain/Finance/Http/Controllers/PettyCashController.php:81` — middleware `web, auth, permission:finance.petty_cash.view`
- `POST finance/petty-cash/{fund}/transaction` — `finance.petty-cash.transaction` — `App\Domain\Finance\Http\Controllers\PettyCashController@storeTransaction` — `app/Domain/Finance/Http/Controllers/PettyCashController.php:99` — middleware `web, auth, permission:finance.petty_cash.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PettyCashController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/petty-cash/Index.tsx`, `resources/js/pages/finance/petty-cash/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
