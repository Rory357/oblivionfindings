# CAP-OPS-CARE-PLAN-GOAL-GOAL-STEPS: Care-plan goals steps and progress

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
2. Invoke only the owning control for `POST operations/care-plans/{carePlan}/goals` (`operations.care_plans.goals.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:14-66`; `title`.
3. Invoke only the owning control for `DELETE operations/care-plans/{carePlan}/goals/{goal}` (`operations.care_plans.goals.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:87-94`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/care-plans/{carePlan}/goals/{goal}` (`operations.care_plans.goals.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:68-85`; `title`.
5. Invoke only the owning control for `PATCH operations/care-plans/{carePlan}/goals/{goal}/progress` (`operations.care_plans.goals.progress`, action `updateProgress`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:150-180`; `progress_percentage`.
6. Invoke only the owning control for `POST operations/care-plans/{carePlan}/goals/{goal}/steps` (`operations.care_plans.goals.steps.store`, action `storeStep`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:182-201`; `title`.
7. Invoke only the owning control for `DELETE operations/care-plans/{carePlan}/goals/{goal}/steps/{step}` (`operations.care_plans.goals.steps.destroy`, action `destroyStep`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:226-235`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT operations/care-plans/{carePlan}/goals/{goal}/steps/{step}` (`operations.care_plans.goals.steps.update`, action `updateStep`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/CarePlanGoalController.php:203-224`; `title`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1912` at `app/Http/Controllers/Operations/CarePlanGoalController.php:14`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1913` at `app/Http/Controllers/Operations/CarePlanGoalController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1914` at `app/Http/Controllers/Operations/CarePlanGoalController.php:100`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1915` at `app/Http/Controllers/Operations/CarePlanGoalController.php:68`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProgress` / `ROUTE-1918` at `app/Http/Controllers/Operations/CarePlanGoalController.php:150`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeStep` / `ROUTE-1919` at `app/Http/Controllers/Operations/CarePlanGoalController.php:182`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyStep` / `ROUTE-1920` at `app/Http/Controllers/Operations/CarePlanGoalController.php:226`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStep` / `ROUTE-1921` at `app/Http/Controllers/Operations/CarePlanGoalController.php:203`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1912` / `store`: fields `title`; success app/Http/Controllers/Operations/CarePlanGoalController.php:65 `return redirect()->back()->with('success', 'Goal added.');`.
- `ROUTE-1913` / `destroy`: success app/Http/Controllers/Operations/CarePlanGoalController.php:93 `return redirect()->back()->with('success', 'Goal removed.');`.
- `ROUTE-1915` / `update`: fields `title`; success app/Http/Controllers/Operations/CarePlanGoalController.php:84 `return redirect()->back()->with('success', 'Goal updated.');`.
- `ROUTE-1918` / `updateProgress`: fields `progress_percentage`; success app/Http/Controllers/Operations/CarePlanGoalController.php:179 `return redirect()->back()->with('success', 'Progress updated.');`.
- `ROUTE-1919` / `storeStep`: fields `title`; success app/Http/Controllers/Operations/CarePlanGoalController.php:200 `return redirect()->back()->with('success', 'Sub-goal added.');`.
- `ROUTE-1920` / `destroyStep`: success app/Http/Controllers/Operations/CarePlanGoalController.php:234 `return redirect()->back()->with('success', 'Sub-goal removed.');`.
- `ROUTE-1921` / `updateStep`: fields `title`; success app/Http/Controllers/Operations/CarePlanGoalController.php:223 `return redirect()->back()->with('success', 'Sub-goal updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/CarePlanGoalController.php:37 `$goal = CarePlanGoal::create([`; app/Http/Controllers/Operations/CarePlanGoalController.php:56 `$goal->steps()->create([`; app/Http/Controllers/Operations/CarePlanGoalController.php:91 `$goal->delete();`; app/Http/Controllers/Operations/CarePlanGoalController.php:82 `$goal->update($data);`; app/Http/Controllers/Operations/CarePlanGoalController.php:163 `$goal->update(['status' => $data['status']]);`; app/Http/Controllers/Operations/CarePlanGoalController.php:168 `$goal->update([`; app/Http/Controllers/Operations/CarePlanGoalController.php:191 `$goal->steps()->create([`; app/Http/Controllers/Operations/CarePlanGoalController.php:231 `$step->delete();`; app/Http/Controllers/Operations/CarePlanGoalController.php:220 `$step->update($data);`; responses app/Http/Controllers/Operations/CarePlanGoalController.php:65 `return redirect()->back()->with('success', 'Goal added.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:93 `return redirect()->back()->with('success', 'Goal removed.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:114 `return response()->json([`; app/Http/Controllers/Operations/CarePlanGoalController.php:84 `return redirect()->back()->with('success', 'Goal updated.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:179 `return redirect()->back()->with('success', 'Progress updated.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:200 `return redirect()->back()->with('success', 'Sub-goal added.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:234 `return redirect()->back()->with('success', 'Sub-goal removed.');`; app/Http/Controllers/Operations/CarePlanGoalController.php:223 `return redirect()->back()->with('success', 'Sub-goal updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/care-plans/{carePlan}/goals` — `operations.care_plans.goals.store` — `App\Http\Controllers\Operations\CarePlanGoalController@store` — `app/Http/Controllers/Operations/CarePlanGoalController.php:14` — middleware `web, auth, permission:care_plans.update`
- `DELETE operations/care-plans/{carePlan}/goals/{goal}` — `operations.care_plans.goals.destroy` — `App\Http\Controllers\Operations\CarePlanGoalController@destroy` — `app/Http/Controllers/Operations/CarePlanGoalController.php:87` — middleware `web, auth, permission:care_plans.update`
- `GET|HEAD operations/care-plans/{carePlan}/goals/{goal}` — `operations.care_plans.goals.show` — `App\Http\Controllers\Operations\CarePlanGoalController@show` — `app/Http/Controllers/Operations/CarePlanGoalController.php:100` — middleware `web, auth, permission:care_plans.update`
- `PUT operations/care-plans/{carePlan}/goals/{goal}` — `operations.care_plans.goals.update` — `App\Http\Controllers\Operations\CarePlanGoalController@update` — `app/Http/Controllers/Operations/CarePlanGoalController.php:68` — middleware `web, auth, permission:care_plans.update`
- `PATCH operations/care-plans/{carePlan}/goals/{goal}/progress` — `operations.care_plans.goals.progress` — `App\Http\Controllers\Operations\CarePlanGoalController@updateProgress` — `app/Http/Controllers/Operations/CarePlanGoalController.php:150` — middleware `web, auth, permission:care_plans.update`
- `POST operations/care-plans/{carePlan}/goals/{goal}/steps` — `operations.care_plans.goals.steps.store` — `App\Http\Controllers\Operations\CarePlanGoalController@storeStep` — `app/Http/Controllers/Operations/CarePlanGoalController.php:182` — middleware `web, auth, permission:care_plans.update`
- `DELETE operations/care-plans/{carePlan}/goals/{goal}/steps/{step}` — `operations.care_plans.goals.steps.destroy` — `App\Http\Controllers\Operations\CarePlanGoalController@destroyStep` — `app/Http/Controllers/Operations/CarePlanGoalController.php:226` — middleware `web, auth, permission:care_plans.update`
- `PUT operations/care-plans/{carePlan}/goals/{goal}/steps/{step}` — `operations.care_plans.goals.steps.update` — `App\Http\Controllers\Operations\CarePlanGoalController@updateStep` — `app/Http/Controllers/Operations/CarePlanGoalController.php:203` — middleware `web, auth, permission:care_plans.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/CarePlanGoalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
