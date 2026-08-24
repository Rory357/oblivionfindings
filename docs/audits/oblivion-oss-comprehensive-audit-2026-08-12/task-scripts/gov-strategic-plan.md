# GOV-STRATEGIC-PLAN: Strategic Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.strategy.view`, `permission:governance.strategy.manage`
- Owning module: Governance
- Legacy family: `GOV-STRATEGIC-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/strategy` (`governance.strategy.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.strategy.view`, `permission:governance.strategy.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.strategy.view`, `permission:governance.strategy.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/strategy` (`governance.strategy.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/strategy/{plan}` (`governance.strategy.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:33-42`.
3. Use `GET|HEAD governance/strategy/{plan}/changes` (`governance.strategy.changes`, action `changes`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:167-177`.
4. Use `GET|HEAD governance/strategy/{plan}/edit` (`governance.strategy.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:142-151`.
5. Use `GET|HEAD governance/strategy/create` (`governance.strategy.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:12-17`.
6. Invoke only the owning control for `POST governance/strategy` (`governance.strategy.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:44-72`; `title`.
7. Invoke only the owning control for `PUT governance/strategy/{plan}` (`governance.strategy.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:74-102`; `title`.
8. Invoke only the owning control for `POST governance/strategy/{plan}/approve` (`governance.strategy.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:128-140`; `resolution_id`, `notes`.
9. Invoke only the owning control for `POST governance/strategy/{plan}/goals` (`governance.strategy.goals.add`, action `addGoal`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:104-126`; `title`.
10. Invoke only the owning control for `POST governance/strategy/{plan}/version` (`governance.strategy.version`, action `createVersion`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:153-165`; `version_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1031` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1032` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:44`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1033` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:33`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1034` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:74`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1035` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:128`; it is not runtime-observed.
- **information presented** is applicable only to `changes` / `ROUTE-1036` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:167`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1037` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:142`; it is not runtime-observed.
- **created/recorded** is applicable only to `addGoal` / `ROUTE-1038` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:104`; it is not runtime-observed.
- **created/recorded** is applicable only to `createVersion` / `ROUTE-1039` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:153`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1040` at `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:12`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Strategy/Changes.tsx`, `resources/js/pages/Governance/Strategy/Create.tsx`, `resources/js/pages/Governance/Strategy/Edit.tsx`, `resources/js/pages/Governance/Strategy/Index.tsx`, `resources/js/pages/Governance/Strategy/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1032` / `store`: fields `title`; success app/Domain/Governance/Http/Controllers/StrategicPlanController.php:71 `->with('success', 'Strategic plan created.');`.
- `ROUTE-1034` / `update`: fields `title`; success app/Domain/Governance/Http/Controllers/StrategicPlanController.php:101 `return redirect()->back()->with('success', 'Strategic plan updated.');`.
- `ROUTE-1035` / `approve`: fields `resolution_id`, `notes`; success app/Domain/Governance/Http/Controllers/StrategicPlanController.php:139 `return redirect()->back()->with('success', 'Strategic plan approved.');`.
- `ROUTE-1038` / `addGoal`: fields `title`; success app/Domain/Governance/Http/Controllers/StrategicPlanController.php:125 `return redirect()->back()->with('success', 'Goal added.');`.
- `ROUTE-1039` / `createVersion`: fields `version_notes`; success app/Domain/Governance/Http/Controllers/StrategicPlanController.php:164 `->with('success', 'New version created (v'.$newPlan->version_number.').');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/StrategicPlanController.php:59 `$plan = StrategicPlan::create([`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:90 `$plan->update([`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:123 `$plan->goals()->create($data);`; responses app/Domain/Governance/Http/Controllers/StrategicPlanController.php:28 `return Inertia::render('Governance/Strategy/Index', [`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:70 `return redirect()->route('governance.strategy.index')`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:39 `return Inertia::render('Governance/Strategy/Show', [`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:101 `return redirect()->back()->with('success', 'Strategic plan updated.');`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:139 `return redirect()->back()->with('success', 'Strategic plan approved.');`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:173 `return Inertia::render('Governance/Strategy/Changes', [`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:148 `return Inertia::render('Governance/Strategy/Edit', [`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:125 `return redirect()->back()->with('success', 'Goal added.');`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:163 `return redirect()->route('governance.strategy.show', $newPlan)`; app/Domain/Governance/Http/Controllers/StrategicPlanController.php:16 `return Inertia::render('Governance/Strategy/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/strategy` — `governance.strategy.index` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@index` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:19` — middleware `web, auth, permission:governance.strategy.view`
- `POST governance/strategy` — `governance.strategy.store` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@store` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:44` — middleware `web, auth, permission:governance.strategy.view, permission:governance.strategy.manage`
- `GET|HEAD governance/strategy/{plan}` — `governance.strategy.show` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@show` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:33` — middleware `web, auth, permission:governance.strategy.view`
- `PUT governance/strategy/{plan}` — `governance.strategy.update` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@update` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:74` — middleware `web, auth, permission:governance.strategy.view, permission:governance.strategy.manage`
- `POST governance/strategy/{plan}/approve` — `governance.strategy.approve` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@approve` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:128` — middleware `web, auth, permission:governance.strategy.view, permission:governance.strategy.manage`
- `GET|HEAD governance/strategy/{plan}/changes` — `governance.strategy.changes` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@changes` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:167` — middleware `web, auth, permission:governance.strategy.view`
- `GET|HEAD governance/strategy/{plan}/edit` — `governance.strategy.edit` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@edit` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:142` — middleware `web, auth, permission:governance.strategy.view`
- `POST governance/strategy/{plan}/goals` — `governance.strategy.goals.add` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@addGoal` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:104` — middleware `web, auth, permission:governance.strategy.view, permission:governance.strategy.manage`
- `POST governance/strategy/{plan}/version` — `governance.strategy.version` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@createVersion` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:153` — middleware `web, auth, permission:governance.strategy.view, permission:governance.strategy.manage`
- `GET|HEAD governance/strategy/create` — `governance.strategy.create` — `App\Domain\Governance\Http\Controllers\StrategicPlanController@create` — `app/Domain/Governance/Http/Controllers/StrategicPlanController.php:12` — middleware `web, auth, permission:governance.strategy.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/StrategicPlanController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Strategy/Changes.tsx`, `resources/js/pages/Governance/Strategy/Create.tsx`, `resources/js/pages/Governance/Strategy/Edit.tsx`, `resources/js/pages/Governance/Strategy/Index.tsx`, `resources/js/pages/Governance/Strategy/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
