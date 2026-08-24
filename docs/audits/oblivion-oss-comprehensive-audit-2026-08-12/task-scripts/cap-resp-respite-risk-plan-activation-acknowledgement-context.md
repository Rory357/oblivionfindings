# CAP-RESP-RESPITE-RISK-PLAN-ACTIVATION-ACKNOWLEDGEMENT-CONTEXT: Risk-plan acknowledgement and client or stay context

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-RISK-PLAN-ACTIVATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/clients/{clientId}/risk-plan-activations` (`respite.clients.risk-plan-activations`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.risk-plans.view`, `permission:respite.risk-plans.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/clients/{clientId}/risk-plan-activations` (`respite.clients.risk-plan-activations`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/risk-plan-activations/needing-acknowledgment` (`respite.risk-plan-activations.needing-acknowledgment`, action `needingAcknowledgment`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:313-324`.
3. Use `GET|HEAD respite/stays/{stay}/risk-plan-activations` (`respite.stays.risk-plan-activations`, action `forStay`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:283-296`.
4. Invoke only the owning control for `POST respite/risk-plan-activations/{riskPlanActivation}/acknowledge` (`respite.risk-plan-activations.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:256-281`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `forClient` / `ROUTE-2371` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:298`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-2435` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:256`; it is not runtime-observed.
- **information presented** is applicable only to `needingAcknowledgment` / `ROUTE-2441` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:313`; it is not runtime-observed.
- **information presented** is applicable only to `forStay` / `ROUTE-2457` at `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:283`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/risk-plan-activations/for-client.tsx`, `resources/js/pages/respite/risk-plan-activations/for-stay.tsx`, `resources/js/pages/respite/risk-plan-activations/needing-acknowledgment.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2435` / `acknowledge`: success app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:280 `return back()->with('success', 'Risk plan acknowledged.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:306 `return Inertia::render('respite/risk-plan-activations/for-client', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:259 `return back()->with('error', 'You have already acknowledged this risk plan.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:280 `return back()->with('success', 'Risk plan acknowledged.');`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:321 `return Inertia::render('respite/risk-plan-activations/needing-acknowledgment', [`; app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:291 `return Inertia::render('respite/risk-plan-activations/for-stay', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:275 `event(new RespiteEvent('respite.risk_plan.acknowledged', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/clients/{clientId}/risk-plan-activations` — `respite.clients.risk-plan-activations` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@forClient` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:298` — middleware `web, auth, permission:respite.risk-plans.view`
- `POST respite/risk-plan-activations/{riskPlanActivation}/acknowledge` — `respite.risk-plan-activations.acknowledge` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@acknowledge` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:256` — middleware `web, auth, permission:respite.risk-plans.manage`
- `GET|HEAD respite/risk-plan-activations/needing-acknowledgment` — `respite.risk-plan-activations.needing-acknowledgment` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@needingAcknowledgment` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:313` — middleware `web, auth, permission:respite.risk-plans.view`
- `GET|HEAD respite/stays/{stay}/risk-plan-activations` — `respite.stays.risk-plan-activations` — `App\Http\Controllers\Respite\RespiteRiskPlanActivationController@forStay` — `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php:283` — middleware `web, auth, permission:respite.risk-plans.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteRiskPlanActivationController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/risk-plan-activations/for-client.tsx`, `resources/js/pages/respite/risk-plan-activations/for-stay.tsx`, `resources/js/pages/respite/risk-plan-activations/needing-acknowledgment.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
