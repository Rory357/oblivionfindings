# CAP-OPS-CARE-PLAN-GOAL-HURDLES: Care-plan goal hurdles and resolution

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:care_plans.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CARE-PLAN-GOAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/care-plans/{carePlan}/goals/{goal}` (`operations.care_plans.goals.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:care_plans.update`.
- Exact middleware atoms: `web`, `auth`, `permission:care_plans.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/care-plans/{carePlan}/goals/{goal}` (`operations.care_plans.goals.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/care-plans/{carePlan}/goals/{goal}/hurdles` (`operations.care_plans.goals.hurdles.store`, action `addHurdle`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:237-249`; `content`.
3. Invoke only the owning control for `PATCH operations/care-plans/{carePlan}/goals/{goal}/hurdles/{note}/resolve` (`operations.care_plans.goals.hurdles.resolve`, action `resolveHurdle`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:251-264`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `addHurdle` / `ROUTE-1916` at `app/Http/Controllers/Operations/CarePlanGoalController.php:237`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveHurdle` / `ROUTE-1917` at `app/Http/Controllers/Operations/CarePlanGoalController.php:251`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1916` / `addHurdle`: fields `content`; success app/Http/Controllers/Operations/CarePlanGoalController.php:248 `return redirect()->back()->with('success', 'Hurdle logged.');`.
- `ROUTE-1917` / `resolveHurdle`: success app/Http/Controllers/Operations/CarePlanGoalController.php:263 `return redirect()->back()->with('success', 'Hurdle resolved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CarePlanGoalController.php:261 `$note->update(['is_flagged' => false]);`; responses app/Http/Controllers/Operations/CarePlanGoalController.php:248 `return redirect()->back()->with('success', 'Hurdle logged.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:263 `return redirect()->back()->with('success', 'Hurdle resolved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/care-plans/{carePlan}/goals/{goal}/hurdles` — `operations.care_plans.goals.hurdles.store` — `App\Http\Controllers\Operations\CarePlanGoalController@addHurdle` — `app/Http/Controllers/Operations/CarePlanGoalController.php:237` — middleware `web, auth, permission:care_plans.update`
- `PATCH operations/care-plans/{carePlan}/goals/{goal}/hurdles/{note}/resolve` — `operations.care_plans.goals.hurdles.resolve` — `App\Http\Controllers\Operations\CarePlanGoalController@resolveHurdle` — `app/Http/Controllers/Operations/CarePlanGoalController.php:251` — middleware `web, auth, permission:care_plans.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CarePlanGoalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
