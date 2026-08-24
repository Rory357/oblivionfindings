# FIN-FISCAL-PERIOD: Fiscal Period

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-FISCAL-PERIOD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/fiscal-periods` (`finance.fiscal-periods.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/fiscal-periods` (`finance.fiscal-periods.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/fiscal-periods` (`finance.fiscal-periods.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:36-55`; `name`, `start_date`, `end_date`.
3. Invoke only the owning control for `PUT finance/fiscal-periods/{period}` (`finance.fiscal-periods.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:57-73`; `name`, `start_date`, `end_date`.
4. Invoke only the owning control for `POST finance/fiscal-periods/{period}/close` (`finance.fiscal-periods.close`, action `close`). Source category: **completed/closed/released**; controller `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:75-101`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0561` at `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0562` at `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:36`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0563` at `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:57`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-0564` at `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:75`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/fiscal-periods/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0562` / `store`: fields `name`, `start_date`, `end_date`; success app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:54 `->with('success', 'Fiscal period created successfully.');`.
- `ROUTE-0563` / `update`: fields `name`, `start_date`, `end_date`; success app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:72 `->with('success', 'Fiscal period updated successfully.');`; failure app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:60 `return back()->withErrors(['period' => 'Only open periods can be edited.']);`.
- `ROUTE-0564` / `close`: success app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:100 `->with('success', 'Fiscal period closed successfully.');`; failure app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:78 `return back()->withErrors(['period' => 'This period is already closed.']);`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:88 `return back()->withErrors([`.

## Failure and recovery paths

- `update`: app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:60 `return back()->withErrors(['period' => 'Only open periods can be edited.']);`.
- `close`: app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:78 `return back()->withErrors(['period' => 'This period is already closed.']);`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:88 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:44 `FinFiscalPeriod::create([`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:69 `$period->update($validated);`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:93 `$period->update([`; responses app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:31 `return Inertia::render('finance/fiscal-periods/Index', [`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:53 `return redirect()->route('finance.fiscal-periods.index')`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:60 `return back()->withErrors(['period' => 'Only open periods can be edited.']);`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:71 `return redirect()->route('finance.fiscal-periods.index')`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:78 `return back()->withErrors(['period' => 'This period is already closed.']);`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:88 `return back()->withErrors([`; app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:99 `return redirect()->route('finance.fiscal-periods.index')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/fiscal-periods` — `finance.fiscal-periods.index` — `App\Domain\Finance\Http\Controllers\FiscalPeriodController@index` — `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:13` — middleware `web, auth, permission:finance.admin`
- `POST finance/fiscal-periods` — `finance.fiscal-periods.store` — `App\Domain\Finance\Http\Controllers\FiscalPeriodController@store` — `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:36` — middleware `web, auth, permission:finance.admin`
- `PUT finance/fiscal-periods/{period}` — `finance.fiscal-periods.update` — `App\Domain\Finance\Http\Controllers\FiscalPeriodController@update` — `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:57` — middleware `web, auth, permission:finance.admin`
- `POST finance/fiscal-periods/{period}/close` — `finance.fiscal-periods.close` — `App\Domain\Finance\Http\Controllers\FiscalPeriodController@close` — `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php:75` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FiscalPeriodController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/fiscal-periods/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
