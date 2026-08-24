# ROAD-INITIATIVE: Initiative

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.manage`
- Owning module: Roadmap
- Legacy family: `ROAD-INITIATIVE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `roadmap/initiatives` (`roadmap.initiatives.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:roadmap.view`, `permission:roadmap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD roadmap/initiatives` (`roadmap.initiatives.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD roadmap/initiatives/{initiative}` (`roadmap.initiatives.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:140-160`.
3. Invoke only the owning control for `POST roadmap/initiatives` (`roadmap.initiatives.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:71-138`; FormRequest `app/Domain/Roadmap/Http/Requests/StoreInitiativeRequest.php:14`; `title`, `summary`, `category_id`, `category_key`, `stream`, `owner_user_id`, `sponsor_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `impact_profile`.
4. Invoke only the owning control for `PUT roadmap/initiatives/{initiative}` (`roadmap.initiatives.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:162-204`; FormRequest `app/Domain/Roadmap/Http/Requests/UpdateInitiativeRequest.php:14`; `title`, `summary`, `stream`, `owner_user_id`, `sponsor_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `impact_profile`, `manual_priority_override`, `manual_priority_reason`.
5. Invoke only the owning control for `POST roadmap/initiatives/{initiative}/score` (`roadmap.initiatives.score`, action `score`). Source category: **mutation outcome source gap (score)**; controller `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:206-230`; `preset`.
6. Invoke only the owning control for `POST roadmap/initiatives/{initiative}/transition` (`roadmap.initiatives.transition`, action `transition`). Source category: **mutation outcome source gap (transition)**; controller `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:232-255`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2477` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:29`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2478` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:71`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2479` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:140`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2480` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:162`; it is not runtime-observed.
- **mutation outcome source gap (score)** is applicable only to `score` / `ROUTE-2481` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:206`; it is not runtime-observed.
- **mutation outcome source gap (transition)** is applicable only to `transition` / `ROUTE-2482` at `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:232`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Roadmap/Initiatives/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2478` / `store`: FormRequest `app/Domain/Roadmap/Http/Requests/StoreInitiativeRequest.php:14`; fields `title`, `summary`, `category_id`, `category_key`, `stream`, `owner_user_id`, `sponsor_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `impact_profile`.
- `ROUTE-2480` / `update`: FormRequest `app/Domain/Roadmap/Http/Requests/UpdateInitiativeRequest.php:14`; fields `title`, `summary`, `stream`, `owner_user_id`, `sponsor_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `impact_profile`, `manual_priority_override`, `manual_priority_reason`.
- `ROUTE-2481` / `score`: fields `preset`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Roadmap/Http/Controllers/InitiativeController.php:87 `$category = InitiativeCategory::create([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:99 `$initiative = Initiative::create([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:176 `$initiative->update($data);`; responses app/Domain/Roadmap/Http/Controllers/InitiativeController.php:56 `return response()->json(['items' => $items]);`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:59 `return Inertia::render('Roadmap/Initiatives/Index', [`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:135 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:144 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:171 `return response()->json(['message' => 'Invalid status transition.'], 422);`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:201 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:226 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:241 `return response()->json(['message' => 'Invalid status transition.'], 422);`; app/Domain/Roadmap/Http/Controllers/InitiativeController.php:254 `return response()->json(['item' => $initiative->fresh()]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD roadmap/initiatives` — `roadmap.initiatives.index` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@index` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:29` — middleware `web, auth, permission:roadmap.view`
- `POST roadmap/initiatives` — `roadmap.initiatives.store` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@store` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:71` — middleware `web, auth, permission:roadmap.manage`
- `GET|HEAD roadmap/initiatives/{initiative}` — `roadmap.initiatives.show` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@show` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:140` — middleware `web, auth, permission:roadmap.view`
- `PUT roadmap/initiatives/{initiative}` — `roadmap.initiatives.update` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@update` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:162` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/initiatives/{initiative}/score` — `roadmap.initiatives.score` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@score` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:206` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/initiatives/{initiative}/transition` — `roadmap.initiatives.transition` — `App\Domain\Roadmap\Http\Controllers\InitiativeController@transition` — `app/Domain/Roadmap/Http/Controllers/InitiativeController.php:232` — middleware `web, auth, permission:roadmap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Roadmap/Http/Controllers/InitiativeController.php`.
- Exact render/action page relationships: `resources/js/pages/Roadmap/Initiatives/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
