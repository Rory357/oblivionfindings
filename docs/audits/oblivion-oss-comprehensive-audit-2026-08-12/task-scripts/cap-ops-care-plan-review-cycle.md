# CAP-OPS-CARE-PLAN-REVIEW-CYCLE: Care plan review start and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:care_plans.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CARE-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/care-plans` (`operations.care_plans.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:care_plans.update`.
- Exact middleware atoms: `web`, `auth`, `permission:care_plans.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/care-plans` (`operations.care_plans.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/care-plans/{carePlan}/complete-review` (`operations.care_plans.complete_review`, action `completeReview`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Operations/CarePlanController.php:465-523`; `review_notes`.
3. Invoke only the owning control for `POST operations/care-plans/{carePlan}/start-review` (`operations.care_plans.start_review`, action `startReview`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanController.php:378-463`; no exact validation fields extracted.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `completeReview` / `ROUTE-1910` at `app/Http/Controllers/Operations/CarePlanController.php:465`; it is not runtime-observed.
- **created/recorded** is applicable only to `startReview` / `ROUTE-1925` at `app/Http/Controllers/Operations/CarePlanController.php:378`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1910` / `completeReview`: fields `review_notes`; success app/Http/Controllers/Operations/CarePlanController.php:522 `->with('success', 'Review completed. Plan is now active.');`; failure app/Http/Controllers/Operations/CarePlanController.php:478 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:483 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:488 `throw ValidationException::withMessages([`.
- `ROUTE-1925` / `startReview`: success app/Http/Controllers/Operations/CarePlanController.php:462 `->with('success', 'Review started. Update the plan and complete the review when ready.');`; failure app/Http/Controllers/Operations/CarePlanController.php:392 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `completeReview`: app/Http/Controllers/Operations/CarePlanController.php:478 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:483 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/CarePlanController.php:488 `throw ValidationException::withMessages([`.
- `startReview`: app/Http/Controllers/Operations/CarePlanController.php:392 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CarePlanController.php:503 `->update(['status' => 'archived']);`; app/Http/Controllers/Operations/CarePlanController.php:512 `$locked->update([`; app/Http/Controllers/Operations/CarePlanController.php:435 `$newVersion->save();`; app/Http/Controllers/Operations/CarePlanController.php:441 `$newGoal->save();`; app/Http/Controllers/Operations/CarePlanController.php:447 `$newStep->save();`; responses app/Http/Controllers/Operations/CarePlanController.php:521 `return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")`; app/Http/Controllers/Operations/CarePlanController.php:407 `return false;`; app/Http/Controllers/Operations/CarePlanController.php:451 `return true;`; app/Http/Controllers/Operations/CarePlanController.php:455 `return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")`; app/Http/Controllers/Operations/CarePlanController.php:461 `return redirect("/operations/clients/{$carePlan->client_id}?tab=care_plans")`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/care-plans/{carePlan}/complete-review` — `operations.care_plans.complete_review` — `App\Http\Controllers\Operations\CarePlanController@completeReview` — `app/Http/Controllers/Operations/CarePlanController.php:465` — middleware `web, auth, permission:care_plans.update`
- `POST operations/care-plans/{carePlan}/start-review` — `operations.care_plans.start_review` — `App\Http\Controllers\Operations\CarePlanController@startReview` — `app/Http/Controllers/Operations/CarePlanController.php:378` — middleware `web, auth, permission:care_plans.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CarePlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
