# FIN-COST-CENTRE: Cost Centre

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-COST-CENTRE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/cost-centres` (`finance.cost-centres.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/cost-centres` (`finance.cost-centres.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/cost-centres` (`finance.cost-centres.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/CostCentreController.php:34-63`; `code`, `name`, `type`, `site_id`, `parent_id`, `is_active`.
3. Invoke only the owning control for `DELETE finance/cost-centres/{costCentre}` (`finance.cost-centres.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/CostCentreController.php:92-98`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT finance/cost-centres/{costCentre}` (`finance.cost-centres.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/CostCentreController.php:65-90`; `code`, `name`, `type`, `site_id`, `parent_id`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0527` at `app/Domain/Finance/Http/Controllers/CostCentreController.php:12`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0528` at `app/Domain/Finance/Http/Controllers/CostCentreController.php:34`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0529` at `app/Domain/Finance/Http/Controllers/CostCentreController.php:92`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0530` at `app/Domain/Finance/Http/Controllers/CostCentreController.php:65`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/cost-centres/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0528` / `store`: fields `code`, `name`, `type`, `site_id`, `parent_id`, `is_active`; success app/Domain/Finance/Http/Controllers/CostCentreController.php:62 `->with('success', 'Cost centre created successfully.');`; failure app/Domain/Finance/Http/Controllers/CostCentreController.php:53 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`.
- `ROUTE-0529` / `destroy`: success app/Domain/Finance/Http/Controllers/CostCentreController.php:97 `->with('success', 'Cost centre deleted successfully.');`.
- `ROUTE-0530` / `update`: fields `code`, `name`, `type`, `site_id`, `parent_id`, `is_active`; success app/Domain/Finance/Http/Controllers/CostCentreController.php:89 `->with('success', 'Cost centre updated successfully.');`; failure app/Domain/Finance/Http/Controllers/CostCentreController.php:83 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/CostCentreController.php:53 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`.
- `update`: app/Domain/Finance/Http/Controllers/CostCentreController.php:83 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/CostCentreController.php:56 `FinCostCentre::create(array_merge($validated, [`; app/Domain/Finance/Http/Controllers/CostCentreController.php:94 `$costCentre->delete();`; app/Domain/Finance/Http/Controllers/CostCentreController.php:86 `$costCentre->update($validated);`; responses app/Domain/Finance/Http/Controllers/CostCentreController.php:29 `return Inertia::render('finance/cost-centres/Index', [`; app/Domain/Finance/Http/Controllers/CostCentreController.php:53 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`; app/Domain/Finance/Http/Controllers/CostCentreController.php:61 `return redirect()->route('finance.cost-centres.index')`; app/Domain/Finance/Http/Controllers/CostCentreController.php:96 `return redirect()->route('finance.cost-centres.index')`; app/Domain/Finance/Http/Controllers/CostCentreController.php:83 `return back()->withErrors(['code' => 'A cost centre with this code already exists.']);`; app/Domain/Finance/Http/Controllers/CostCentreController.php:88 `return redirect()->route('finance.cost-centres.index')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/cost-centres` — `finance.cost-centres.index` — `App\Domain\Finance\Http\Controllers\CostCentreController@index` — `app/Domain/Finance/Http/Controllers/CostCentreController.php:12` — middleware `web, auth, permission:finance.admin`
- `POST finance/cost-centres` — `finance.cost-centres.store` — `App\Domain\Finance\Http\Controllers\CostCentreController@store` — `app/Domain/Finance/Http/Controllers/CostCentreController.php:34` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/cost-centres/{costCentre}` — `finance.cost-centres.destroy` — `App\Domain\Finance\Http\Controllers\CostCentreController@destroy` — `app/Domain/Finance/Http/Controllers/CostCentreController.php:92` — middleware `web, auth, permission:finance.admin`
- `PUT finance/cost-centres/{costCentre}` — `finance.cost-centres.update` — `App\Domain\Finance\Http\Controllers\CostCentreController@update` — `app/Domain/Finance/Http/Controllers/CostCentreController.php:65` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/CostCentreController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/cost-centres/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
