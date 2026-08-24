# HR-GOAL-CYCLE: Goal Cycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-GOAL-CYCLE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/goals/cycles` (`hr.goals.cycles.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/goals/cycles` (`hr.goals.cycles.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/goals/cycles` (`hr.goals.cycles.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/GoalCycleController.php:45-67`; `name`.
3. Invoke only the owning control for `PUT hr/goals/cycles/{cycle}` (`hr.goals.cycles.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/GoalCycleController.php:69-86`; `name`.
4. Invoke only the owning control for `POST hr/goals/cycles/{cycle}/close` (`hr.goals.cycles.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/GoalCycleController.php:88-123`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/goals/cycles/{cycle}/rollover` (`hr.goals.cycles.rollover`, action `rollover`). Source category: **mutation outcome source gap (rollover)**; controller `app/Http/Controllers/Hr/GoalCycleController.php:126-145`; `target_cycle_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1467` at `app/Http/Controllers/Hr/GoalCycleController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1468` at `app/Http/Controllers/Hr/GoalCycleController.php:45`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1469` at `app/Http/Controllers/Hr/GoalCycleController.php:69`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1470` at `app/Http/Controllers/Hr/GoalCycleController.php:88`; it is not runtime-observed.
- **mutation outcome source gap (rollover)** is applicable only to `rollover` / `ROUTE-1471` at `app/Http/Controllers/Hr/GoalCycleController.php:126`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1468` / `store`: fields `name`; success app/Http/Controllers/Hr/GoalCycleController.php:66 `return redirect()->back()->with('success', 'Cycle created.');`.
- `ROUTE-1469` / `update`: fields `name`; success app/Http/Controllers/Hr/GoalCycleController.php:85 `return redirect()->back()->with('success', 'Cycle updated.');`.
- `ROUTE-1471` / `rollover`: fields `target_cycle_id`; success app/Http/Controllers/Hr/GoalCycleController.php:144 `return redirect()->back()->with('success', "{$count} objective(s) rolled over to “{$target->name}”.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/GoalCycleController.php:60 `HrGoalCycle::create([`; app/Http/Controllers/Hr/GoalCycleController.php:83 `$cycle->update($data);`; app/Http/Controllers/Hr/GoalCycleController.php:94 `$cycle->update(['status' => 'closed']);`; app/Http/Controllers/Hr/GoalCycleController.php:106 `$goal->update([`; responses app/Http/Controllers/Hr/GoalCycleController.php:39 `return response()->json([`; app/Http/Controllers/Hr/GoalCycleController.php:66 `return redirect()->back()->with('success', 'Cycle created.');`; app/Http/Controllers/Hr/GoalCycleController.php:85 `return redirect()->back()->with('success', 'Cycle updated.');`; app/Http/Controllers/Hr/GoalCycleController.php:119 `return redirect()->back()->with(`; app/Http/Controllers/Hr/GoalCycleController.php:144 `return redirect()->back()->with('success', "{$count} objective(s) rolled over to “{$target->name}”.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/goals/cycles` — `hr.goals.cycles.index` — `App\Http\Controllers\Hr\GoalCycleController@index` — `app/Http/Controllers/Hr/GoalCycleController.php:20` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/goals/cycles` — `hr.goals.cycles.store` — `App\Http\Controllers\Hr\GoalCycleController@store` — `app/Http/Controllers/Hr/GoalCycleController.php:45` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/goals/cycles/{cycle}` — `hr.goals.cycles.update` — `App\Http\Controllers\Hr\GoalCycleController@update` — `app/Http/Controllers/Hr/GoalCycleController.php:69` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/goals/cycles/{cycle}/close` — `hr.goals.cycles.close` — `App\Http\Controllers\Hr\GoalCycleController@close` — `app/Http/Controllers/Hr/GoalCycleController.php:88` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/goals/cycles/{cycle}/rollover` — `hr.goals.cycles.rollover` — `App\Http\Controllers\Hr\GoalCycleController@rollover` — `app/Http/Controllers/Hr/GoalCycleController.php:126` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/GoalCycleController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
