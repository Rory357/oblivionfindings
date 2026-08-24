# CAP-FIN-CONTROLLERS-BUDGET-APPROVAL-ADJUSTMENT: Budget proposal approval and adjustment decisions

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`, `permission:governance.budgets.approve`, `permission:governance.budgets.submit`
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

- Actor satisfying exact route middleware `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`, `permission:governance.budgets.approve`, `permission:governance.budgets.submit`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.budgets.view`, `permission:governance.budgets.create`, `permission:governance.budgets.approve`, `permission:governance.budgets.submit`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/budgets` (`governance.budgets.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/budgets/{budget}/adjust` (`governance.budgets.adjust`, action `requestAdjustment`). Source category: **mutation outcome source gap (requestAdjustment)**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:299-326`; `budget_line_item_id`.
3. Invoke only the owning control for `POST governance/budgets/{budget}/adjustments/{adjustment}/approve` (`governance.budgets.adjustments.approve`, action `approveAdjustment`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:328-335`; no exact validation fields extracted.
4. Invoke only the owning control for `POST governance/budgets/{budget}/adjustments/{adjustment}/reject` (`governance.budgets.adjustments.reject`, action `rejectAdjustment`). Source category: **rejected/returned**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:337-348`; `review_notes`.
5. Invoke only the owning control for `POST governance/budgets/{budget}/approve` (`governance.budgets.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:135-162`; no exact validation fields extracted.
6. Invoke only the owning control for `POST governance/budgets/{budget}/propose` (`governance.budgets.propose`, action `propose`). Source category: **mutation outcome source gap (propose)**; controller `app/Domain/Governance/Http/Controllers/BudgetController.php:120-133`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (requestAdjustment)** is applicable only to `requestAdjustment` / `ROUTE-0872` at `app/Domain/Governance/Http/Controllers/BudgetController.php:299`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approveAdjustment` / `ROUTE-0873` at `app/Domain/Governance/Http/Controllers/BudgetController.php:328`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectAdjustment` / `ROUTE-0874` at `app/Domain/Governance/Http/Controllers/BudgetController.php:337`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0878` at `app/Domain/Governance/Http/Controllers/BudgetController.php:135`; it is not runtime-observed.
- **mutation outcome source gap (propose)** is applicable only to `propose` / `ROUTE-0883` at `app/Domain/Governance/Http/Controllers/BudgetController.php:120`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0872` / `requestAdjustment`: fields `budget_line_item_id`; success app/Domain/Governance/Http/Controllers/BudgetController.php:323 `return redirect()->back()->with('success', $needsBoardApproval`.
- `ROUTE-0873` / `approveAdjustment`: success app/Domain/Governance/Http/Controllers/BudgetController.php:334 `return redirect()->back()->with('success', 'Adjustment approved and applied.');`.
- `ROUTE-0874` / `rejectAdjustment`: fields `review_notes`; success app/Domain/Governance/Http/Controllers/BudgetController.php:347 `return redirect()->back()->with('success', 'Adjustment rejected.');`.
- `ROUTE-0878` / `approve`: success app/Domain/Governance/Http/Controllers/BudgetController.php:161 `return redirect()->back()->with('success', 'Budget approved by board.');`.
- `ROUTE-0883` / `propose`: success app/Domain/Governance/Http/Controllers/BudgetController.php:132 `return redirect()->back()->with('success', 'Budget proposed to board.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BudgetController.php:312 `$adjustment = $budget->adjustments()->create([`; responses app/Domain/Governance/Http/Controllers/BudgetController.php:323 `return redirect()->back()->with('success', $needsBoardApproval`; app/Domain/Governance/Http/Controllers/BudgetController.php:334 `return redirect()->back()->with('success', 'Adjustment approved and applied.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:347 `return redirect()->back()->with('success', 'Adjustment rejected.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:142 `return redirect()->back()->with('error', 'No linked resolution found. The budget must be proposed first.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:146 `return redirect()->back()->with('error', 'The board resolution has not been carried yet. Voting must be completed first.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:150 `return redirect()->back()->with('error', 'Budget is already approved.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:161 `return redirect()->back()->with('success', 'Budget approved by board.');`; app/Domain/Governance/Http/Controllers/BudgetController.php:132 `return redirect()->back()->with('success', 'Budget proposed to board.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/budgets/{budget}/adjust` — `governance.budgets.adjust` — `App\Domain\Governance\Http\Controllers\BudgetController@requestAdjustment` — `app/Domain/Governance/Http/Controllers/BudgetController.php:299` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.create`
- `POST governance/budgets/{budget}/adjustments/{adjustment}/approve` — `governance.budgets.adjustments.approve` — `App\Domain\Governance\Http\Controllers\BudgetController@approveAdjustment` — `app/Domain/Governance/Http/Controllers/BudgetController.php:328` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.approve`
- `POST governance/budgets/{budget}/adjustments/{adjustment}/reject` — `governance.budgets.adjustments.reject` — `App\Domain\Governance\Http\Controllers\BudgetController@rejectAdjustment` — `app/Domain/Governance/Http/Controllers/BudgetController.php:337` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.approve`
- `POST governance/budgets/{budget}/approve` — `governance.budgets.approve` — `App\Domain\Governance\Http\Controllers\BudgetController@approve` — `app/Domain/Governance/Http/Controllers/BudgetController.php:135` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.approve`
- `POST governance/budgets/{budget}/propose` — `governance.budgets.propose` — `App\Domain\Governance\Http\Controllers\BudgetController@propose` — `app/Domain/Governance/Http/Controllers/BudgetController.php:120` — middleware `web, auth, permission:governance.budgets.view, permission:governance.budgets.submit`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BudgetController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
