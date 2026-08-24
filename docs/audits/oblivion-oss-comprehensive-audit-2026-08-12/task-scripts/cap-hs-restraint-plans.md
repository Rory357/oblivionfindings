# CAP-HS-RESTRAINT-PLANS: Restraint plan activation review and archive

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:restraints.create|restraints.manage`, `permission:restraints.manage`, `permission:restraints.review|restraints.manage`
- Owning module: Health and safety
- Legacy family: `HS-RESTRAINT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/restraints` (`health-safety.restraints.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:restraints.create|restraints.manage`, `permission:restraints.manage`, `permission:restraints.review|restraints.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:restraints.create|restraints.manage`, `permission:restraints.manage`, `permission:restraints.review|restraints.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/restraints` (`health-safety.restraints.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/restraints/plans` (`health-safety.restraints.plans.store`, action `storePlan`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:557-586`; `client_id`, `title`, `triggers`, `de_escalation_strategies`, `approved_interventions`, `prohibited_interventions`, `restrictive_practice_type`, `developed_by`, `developed_at`, `review_date`, `notes`.
3. Invoke only the owning control for `PUT health-safety/restraints/plans/{plan}` (`health-safety.restraints.plans.update`, action `updatePlan`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:588-610`; `title`, `triggers`, `de_escalation_strategies`, `approved_interventions`, `prohibited_interventions`, `restrictive_practice_type`, `developed_by`, `developed_at`, `review_date`, `notes`.
4. Invoke only the owning control for `POST health-safety/restraints/plans/{plan}/activate` (`health-safety.restraints.plans.activate`, action `activatePlan`). Source category: **mutation outcome source gap (activatePlan)**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:612-618`; no exact validation fields extracted.
5. Invoke only the owning control for `POST health-safety/restraints/plans/{plan}/archive` (`health-safety.restraints.plans.archive`, action `archivePlan`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:628-634`; no exact validation fields extracted.
6. Invoke only the owning control for `POST health-safety/restraints/plans/{plan}/review` (`health-safety.restraints.plans.review`, action `reviewPlan`). Source category: **mutation outcome source gap (reviewPlan)**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:636-673`; `outcome`, `next_review_date`, `resulting_status`, `notes`.
7. Invoke only the owning control for `POST health-safety/restraints/plans/{plan}/submit-review` (`health-safety.restraints.plans.submit-review`, action `submitPlanReview`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/RestraintController.php:620-626`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storePlan` / `ROUTE-1208` at `app/Http/Controllers/HealthSafety/RestraintController.php:557`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePlan` / `ROUTE-1209` at `app/Http/Controllers/HealthSafety/RestraintController.php:588`; it is not runtime-observed.
- **mutation outcome source gap (activatePlan)** is applicable only to `activatePlan` / `ROUTE-1210` at `app/Http/Controllers/HealthSafety/RestraintController.php:612`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archivePlan` / `ROUTE-1211` at `app/Http/Controllers/HealthSafety/RestraintController.php:628`; it is not runtime-observed.
- **mutation outcome source gap (reviewPlan)** is applicable only to `reviewPlan` / `ROUTE-1212` at `app/Http/Controllers/HealthSafety/RestraintController.php:636`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitPlanReview` / `ROUTE-1213` at `app/Http/Controllers/HealthSafety/RestraintController.php:620`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1208` / `storePlan`: fields `client_id`, `title`, `triggers`, `de_escalation_strategies`, `approved_interventions`, `prohibited_interventions`, `restrictive_practice_type`, `developed_by`, `developed_at`, `review_date`, `notes`; success app/Http/Controllers/HealthSafety/RestraintController.php:585 `->with('success', 'Behaviour support plan created.');`.
- `ROUTE-1209` / `updatePlan`: fields `title`, `triggers`, `de_escalation_strategies`, `approved_interventions`, `prohibited_interventions`, `restrictive_practice_type`, `developed_by`, `developed_at`, `review_date`, `notes`; success app/Http/Controllers/HealthSafety/RestraintController.php:609 `return back()->with('success', 'Behaviour support plan updated.');`.
- `ROUTE-1210` / `activatePlan`: success app/Http/Controllers/HealthSafety/RestraintController.php:617 `return back()->with('success', 'Plan activated.');`.
- `ROUTE-1211` / `archivePlan`: success app/Http/Controllers/HealthSafety/RestraintController.php:633 `return back()->with('success', 'Plan archived.');`.
- `ROUTE-1212` / `reviewPlan`: fields `outcome`, `next_review_date`, `resulting_status`, `notes`; success app/Http/Controllers/HealthSafety/RestraintController.php:672 `return back()->with('success', 'Plan review recorded.');`.
- `ROUTE-1213` / `submitPlanReview`: success app/Http/Controllers/HealthSafety/RestraintController.php:625 `return back()->with('success', 'Plan submitted for review.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/RestraintController.php:581 `$plan = BehaviourSupportPlan::create($validated);`; app/Http/Controllers/HealthSafety/RestraintController.php:607 `$plan->update($validated);`; app/Http/Controllers/HealthSafety/RestraintController.php:647 `BehaviourSupportPlanReview::create([`; app/Http/Controllers/HealthSafety/RestraintController.php:670 `$plan->update($changes);`; responses app/Http/Controllers/HealthSafety/RestraintController.php:583 `return redirect()`; app/Http/Controllers/HealthSafety/RestraintController.php:609 `return back()->with('success', 'Behaviour support plan updated.');`; app/Http/Controllers/HealthSafety/RestraintController.php:617 `return back()->with('success', 'Plan activated.');`; app/Http/Controllers/HealthSafety/RestraintController.php:633 `return back()->with('success', 'Plan archived.');`; app/Http/Controllers/HealthSafety/RestraintController.php:672 `return back()->with('success', 'Plan review recorded.');`; app/Http/Controllers/HealthSafety/RestraintController.php:625 `return back()->with('success', 'Plan submitted for review.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/restraints/plans` — `health-safety.restraints.plans.store` — `App\Http\Controllers\HealthSafety\RestraintController@storePlan` — `app/Http/Controllers/HealthSafety/RestraintController.php:557` — middleware `web, auth, permission:restraints.create|restraints.manage`
- `PUT health-safety/restraints/plans/{plan}` — `health-safety.restraints.plans.update` — `App\Http\Controllers\HealthSafety\RestraintController@updatePlan` — `app/Http/Controllers/HealthSafety/RestraintController.php:588` — middleware `web, auth, permission:restraints.manage`
- `POST health-safety/restraints/plans/{plan}/activate` — `health-safety.restraints.plans.activate` — `App\Http\Controllers\HealthSafety\RestraintController@activatePlan` — `app/Http/Controllers/HealthSafety/RestraintController.php:612` — middleware `web, auth, permission:restraints.manage`
- `POST health-safety/restraints/plans/{plan}/archive` — `health-safety.restraints.plans.archive` — `App\Http\Controllers\HealthSafety\RestraintController@archivePlan` — `app/Http/Controllers/HealthSafety/RestraintController.php:628` — middleware `web, auth, permission:restraints.manage`
- `POST health-safety/restraints/plans/{plan}/review` — `health-safety.restraints.plans.review` — `App\Http\Controllers\HealthSafety\RestraintController@reviewPlan` — `app/Http/Controllers/HealthSafety/RestraintController.php:636` — middleware `web, auth, permission:restraints.review|restraints.manage`
- `POST health-safety/restraints/plans/{plan}/submit-review` — `health-safety.restraints.plans.submit-review` — `App\Http\Controllers\HealthSafety\RestraintController@submitPlanReview` — `app/Http/Controllers/HealthSafety/RestraintController.php:620` — middleware `web, auth, permission:restraints.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/RestraintController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
