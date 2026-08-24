# CAP-HR-GOAL-GOAL-KEY-RESULTS: Goals key results hierarchy and lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-GOAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/goals` (`hr.goals.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/goals` (`hr.goals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/goals/{goal}` (`hr.goals.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/GoalController.php:355-465`.
3. Use `GET|HEAD hr/goals/create` (`hr.goals.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/GoalController.php:221-227`.
4. Use `GET|HEAD hr/goals/export` (`hr.goals.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/GoalController.php:869-931`.
5. Invoke only the owning control for `POST hr/goals` (`hr.goals.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/GoalController.php:264-349`; `user_id`.
6. Invoke only the owning control for `DELETE hr/goals/{goal}` (`hr.goals.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/GoalController.php:516-524`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT hr/goals/{goal}` (`hr.goals.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/GoalController.php:471-510`; `title`.
8. Invoke only the owning control for `POST hr/goals/{goal}/duplicate` (`hr.goals.duplicate`, action `duplicate`). Source category: **mutation outcome source gap (duplicate)**; controller `app/Http/Controllers/Hr/GoalController.php:771-818`; `cycle_id`.
9. Invoke only the owning control for `POST hr/goals/{goal}/key-results` (`hr.goals.key-results.store`, action `storeKeyResult`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/GoalController.php:625-663`; `title`.
10. Invoke only the owning control for `PATCH hr/goals/{goal}/parent` (`hr.goals.reparent`, action `reparent`). Source category: **mutation outcome source gap (reparent)**; controller `app/Http/Controllers/Hr/GoalController.php:821-852`; `parent_goal_id`.
11. Invoke only the owning control for `POST hr/goals/bulk` (`hr.goals.bulk`, action `bulk`). Source category: **mutation outcome source gap (bulk)**; controller `app/Http/Controllers/Hr/GoalController.php:716-768`; `action`.
12. Invoke only the owning control for `DELETE hr/goals/key-results/{keyResult}` (`hr.goals.key-results.destroy`, action `destroyKeyResult`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/GoalController.php:697-709`; no exact validation fields extracted.
13. Invoke only the owning control for `PUT hr/goals/key-results/{keyResult}` (`hr.goals.key-results.update`, action `updateKeyResult`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/GoalController.php:665-695`; `current_value`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1455` at `app/Http/Controllers/Hr/GoalController.php:32`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1456` at `app/Http/Controllers/Hr/GoalController.php:264`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1457` at `app/Http/Controllers/Hr/GoalController.php:516`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1458` at `app/Http/Controllers/Hr/GoalController.php:355`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1459` at `app/Http/Controllers/Hr/GoalController.php:471`; it is not runtime-observed.
- **mutation outcome source gap (duplicate)** is applicable only to `duplicate` / `ROUTE-1461` at `app/Http/Controllers/Hr/GoalController.php:771`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeKeyResult` / `ROUTE-1462` at `app/Http/Controllers/Hr/GoalController.php:625`; it is not runtime-observed.
- **mutation outcome source gap (reparent)** is applicable only to `reparent` / `ROUTE-1463` at `app/Http/Controllers/Hr/GoalController.php:821`; it is not runtime-observed.
- **mutation outcome source gap (bulk)** is applicable only to `bulk` / `ROUTE-1465` at `app/Http/Controllers/Hr/GoalController.php:716`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1466` at `app/Http/Controllers/Hr/GoalController.php:221`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1476` at `app/Http/Controllers/Hr/GoalController.php:869`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyKeyResult` / `ROUTE-1477` at `app/Http/Controllers/Hr/GoalController.php:697`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateKeyResult` / `ROUTE-1478` at `app/Http/Controllers/Hr/GoalController.php:665`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/goals/index.tsx`, `resources/js/pages/hr/goals/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1456` / `store`: fields `user_id`; success app/Http/Controllers/Hr/GoalController.php:345 `return redirect()->back()->with('success', 'Objective created.');`; app/Http/Controllers/Hr/GoalController.php:348 `return redirect("/hr/goals/{$goal->id}")->with('success', 'Objective created.');`.
- `ROUTE-1457` / `destroy`: success app/Http/Controllers/Hr/GoalController.php:523 `return redirect()->route('hr.goals.index')->with('success', 'Objective deleted.');`.
- `ROUTE-1459` / `update`: fields `title`; success app/Http/Controllers/Hr/GoalController.php:509 `return redirect()->back()->with('success', 'Objective updated.');`.
- `ROUTE-1461` / `duplicate`: fields `cycle_id`; success app/Http/Controllers/Hr/GoalController.php:817 `return redirect()->back()->with('success', 'Objective duplicated.');`.
- `ROUTE-1462` / `storeKeyResult`: fields `title`; success app/Http/Controllers/Hr/GoalController.php:662 `return redirect()->back()->with('success', 'Key result added.');`.
- `ROUTE-1463` / `reparent`: fields `parent_goal_id`; success app/Http/Controllers/Hr/GoalController.php:851 `return redirect()->back()->with('success', 'Objective moved.');`.
- `ROUTE-1465` / `bulk`: fields `action`; success app/Http/Controllers/Hr/GoalController.php:767 `return redirect()->back()->with('success', "{$count} objective(s) updated.");`.
- `ROUTE-1477` / `destroyKeyResult`: success app/Http/Controllers/Hr/GoalController.php:708 `return redirect()->back()->with('success', 'Key result removed.');`.
- `ROUTE-1478` / `updateKeyResult`: fields `current_value`; success app/Http/Controllers/Hr/GoalController.php:694 `return redirect()->back()->with('success', 'Key result updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/GoalController.php:313 `$created = HrKeyResult::create([`; app/Http/Controllers/Hr/GoalController.php:329 `$created->save();`; app/Http/Controllers/Hr/GoalController.php:521 `$goal->delete();`; app/Http/Controllers/Hr/GoalController.php:507 `$goal->update($data);`; app/Http/Controllers/Hr/GoalController.php:802 `$clone->save();`; app/Http/Controllers/Hr/GoalController.php:812 `$krClone->save();`; app/Http/Controllers/Hr/GoalController.php:641 `$kr = HrKeyResult::create([`; app/Http/Controllers/Hr/GoalController.php:657 `$kr->save();`; app/Http/Controllers/Hr/GoalController.php:841 `$goal->update(['parent_goal_id' => $parentId]);`; app/Http/Controllers/Hr/GoalController.php:737 `$goal->update(['status' => 'cancelled']);`; app/Http/Controllers/Hr/GoalController.php:742 `$goal->update(['user_id' => $data['owner_id']]);`; app/Http/Controllers/Hr/GoalController.php:703 `$keyResult->delete();`; app/Http/Controllers/Hr/GoalController.php:689 `$keyResult->save();`; responses app/Http/Controllers/Hr/GoalController.php:80 `return Inertia::render('hr/goals/index', [`; app/Http/Controllers/Hr/GoalController.php:336 `return $goal;`; app/Http/Controllers/Hr/GoalController.php:345 `return redirect()->back()->with('success', 'Objective created.');`; app/Http/Controllers/Hr/GoalController.php:348 `return redirect("/hr/goals/{$goal->id}")->with('success', 'Objective created.');`; app/Http/Controllers/Hr/GoalController.php:523 `return redirect()->route('hr.goals.index')->with('success', 'Objective deleted.');`; app/Http/Controllers/Hr/GoalController.php:373 `return Inertia::render('hr/goals/show', [`; app/Http/Controllers/Hr/GoalController.php:509 `return redirect()->back()->with('success', 'Objective updated.');`; app/Http/Controllers/Hr/GoalController.php:817 `return redirect()->back()->with('success', 'Objective duplicated.');`; app/Http/Controllers/Hr/GoalController.php:662 `return redirect()->back()->with('success', 'Key result added.');`; app/Http/Controllers/Hr/GoalController.php:851 `return redirect()->back()->with('success', 'Objective moved.');`; app/Http/Controllers/Hr/GoalController.php:767 `return redirect()->back()->with('success', "{$count} objective(s) updated.");`; app/Http/Controllers/Hr/GoalController.php:226 `return redirect()->route('hr.goals.index');`; app/Http/Controllers/Hr/GoalController.php:887 `return response()->streamDownload(function () use ($goals) {`; app/Http/Controllers/Hr/GoalController.php:708 `return redirect()->back()->with('success', 'Key result removed.');`; app/Http/Controllers/Hr/GoalController.php:694 `return redirect()->back()->with('success', 'Key result updated.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/GoalController.php:341 `$owner->notify(new GoalAssignedNotification($goal));`; app/Http/Controllers/Hr/GoalController.php:748 `$owner->notify(new GoalAssignedNotification($goal, checkinReminder: true));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD hr/goals` — `hr.goals.index` — `App\Http\Controllers\Hr\GoalController@index` — `app/Http/Controllers/Hr/GoalController.php:32` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/goals` — `hr.goals.store` — `App\Http\Controllers\Hr\GoalController@store` — `app/Http/Controllers/Hr/GoalController.php:264` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `DELETE hr/goals/{goal}` — `hr.goals.destroy` — `App\Http\Controllers\Hr\GoalController@destroy` — `app/Http/Controllers/Hr/GoalController.php:516` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/goals/{goal}` — `hr.goals.show` — `App\Http\Controllers\Hr\GoalController@show` — `app/Http/Controllers/Hr/GoalController.php:355` — middleware `web, auth, permission:hr.performance.view`
- `PUT hr/goals/{goal}` — `hr.goals.update` — `App\Http\Controllers\Hr\GoalController@update` — `app/Http/Controllers/Hr/GoalController.php:471` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/goals/{goal}/duplicate` — `hr.goals.duplicate` — `App\Http\Controllers\Hr\GoalController@duplicate` — `app/Http/Controllers/Hr/GoalController.php:771` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/goals/{goal}/key-results` — `hr.goals.key-results.store` — `App\Http\Controllers\Hr\GoalController@storeKeyResult` — `app/Http/Controllers/Hr/GoalController.php:625` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PATCH hr/goals/{goal}/parent` — `hr.goals.reparent` — `App\Http\Controllers\Hr\GoalController@reparent` — `app/Http/Controllers/Hr/GoalController.php:821` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/goals/bulk` — `hr.goals.bulk` — `App\Http\Controllers\Hr\GoalController@bulk` — `app/Http/Controllers/Hr/GoalController.php:716` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/goals/create` — `hr.goals.create` — `App\Http\Controllers\Hr\GoalController@create` — `app/Http/Controllers/Hr/GoalController.php:221` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/goals/export` — `hr.goals.export` — `App\Http\Controllers\Hr\GoalController@export` — `app/Http/Controllers/Hr/GoalController.php:869` — middleware `web, auth, permission:hr.performance.view`
- `DELETE hr/goals/key-results/{keyResult}` — `hr.goals.key-results.destroy` — `App\Http\Controllers\Hr\GoalController@destroyKeyResult` — `app/Http/Controllers/Hr/GoalController.php:697` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/goals/key-results/{keyResult}` — `hr.goals.key-results.update` — `App\Http\Controllers\Hr\GoalController@updateKeyResult` — `app/Http/Controllers/Hr/GoalController.php:665` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/GoalController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/goals/index.tsx`, `resources/js/pages/hr/goals/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
