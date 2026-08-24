# HR-DEVELOPMENT-GOAL: Development Goal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-DEVELOPMENT-GOAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/goals/development` (`hr.development.goals.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/goals/development` (`hr.development.goals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/goals/development` (`hr.development.goals.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/DevelopmentGoalController.php:29-85`; `employee_user_id`.
3. Invoke only the owning control for `DELETE hr/goals/development/{goal}` (`hr.development.goals.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/DevelopmentGoalController.php:148-160`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/goals/development/{goal}` (`hr.development.goals.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/DevelopmentGoalController.php:87-146`; `title`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1472` at `app/Http/Controllers/Hr/DevelopmentGoalController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1473` at `app/Http/Controllers/Hr/DevelopmentGoalController.php:29`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1474` at `app/Http/Controllers/Hr/DevelopmentGoalController.php:148`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1475` at `app/Http/Controllers/Hr/DevelopmentGoalController.php:87`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1473` / `store`: fields `employee_user_id`; success app/Http/Controllers/Hr/DevelopmentGoalController.php:84 `return redirect()->back()->with('success', 'Development goal created.');`.
- `ROUTE-1474` / `destroy`: success app/Http/Controllers/Hr/DevelopmentGoalController.php:159 `return redirect()->back()->with('success', 'Development goal deleted.');`.
- `ROUTE-1475` / `update`: fields `title`; success app/Http/Controllers/Hr/DevelopmentGoalController.php:145 `return redirect()->back()->with('success', 'Development goal updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/DevelopmentGoalController.php:69 `$goal = HrDevelopmentGoal::create([`; app/Http/Controllers/Hr/DevelopmentGoalController.php:157 `$goal->delete();`; app/Http/Controllers/Hr/DevelopmentGoalController.php:143 `$goal->update($payload);`; responses app/Http/Controllers/Hr/DevelopmentGoalController.php:26 `return redirect('/hr/goals?tab=development');`; app/Http/Controllers/Hr/DevelopmentGoalController.php:84 `return redirect()->back()->with('success', 'Development goal created.');`; app/Http/Controllers/Hr/DevelopmentGoalController.php:159 `return redirect()->back()->with('success', 'Development goal deleted.');`; app/Http/Controllers/Hr/DevelopmentGoalController.php:145 `return redirect()->back()->with('success', 'Development goal updated.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/DevelopmentGoalController.php:81 `$employee->notify(new DevelopmentGoalAssignedNotification($goal));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD hr/goals/development` — `hr.development.goals.index` — `App\Http\Controllers\Hr\DevelopmentGoalController@index` — `app/Http/Controllers/Hr/DevelopmentGoalController.php:21` — middleware `web, auth`
- `POST hr/goals/development` — `hr.development.goals.store` — `App\Http\Controllers\Hr\DevelopmentGoalController@store` — `app/Http/Controllers/Hr/DevelopmentGoalController.php:29` — middleware `web, auth, permission:hr.performance.manage`
- `DELETE hr/goals/development/{goal}` — `hr.development.goals.destroy` — `App\Http\Controllers\Hr\DevelopmentGoalController@destroy` — `app/Http/Controllers/Hr/DevelopmentGoalController.php:148` — middleware `web, auth, permission:hr.performance.manage`
- `PUT hr/goals/development/{goal}` — `hr.development.goals.update` — `App\Http\Controllers\Hr\DevelopmentGoalController@update` — `app/Http/Controllers/Hr/DevelopmentGoalController.php:87` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/DevelopmentGoalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
