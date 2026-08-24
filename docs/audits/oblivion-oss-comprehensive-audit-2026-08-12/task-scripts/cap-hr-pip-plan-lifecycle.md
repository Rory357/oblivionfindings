# CAP-HR-PIP-PLAN-LIFECYCLE: Performance improvement plan lifecycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-PIP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/pips` (`hr.pips.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/pips` (`hr.pips.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/performance/pips/{pip}` (`hr.pips.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PipController.php:185-203`.
3. Use `GET|HEAD hr/performance/pips/create` (`hr.pips.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PipController.php:78-88`.
4. Invoke only the owning control for `POST hr/performance/pips` (`hr.pips.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PipController.php:93-180`; `employee_user_id`.
5. Invoke only the owning control for `PUT hr/performance/pips/{pip}` (`hr.pips.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PipController.php:208-228`; `title`.
6. Invoke only the owning control for `POST hr/performance/pips/{pip}/acknowledge` (`hr.pips.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/PipController.php:296-313`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/performance/pips/{pip}/cancel` (`hr.pips.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/PipController.php:233-251`; `outcome_notes`.
8. Invoke only the owning control for `POST hr/performance/pips/{pip}/complete` (`hr.pips.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/PipController.php:381-413`; `outcome`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1624` at `app/Http/Controllers/Hr/PipController.php:24`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1625` at `app/Http/Controllers/Hr/PipController.php:93`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1626` at `app/Http/Controllers/Hr/PipController.php:185`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1627` at `app/Http/Controllers/Hr/PipController.php:208`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1628` at `app/Http/Controllers/Hr/PipController.php:296`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1629` at `app/Http/Controllers/Hr/PipController.php:233`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-1630` at `app/Http/Controllers/Hr/PipController.php:381`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1632` at `app/Http/Controllers/Hr/PipController.php:78`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/performance/pips/create.tsx`, `resources/js/pages/hr/performance/pips/index.tsx`, `resources/js/pages/hr/performance/pips/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1625` / `store`: fields `employee_user_id`; success app/Http/Controllers/Hr/PipController.php:176 `return redirect()->back()->with('success', 'Performance Improvement Plan created.');`; app/Http/Controllers/Hr/PipController.php:179 `return redirect()->route('hr.pips.index')->with('success', 'Performance Improvement Plan created.');`.
- `ROUTE-1627` / `update`: fields `title`; success app/Http/Controllers/Hr/PipController.php:227 `return redirect()->back()->with('success', 'Plan updated.');`.
- `ROUTE-1628` / `acknowledge`: success app/Http/Controllers/Hr/PipController.php:312 `return redirect()->back()->with('success', 'Plan acknowledged.');`.
- `ROUTE-1629` / `cancel`: fields `outcome_notes`; success app/Http/Controllers/Hr/PipController.php:250 `return redirect()->back()->with('success', 'Plan cancelled.');`.
- `ROUTE-1630` / `complete`: fields `outcome`; success app/Http/Controllers/Hr/PipController.php:405 `->with('success', 'PIP completed with an unsuccessful outcome. If formal action is the next step, open a disciplinary case so it is handled through the proper process.')`; app/Http/Controllers/Hr/PipController.php:412 `return redirect()->back()->with('success', 'PIP completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PipController.php:131 `$pip = HrPerformanceImprovementPlan::create([`; app/Http/Controllers/Hr/PipController.php:149 `HrPipMilestone::create([`; app/Http/Controllers/Hr/PipController.php:225 `$pip->update([...$data, 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/PipController.php:306 `$pip->update([`; app/Http/Controllers/Hr/PipController.php:243 `$pip->update([`; app/Http/Controllers/Hr/PipController.php:391 `$pip->update([`; responses app/Http/Controllers/Hr/PipController.php:63 `return Inertia::render('hr/performance/pips/index', [`; app/Http/Controllers/Hr/PipController.php:159 `return $pip;`; app/Http/Controllers/Hr/PipController.php:176 `return redirect()->back()->with('success', 'Performance Improvement Plan created.');`; app/Http/Controllers/Hr/PipController.php:179 `return redirect()->route('hr.pips.index')->with('success', 'Performance Improvement Plan created.');`; app/Http/Controllers/Hr/PipController.php:196 `return Inertia::render('hr/performance/pips/show', [`; app/Http/Controllers/Hr/PipController.php:227 `return redirect()->back()->with('success', 'Plan updated.');`; app/Http/Controllers/Hr/PipController.php:312 `return redirect()->back()->with('success', 'Plan acknowledged.');`; app/Http/Controllers/Hr/PipController.php:250 `return redirect()->back()->with('success', 'Plan cancelled.');`; app/Http/Controllers/Hr/PipController.php:403 `return redirect()`; app/Http/Controllers/Hr/PipController.php:412 `return redirect()->back()->with('success', 'PIP completed.');`; app/Http/Controllers/Hr/PipController.php:85 `return Inertia::render('hr/performance/pips/create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/PipController.php:167 `$employee?->notify(new PipCreatedNotification($pip, $managerName, forSubject: true));`; app/Http/Controllers/Hr/PipController.php:169 `$user->notify(new PipCreatedNotification($pip, $managerName, forSubject: false));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD hr/performance/pips` — `hr.pips.index` — `App\Http\Controllers\Hr\PipController@index` — `app/Http/Controllers/Hr/PipController.php:24` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/pips` — `hr.pips.store` — `App\Http\Controllers\Hr\PipController@store` — `app/Http/Controllers/Hr/PipController.php:93` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/pips/{pip}` — `hr.pips.show` — `App\Http\Controllers\Hr\PipController@show` — `app/Http/Controllers/Hr/PipController.php:185` — middleware `web, auth`
- `PUT hr/performance/pips/{pip}` — `hr.pips.update` — `App\Http\Controllers\Hr\PipController@update` — `app/Http/Controllers/Hr/PipController.php:208` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/pips/{pip}/acknowledge` — `hr.pips.acknowledge` — `App\Http\Controllers\Hr\PipController@acknowledge` — `app/Http/Controllers/Hr/PipController.php:296` — middleware `web, auth`
- `POST hr/performance/pips/{pip}/cancel` — `hr.pips.cancel` — `App\Http\Controllers\Hr\PipController@cancel` — `app/Http/Controllers/Hr/PipController.php:233` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/pips/{pip}/complete` — `hr.pips.complete` — `App\Http\Controllers\Hr\PipController@complete` — `app/Http/Controllers/Hr/PipController.php:381` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/pips/create` — `hr.pips.create` — `App\Http\Controllers\Hr\PipController@create` — `app/Http/Controllers/Hr/PipController.php:78` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PipController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/performance/pips/create.tsx`, `resources/js/pages/hr/performance/pips/index.tsx`, `resources/js/pages/hr/performance/pips/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
