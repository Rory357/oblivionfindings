# CAP-RESP-RESPITE-RISK-PLAN-ACTIVATION-ACTIVATION-LIFECYCLE: Respite risk-plan activation review suspension and deactivation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-RISK-PLAN-ACTIVATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/risk-plan-activations` (`respite.risk-plan-activations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/risk-plan-activations` (`respite.risk-plan-activations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/risk-plan-activations/{riskPlanActivation}` (`respite.risk-plan-activations.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:106-124`.
3. Use `GET|HEAD respite/risk-plan-activations/create` (`respite.risk-plan-activations.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:40-62`.
4. Invoke only the owning control for `POST respite/risk-plan-activations` (`respite.risk-plan-activations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:64-104`; `stay_id`, `client_id`, `risk_assessment_id`, `plan_type`, `plan_name`, `plan_details`, `triggers`, `interventions`, `escalation_steps`.
5. Invoke only the owning control for `PUT respite/risk-plan-activations/{riskPlanActivation}` (`respite.risk-plan-activations.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:126-158`; `plan_name`, `plan_details`, `triggers`, `interventions`, `escalation_steps`.
6. Invoke only the owning control for `POST respite/risk-plan-activations/{riskPlanActivation}/activate` (`respite.risk-plan-activations.activate`, action `activate`). Source category: **mutation outcome source gap (activate)**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:181-207`; no exact validation fields extracted.
7. Invoke only the owning control for `POST respite/risk-plan-activations/{riskPlanActivation}/deactivate` (`respite.risk-plan-activations.deactivate`, action `deactivate`). Source category: **mutation outcome source gap (deactivate)**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:209-233`; `deactivation_reason`.
8. Invoke only the owning control for `POST respite/risk-plan-activations/{riskPlanActivation}/review` (`respite.risk-plan-activations.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:160-179`; `review_notes`.
9. Invoke only the owning control for `POST respite/risk-plan-activations/{riskPlanActivation}/suspend` (`respite.risk-plan-activations.suspend`, action `suspend`). Source category: **mutation outcome source gap (suspend)**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:235-254`; `suspension_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2431` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2432` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:64`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2433` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:106`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2434` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:126`; it is not runtime-observed.
- **mutation outcome source gap (activate)** is applicable only to `activate` / `ROUTE-2436` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:181`; it is not runtime-observed.
- **mutation outcome source gap (deactivate)** is applicable only to `deactivate` / `ROUTE-2437` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:209`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-2438` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:160`; it is not runtime-observed.
- **mutation outcome source gap (suspend)** is applicable only to `suspend` / `ROUTE-2439` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:235`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2440` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:40`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/risk-plan-activations/create.tsx`, `resources/js/pages/respite/risk-plan-activations/index.tsx`, `resources/js/pages/respite/risk-plan-activations/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2432` / `store`: fields `stay_id`, `client_id`, `risk_assessment_id`, `plan_type`, `plan_name`, `plan_details`, `triggers`, `interventions`, `escalation_steps`; success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:103 `->with('success', 'Risk plan activation created.');`.
- `ROUTE-2434` / `update`: fields `plan_name`, `plan_details`, `triggers`, `interventions`, `escalation_steps`; success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:157 `return back()->with('success', 'Risk plan activation updated.');`.
- `ROUTE-2436` / `activate`: success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:206 `return back()->with('success', 'Risk plan activated.');`.
- `ROUTE-2437` / `deactivate`: fields `deactivation_reason`; success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:232 `return back()->with('success', 'Risk plan deactivated.');`.
- `ROUTE-2438` / `review`: fields `review_notes`; success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:178 `return back()->with('success', 'Risk plan reviewed.');`.
- `ROUTE-2439` / `suspend`: fields `suspension_reason`; success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:253 `return back()->with('success', 'Risk plan suspended.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:82 `$activation = RespiteRiskPlanActivation::create($validated);`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:140 `$riskPlanActivation->update($validated);`; responses app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:32 `return Inertia::render('respite/risk-plan-activations/index', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:101 `return redirect()`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:120 `return Inertia::render('respite/risk-plan-activations/show', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:157 `return back()->with('success', 'Risk plan activation updated.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:184 `return back()->with('error', 'Risk plan is already active.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:206 `return back()->with('success', 'Risk plan activated.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:232 `return back()->with('success', 'Risk plan deactivated.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:178 `return back()->with('success', 'Risk plan reviewed.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:253 `return back()->with('success', 'Risk plan suspended.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:55 `return Inertia::render('respite/risk-plan-activations/create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:94 `event(new RespiteEvent('respite.risk_plan.created', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:152 `event(new RespiteEvent('respite.risk_plan.updated', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:199 `event(new RespiteEvent('respite.risk_plan.activated', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:227 `event(new RespiteEvent('respite.risk_plan.deactivated', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/risk-plan-activations` — `respite.risk-plan-activations.index` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@index` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:18` — middleware `web, auth, permission:respite.risk-plans.view`
- `POST respite/risk-plan-activations` — `respite.risk-plan-activations.store` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@store` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:64` — middleware `web, auth, permission:respite.risk-plans.manage`
- `GET|HEAD respite/risk-plan-activations/{riskPlanActivation}` — `respite.risk-plan-activations.show` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@show` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:106` — middleware `web, auth, permission:respite.risk-plans.view`
- `PUT respite/risk-plan-activations/{riskPlanActivation}` — `respite.risk-plan-activations.update` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@update` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:126` — middleware `web, auth, permission:respite.risk-plans.manage`
- `POST respite/risk-plan-activations/{riskPlanActivation}/activate` — `respite.risk-plan-activations.activate` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@activate` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:181` — middleware `web, auth, permission:respite.risk-plans.manage`
- `POST respite/risk-plan-activations/{riskPlanActivation}/deactivate` — `respite.risk-plan-activations.deactivate` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@deactivate` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:209` — middleware `web, auth, permission:respite.risk-plans.manage`
- `POST respite/risk-plan-activations/{riskPlanActivation}/review` — `respite.risk-plan-activations.review` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@review` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:160` — middleware `web, auth, permission:respite.risk-plans.manage`
- `POST respite/risk-plan-activations/{riskPlanActivation}/suspend` — `respite.risk-plan-activations.suspend` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@suspend` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:235` — middleware `web, auth, permission:respite.risk-plans.manage`
- `GET|HEAD respite/risk-plan-activations/create` — `respite.risk-plan-activations.create` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@create` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:40` — middleware `web, auth, permission:respite.risk-plans.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/risk-plan-activations/create.tsx`, `resources/js/pages/respite/risk-plan-activations/index.tsx`, `resources/js/pages/respite/risk-plan-activations/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
