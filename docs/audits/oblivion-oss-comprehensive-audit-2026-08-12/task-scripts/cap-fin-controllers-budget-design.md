# CAP-FIN-CONTROLLERS-BUDGET-DESIGN: Budget structure line items and allocations

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`
- Owning module: Finance and funding
- Legacy family: `FIN-CONTROLLERS-BUDGET`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/budgets/{budget}/edit` (`governance.budgets.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/budgets/{budget}/edit` (`governance.budgets.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/budgets/create` (`governance.budgets.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BudgetController.php:36-41`.
3. Invoke only the owning control for `POST governance/budgets` (`governance.budgets.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:79-102`; `fiscal_year`.
4. Invoke only the owning control for `PUT governance/budgets/{budget}` (`governance.budgets.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:104-118`; `fiscal_year`.
5. Invoke only the owning control for `POST governance/budgets/{budget}/allocations` (`governance.budgets.allocations.store`, action `storeAllocation`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:239-266`; `budget_line_item_id`.
6. Invoke only the owning control for `DELETE governance/budgets/{budget}/allocations/{allocation}` (`governance.budgets.allocations.destroy`, action `destroyAllocation`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:287-295`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT governance/budgets/{budget}/allocations/{allocation}` (`governance.budgets.allocations.update`, action `updateAllocation`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:268-285`; `site_id`.
8. Invoke only the owning control for `POST governance/budgets/{budget}/line-items` (`governance.budgets.line-items.store`, action `storeLineItem`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:176-200`; `category`.
9. Invoke only the owning control for `DELETE governance/budgets/{budget}/line-items/{lineItem}` (`governance.budgets.line-items.destroy`, action `destroyLineItem`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:225-235`; no exact validation fields extracted.
10. Invoke only the owning control for `PUT governance/budgets/{budget}/line-items/{lineItem}` (`governance.budgets.line-items.update`, action `updateLineItem`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:202-223`; `category`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0869` at `app/Domain/Governance/Http/Controllers/BudgetController.php:79`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0871` at `app/Domain/Governance/Http/Controllers/BudgetController.php:104`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAllocation` / `ROUTE-0875` at `app/Domain/Governance/Http/Controllers/BudgetController.php:239`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAllocation` / `ROUTE-0876` at `app/Domain/Governance/Http/Controllers/BudgetController.php:287`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAllocation` / `ROUTE-0877` at `app/Domain/Governance/Http/Controllers/BudgetController.php:268`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0879` at `app/Domain/Governance/Http/Controllers/BudgetController.php:164`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeLineItem` / `ROUTE-0880` at `app/Domain/Governance/Http/Controllers/BudgetController.php:176`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyLineItem` / `ROUTE-0881` at `app/Domain/Governance/Http/Controllers/BudgetController.php:225`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateLineItem` / `ROUTE-0882` at `app/Domain/Governance/Http/Controllers/BudgetController.php:202`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0885` at `app/Domain/Governance/Http/Controllers/BudgetController.php:36`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Budgets/Create.tsx`, `resources/js/pages/Governance/Budgets/Edit.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0869` / `store`: fields `fiscal_year`; success app/Domain/Governance/Http/Controllers/BudgetController.php:101 `->with('success', 'Budget created. Add line items to build your budget.');`.
- `ROUTE-0871` / `update`: fields `fiscal_year`; success app/Domain/Governance/Http/Controllers/BudgetController.php:117 `return redirect()->route('governance.budgets.show', $budget)->with('success', 'Budget updated.');`.
- `ROUTE-0875` / `storeAllocation`: fields `budget_line_item_id`; success app/Domain/Governance/Http/Controllers/BudgetController.php:265 `return redirect()->back()->with('success', 'Allocation added.');`.
- `ROUTE-0876` / `destroyAllocation`: success app/Domain/Governance/Http/Controllers/BudgetController.php:294 `return redirect()->back()->with('success', 'Allocation removed.');`.
- `ROUTE-0877` / `updateAllocation`: fields `site_id`; success app/Domain/Governance/Http/Controllers/BudgetController.php:284 `return redirect()->back()->with('success', 'Allocation updated.');`.
- `ROUTE-0880` / `storeLineItem`: fields `category`; success app/Domain/Governance/Http/Controllers/BudgetController.php:199 `return redirect()->back()->with('success', 'Line item added.');`.
- `ROUTE-0881` / `destroyLineItem`: success app/Domain/Governance/Http/Controllers/BudgetController.php:234 `return redirect()->back()->with('success', 'Line item removed.');`.
- `ROUTE-0882` / `updateLineItem`: fields `category`; success app/Domain/Governance/Http/Controllers/BudgetController.php:222 `return redirect()->back()->with('success', 'Line item updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BudgetController.php:98 `$budget = Budget::create($data);`; app/Domain/Governance/Http/Controllers/BudgetController.php:115 `$budget->update($data);`; app/Domain/Governance/Http/Controllers/BudgetController.php:257 `$allocation = BudgetAllocation::create($data);`; app/Domain/Governance/Http/Controllers/BudgetController.php:292 `$allocation->delete();`; app/Domain/Governance/Http/Controllers/BudgetController.php:282 `$allocation->update($data);`; app/Domain/Governance/Http/Controllers/BudgetController.php:194 `BudgetLineItem::create($data);`; app/Domain/Governance/Http/Controllers/BudgetController.php:229 `$lineItem->delete();`; app/Domain/Governance/Http/Controllers/BudgetController.php:217 `$lineItem->update($data);`; responses app/Domain/Governance/Http/Controllers/BudgetController.php:100 `return redirect()->route('governance.budgets.show', $budget)`; app/Domain/Governance/Http/Controllers/BudgetController.php:117 `return redirect()->route('governance.budgets.show', $budget)->with('success', 'Budget updated.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:265 `return redirect()->back()->with('success', 'Allocation added.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:294 `return redirect()->back()->with('success', 'Allocation removed.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:284 `return redirect()->back()->with('success', 'Allocation updated.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:169 `return Inertia::render('Governance/Budgets/Edit', [`; app/Domain/Governance/Http/Controllers/BudgetController.php:199 `return redirect()->back()->with('success', 'Line item added.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:234 `return redirect()->back()->with('success', 'Line item removed.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:222 `return redirect()->back()->with('success', 'Line item updated.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:40 `return Inertia::render('Governance/Budgets/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/budgets` — `governance.budgets.store` — `App\Domain\Governance\Http\Controllers\BudgetController@store` — `app/Domain/Governance/Http/Controllers/BudgetController.php:79` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `PUT governance/budgets/{budget}` — `governance.budgets.update` — `App\Domain\Governance\Http\Controllers\BudgetController@update` — `app/Domain/Governance/Http/Controllers/BudgetController.php:104` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `POST governance/budgets/{budget}/allocations` — `governance.budgets.allocations.store` — `App\Domain\Governance\Http\Controllers\BudgetController@storeAllocation` — `app/Domain/Governance/Http/Controllers/BudgetController.php:239` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `DELETE governance/budgets/{budget}/allocations/{allocation}` — `governance.budgets.allocations.destroy` — `App\Domain\Governance\Http\Controllers\BudgetController@destroyAllocation` — `app/Domain/Governance/Http/Controllers/BudgetController.php:287` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `PUT governance/budgets/{budget}/allocations/{allocation}` — `governance.budgets.allocations.update` — `App\Domain\Governance\Http\Controllers\BudgetController@updateAllocation` — `app/Domain/Governance/Http/Controllers/BudgetController.php:268` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `GET|HEAD governance/budgets/{budget}/edit` — `governance.budgets.edit` — `App\Domain\Governance\Http\Controllers\BudgetController@edit` — `app/Domain/Governance/Http/Controllers/BudgetController.php:164` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `POST governance/budgets/{budget}/line-items` — `governance.budgets.line-items.store` — `App\Domain\Governance\Http\Controllers\BudgetController@storeLineItem` — `app/Domain/Governance/Http/Controllers/BudgetController.php:176` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `DELETE governance/budgets/{budget}/line-items/{lineItem}` — `governance.budgets.line-items.destroy` — `App\Domain\Governance\Http\Controllers\BudgetController@destroyLineItem` — `app/Domain/Governance/Http/Controllers/BudgetController.php:225` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `PUT governance/budgets/{budget}/line-items/{lineItem}` — `governance.budgets.line-items.update` — `App\Domain\Governance\Http\Controllers\BudgetController@updateLineItem` — `app/Domain/Governance/Http/Controllers/BudgetController.php:202` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `GET|HEAD governance/budgets/create` — `governance.budgets.create` — `App\Domain\Governance\Http\Controllers\BudgetController@create` — `app/Domain/Governance/Http/Controllers/BudgetController.php:36` — middleware `web, auth, permission:governance.budgets.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BudgetController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Budgets/Create.tsx`, `resources/js/pages/Governance/Budgets/Edit.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
