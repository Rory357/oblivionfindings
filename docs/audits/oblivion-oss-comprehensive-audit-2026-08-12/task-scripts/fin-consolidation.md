# FIN-CONSOLIDATION: Consolidation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-CONSOLIDATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/consolidation` (`finance.consolidation.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/consolidation` (`finance.consolidation.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/consolidation/{group}` (`finance.consolidation.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ConsolidationController.php:76-124`.
3. Use `GET|HEAD finance/consolidation/{group}/mapping` (`finance.consolidation.mapping`, action `mapping`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ConsolidationController.php:288-326`.
4. Use `GET|HEAD finance/consolidation/{group}/runs` (`finance.consolidation.runs`, action `runs`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ConsolidationController.php:175-218`.
5. Use `GET|HEAD finance/consolidation/{group}/runs/{run}` (`finance.consolidation.show-run`, action `showRun`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/ConsolidationController.php:249-283`.
6. Invoke only the owning control for `POST finance/consolidation` (`finance.consolidation.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/ConsolidationController.php:52-71`; `name`, `description`, `base_currency_code`.
7. Invoke only the owning control for `POST finance/consolidation/{group}/entities` (`finance.consolidation.add-entity`, action `addEntity`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/ConsolidationController.php:129-153`; `organization_id`, `entity_name`, `ownership_percentage`, `consolidation_method`, `currency_code`.
8. Invoke only the owning control for `DELETE finance/consolidation/{group}/entities/{entity}` (`finance.consolidation.remove-entity`, action `removeEntity`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/ConsolidationController.php:158-170`; no exact validation fields extracted.
9. Invoke only the owning control for `PUT finance/consolidation/{group}/mapping` (`finance.consolidation.mapping.update`, action `updateMapping`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/ConsolidationController.php:331-361`; `mappings`.
10. Invoke only the owning control for `POST finance/consolidation/{group}/run` (`finance.consolidation.run`, action `runConsolidation`). Source category: **mutation outcome source gap (runConsolidation)**; controller `app/Domain/Finance/Http/Controllers/ConsolidationController.php:223-244`; `period_from`, `period_to`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0517` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:23`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0518` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:52`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0519` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:76`; it is not runtime-observed.
- **created/recorded** is applicable only to `addEntity` / `ROUTE-0520` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:129`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeEntity` / `ROUTE-0521` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:158`; it is not runtime-observed.
- **information presented** is applicable only to `mapping` / `ROUTE-0522` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:288`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMapping` / `ROUTE-0523` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:331`; it is not runtime-observed.
- **mutation outcome source gap (runConsolidation)** is applicable only to `runConsolidation` / `ROUTE-0524` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:223`; it is not runtime-observed.
- **information presented** is applicable only to `runs` / `ROUTE-0525` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:175`; it is not runtime-observed.
- **information presented** is applicable only to `showRun` / `ROUTE-0526` at `app/Domain/Finance/Http/Controllers/ConsolidationController.php:249`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/Consolidation/Index.tsx`, `resources/js/pages/finance/Consolidation/RunResults.tsx`, `resources/js/pages/finance/Consolidation/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0518` / `store`: fields `name`, `description`, `base_currency_code`; success app/Domain/Finance/Http/Controllers/ConsolidationController.php:70 `->with('success', 'Consolidation group created successfully.');`.
- `ROUTE-0520` / `addEntity`: fields `organization_id`, `entity_name`, `ownership_percentage`, `consolidation_method`, `currency_code`; success app/Domain/Finance/Http/Controllers/ConsolidationController.php:152 `->with('success', 'Entity added to consolidation group.');`.
- `ROUTE-0521` / `removeEntity`: success app/Domain/Finance/Http/Controllers/ConsolidationController.php:169 `->with('success', 'Entity removed from consolidation group.');`; failure app/Domain/Finance/Http/Controllers/ConsolidationController.php:163 `abort(404);`.
- `ROUTE-0523` / `updateMapping`: fields `mappings`; success app/Domain/Finance/Http/Controllers/ConsolidationController.php:360 `->with('success', 'Account mappings updated successfully.');`.
- `ROUTE-0524` / `runConsolidation`: fields `period_from`, `period_to`; success app/Domain/Finance/Http/Controllers/ConsolidationController.php:240 `->with('success', 'Consolidation run completed successfully.');`; failure app/Domain/Finance/Http/Controllers/ConsolidationController.php:242 `return back()->withErrors(['consolidation' => 'Consolidation failed: ' . $e->getMessage()]);`.
- `ROUTE-0526` / `showRun`: failure app/Domain/Finance/Http/Controllers/ConsolidationController.php:254 `abort(404);`.

## Failure and recovery paths

- `removeEntity`: app/Domain/Finance/Http/Controllers/ConsolidationController.php:163 `abort(404);`.
- `runConsolidation`: app/Domain/Finance/Http/Controllers/ConsolidationController.php:242 `return back()->withErrors(['consolidation' => 'Consolidation failed: ' . $e->getMessage()]);`.
- `showRun`: app/Domain/Finance/Http/Controllers/ConsolidationController.php:254 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/ConsolidationController.php:60 `FinConsolidationGroup::create([`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:141 `FinConsolidationEntity::create([`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:166 `$entity->delete();`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:345 `FinAccountMapping::updateOrCreate(`; responses app/Domain/Finance/Http/Controllers/ConsolidationController.php:44 `return Inertia::render('finance/Consolidation/Index', [`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:69 `return redirect()->route('finance.consolidation.index')`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:112 `return Inertia::render('finance/Consolidation/Show', [`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:151 `return redirect()->route('finance.consolidation.show', $group)`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:168 `return redirect()->route('finance.consolidation.show', $group)`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:306 `return Inertia::render('finance/Consolidation/Show', [`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:359 `return redirect()->route('finance.consolidation.mapping', $group)`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:239 `return redirect()->route('finance.consolidation.show-run', [$group, $run])`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:242 `return back()->withErrors(['consolidation' => 'Consolidation failed: ' . $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:199 `return Inertia::render('finance/Consolidation/Show', [`; app/Domain/Finance/Http/Controllers/ConsolidationController.php:259 `return Inertia::render('finance/Consolidation/RunResults', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/consolidation` — `finance.consolidation.index` — `App\Domain\Finance\Http\Controllers\ConsolidationController@index` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:23` — middleware `web, auth, permission:finance.admin`
- `POST finance/consolidation` — `finance.consolidation.store` — `App\Domain\Finance\Http\Controllers\ConsolidationController@store` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:52` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/consolidation/{group}` — `finance.consolidation.show` — `App\Domain\Finance\Http\Controllers\ConsolidationController@show` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:76` — middleware `web, auth, permission:finance.admin`
- `POST finance/consolidation/{group}/entities` — `finance.consolidation.add-entity` — `App\Domain\Finance\Http\Controllers\ConsolidationController@addEntity` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:129` — middleware `web, auth, permission:finance.admin`
- `DELETE finance/consolidation/{group}/entities/{entity}` — `finance.consolidation.remove-entity` — `App\Domain\Finance\Http\Controllers\ConsolidationController@removeEntity` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:158` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/consolidation/{group}/mapping` — `finance.consolidation.mapping` — `App\Domain\Finance\Http\Controllers\ConsolidationController@mapping` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:288` — middleware `web, auth, permission:finance.admin`
- `PUT finance/consolidation/{group}/mapping` — `finance.consolidation.mapping.update` — `App\Domain\Finance\Http\Controllers\ConsolidationController@updateMapping` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:331` — middleware `web, auth, permission:finance.admin`
- `POST finance/consolidation/{group}/run` — `finance.consolidation.run` — `App\Domain\Finance\Http\Controllers\ConsolidationController@runConsolidation` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:223` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/consolidation/{group}/runs` — `finance.consolidation.runs` — `App\Domain\Finance\Http\Controllers\ConsolidationController@runs` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:175` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/consolidation/{group}/runs/{run}` — `finance.consolidation.show-run` — `App\Domain\Finance\Http\Controllers\ConsolidationController@showRun` — `app/Domain/Finance/Http/Controllers/ConsolidationController.php:249` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/ConsolidationController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/Consolidation/Index.tsx`, `resources/js/pages/finance/Consolidation/RunResults.tsx`, `resources/js/pages/finance/Consolidation/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
