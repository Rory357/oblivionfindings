# CAP-GOV-RISK-REGISTER-ACCEPTANCE-CLOSURE: Risk acceptance and closure

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`
- Owning module: Governance
- Legacy family: `GOV-RISK-REGISTER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/risks` (`governance.risks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/risks` (`governance.risks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/risks/{risk}/accept` (`governance.risks.accept`, action `accept`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:161-192`; `justification`, `expiry_months`, `conditions`, `resolution_id`.
3. Invoke only the owning control for `POST governance/risks/{risk}/close` (`governance.risks.close`, action `close`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:194-206`; `rationale`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `accept` / `ROUTE-1005` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:161`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1006` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:194`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1005` / `accept`: fields `justification`, `expiry_months`, `conditions`, `resolution_id`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:191 `return redirect()->back()->with('success', 'Risk acceptance recorded.');`.
- `ROUTE-1006` / `close`: fields `rationale`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:205 `->with('success', 'Risk closed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/RiskRegisterController.php:189 `$risk->update(['status' => 'accepted']);`; responses app/Domain/Governance/Http/Controllers/RiskRegisterController.php:175 `return redirect()->back()->with('error', 'Above-appetite risks require a Board resolution for acceptance. Please create and link a resolution first.');`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:191 `return redirect()->back()->with('success', 'Risk acceptance recorded.');`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:204 `return redirect()->route('governance.risks.index')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/risks/{risk}/accept` — `governance.risks.accept` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@accept` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:161` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `POST governance/risks/{risk}/close` — `governance.risks.close` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@close` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:194` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
