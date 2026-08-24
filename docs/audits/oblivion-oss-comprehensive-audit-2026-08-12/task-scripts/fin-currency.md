# FIN-CURRENCY: Currency

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-CURRENCY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/currencies` (`finance.currencies.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/currencies` (`finance.currencies.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/currencies/create` (`finance.currencies.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/CurrencyController.php:12-15`.
3. Invoke only the owning control for `POST finance/currencies` (`finance.currencies.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/CurrencyController.php:42-81`; `code`, `name`, `symbol`, `decimal_places`, `exchange_rate`, `is_base`, `is_active`.
4. Invoke only the owning control for `DELETE finance/currencies/{currency}` (`finance.currencies.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/CurrencyController.php:126-136`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT|PATCH finance/currencies/{currency}` (`finance.currencies.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/CurrencyController.php:83-124`; `code`, `name`, `symbol`, `decimal_places`, `exchange_rate`, `is_base`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0536` at `app/Domain/Finance/Http/Controllers/CurrencyController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0537` at `app/Domain/Finance/Http/Controllers/CurrencyController.php:42`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0538` at `app/Domain/Finance/Http/Controllers/CurrencyController.php:126`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0539` at `app/Domain/Finance/Http/Controllers/CurrencyController.php:83`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0540` at `app/Domain/Finance/Http/Controllers/CurrencyController.php:12`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/currencies/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0537` / `store`: fields `code`, `name`, `symbol`, `decimal_places`, `exchange_rate`, `is_base`, `is_active`; success app/Domain/Finance/Http/Controllers/CurrencyController.php:80 `->with('success', 'Currency created successfully.');`; failure app/Domain/Finance/Http/Controllers/CurrencyController.php:63 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`.
- `ROUTE-0538` / `destroy`: success app/Domain/Finance/Http/Controllers/CurrencyController.php:135 `->with('success', 'Currency deleted successfully.');`; failure app/Domain/Finance/Http/Controllers/CurrencyController.php:129 `return back()->withErrors(['code' => 'The base currency cannot be deleted.']);`.
- `ROUTE-0539` / `update`: fields `code`, `name`, `symbol`, `decimal_places`, `exchange_rate`, `is_base`, `is_active`; success app/Domain/Finance/Http/Controllers/CurrencyController.php:123 `->with('success', 'Currency updated successfully.');`; failure app/Domain/Finance/Http/Controllers/CurrencyController.php:104 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/CurrencyController.php:63 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`.
- `destroy`: app/Domain/Finance/Http/Controllers/CurrencyController.php:129 `return back()->withErrors(['code' => 'The base currency cannot be deleted.']);`.
- `update`: app/Domain/Finance/Http/Controllers/CurrencyController.php:104 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/CurrencyController.php:70 `->update(['is_base' => false]);`; app/Domain/Finance/Http/Controllers/CurrencyController.php:73 `FinCurrency::create(array_merge($validated, [`; app/Domain/Finance/Http/Controllers/CurrencyController.php:132 `$currency->delete();`; app/Domain/Finance/Http/Controllers/CurrencyController.php:112 `->update(['is_base' => false]);`; app/Domain/Finance/Http/Controllers/CurrencyController.php:118 `$currency->update(array_merge($validated, [`; responses app/Domain/Finance/Http/Controllers/CurrencyController.php:37 `return Inertia::render('finance/currencies/Index', [`; app/Domain/Finance/Http/Controllers/CurrencyController.php:63 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`; app/Domain/Finance/Http/Controllers/CurrencyController.php:79 `return redirect()->route('finance.currencies.index')`; app/Domain/Finance/Http/Controllers/CurrencyController.php:129 `return back()->withErrors(['code' => 'The base currency cannot be deleted.']);`; app/Domain/Finance/Http/Controllers/CurrencyController.php:134 `return redirect()->route('finance.currencies.index')`; app/Domain/Finance/Http/Controllers/CurrencyController.php:104 `return back()->withErrors(['code' => 'A currency with this code already exists.']);`; app/Domain/Finance/Http/Controllers/CurrencyController.php:122 `return redirect()->route('finance.currencies.index')`; app/Domain/Finance/Http/Controllers/CurrencyController.php:14 `return redirect()->route('finance.currencies.index');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/currencies` — `finance.currencies.index` — `App\Domain\Finance\Http\Controllers\CurrencyController@index` — `app/Domain/Finance/Http/Controllers/CurrencyController.php:17` — middleware `web, auth, permission:finance.admin`
- `POST finance/currencies` — `finance.currencies.store` — `App\Domain\Finance\Http\Controllers\CurrencyController@store` — `app/Domain/Finance/Http/Controllers/CurrencyController.php:42` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/currencies/{currency}` — `finance.currencies.destroy` — `App\Domain\Finance\Http\Controllers\CurrencyController@destroy` — `app/Domain/Finance/Http/Controllers/CurrencyController.php:126` — middleware `web, auth, permission:finance.admin`
- `PUT|PATCH finance/currencies/{currency}` — `finance.currencies.update` — `App\Domain\Finance\Http\Controllers\CurrencyController@update` — `app/Domain/Finance/Http/Controllers/CurrencyController.php:83` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/currencies/create` — `finance.currencies.create` — `App\Domain\Finance\Http\Controllers\CurrencyController@create` — `app/Domain/Finance/Http/Controllers/CurrencyController.php:12` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/CurrencyController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/currencies/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
