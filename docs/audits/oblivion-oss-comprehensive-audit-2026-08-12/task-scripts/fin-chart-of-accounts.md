# FIN-CHART-OF-ACCOUNTS: Chart Of Accounts

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-CHART-OF-ACCOUNTS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/accounts` (`finance.accounts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/accounts` (`finance.accounts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/accounts/{account}` (`finance.accounts.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:111-139`.
3. Use `GET|HEAD finance/accounts/{account}/edit` (`finance.accounts.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:141-174`.
4. Use `GET|HEAD finance/accounts/create` (`finance.accounts.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:54-80`.
5. Invoke only the owning control for `POST finance/accounts` (`finance.accounts.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:82-109`; `code`, `name`, `type`, `sub_type`, `parent_id`, `is_active`, `gst_applicable`, `description`, `default_tax_rate_id`, `funding_stream_id`.
6. Invoke only the owning control for `DELETE finance/accounts/{account}` (`finance.accounts.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:203-215`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT finance/accounts/{account}` (`finance.accounts.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:176-201`; `code`, `name`, `type`, `sub_type`, `parent_id`, `is_active`, `gst_applicable`, `description`, `default_tax_rate_id`, `funding_stream_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0448` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0449` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:82`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0450` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:203`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0451` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:111`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0452` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:176`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0453` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:141`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0454` at `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:54`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/accounts/Create.tsx`, `resources/js/pages/finance/accounts/Edit.tsx`, `resources/js/pages/finance/accounts/Index.tsx`, `resources/js/pages/finance/accounts/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0449` / `store`: fields `code`, `name`, `type`, `sub_type`, `parent_id`, `is_active`, `gst_applicable`, `description`, `default_tax_rate_id`, `funding_stream_id`; success app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:108 `->with('success', 'Account created successfully.');`; failure app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:104 `return back()->withErrors(['code' => $e->getMessage()]);`.
- `ROUTE-0450` / `destroy`: success app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:214 `->with('success', 'Account deleted successfully.');`; failure app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:210 `return back()->withErrors(['account' => $e->getMessage()]);`.
- `ROUTE-0452` / `update`: fields `code`, `name`, `type`, `sub_type`, `parent_id`, `is_active`, `gst_applicable`, `description`, `default_tax_rate_id`, `funding_stream_id`; success app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:200 `->with('success', 'Account updated successfully.');`; failure app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:196 `return back()->withErrors(['code' => $e->getMessage()]);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:104 `return back()->withErrors(['code' => $e->getMessage()]);`.
- `destroy`: app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:210 `return back()->withErrors(['account' => $e->getMessage()]);`.
- `update`: app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:196 `return back()->withErrors(['code' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:38 `return Inertia::render('finance/accounts/Index', [`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:104 `return back()->withErrors(['code' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:107 `return redirect()->route('finance.accounts.index')`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:210 `return back()->withErrors(['account' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:213 `return redirect()->route('finance.accounts.index')`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:120 `return Inertia::render('finance/accounts/Show', [`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:196 `return back()->withErrors(['code' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:199 `return redirect()->route('finance.accounts.index')`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:163 `return Inertia::render('finance/accounts/Edit', [`; app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:75 `return Inertia::render('finance/accounts/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/accounts` — `finance.accounts.index` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@index` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:19` — middleware `web, auth, permission:finance.ledger.view`
- `POST finance/accounts` — `finance.accounts.store` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@store` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:82` — middleware `web, auth, permission:finance.ledger.manage`
- `DELETE finance/accounts/{account}` — `finance.accounts.destroy` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@destroy` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:203` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/accounts/{account}` — `finance.accounts.show` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@show` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:111` — middleware `web, auth, permission:finance.ledger.view`
- `PUT finance/accounts/{account}` — `finance.accounts.update` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@update` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:176` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/accounts/{account}/edit` — `finance.accounts.edit` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@edit` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:141` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/accounts/create` — `finance.accounts.create` — `App\Domain\Finance\Http\Controllers\ChartOfAccountsController@create` — `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php:54` — middleware `web, auth, permission:finance.ledger.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/ChartOfAccountsController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/accounts/Create.tsx`, `resources/js/pages/finance/accounts/Edit.tsx`, `resources/js/pages/finance/accounts/Index.tsx`, `resources/js/pages/finance/accounts/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
