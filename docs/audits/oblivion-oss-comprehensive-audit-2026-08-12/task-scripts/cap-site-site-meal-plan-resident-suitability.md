# CAP-SITE-SITE-MEAL-PLAN-RESIDENT-SUITABILITY: Resident meal settings and conflict checks

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`, `permission:sites.meals.shopping.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-MEAL-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/meal-plan` (`sites.meals.plan.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`, `permission:sites.meals.shopping.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`, `permission:sites.meals.shopping.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-plan` (`sites.meals.plan.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/meal-planner/check-conflicts` (`sites.meals.checkConflicts`, action `checkConflicts`). Source category: **mutation outcome source gap (checkConflicts)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:221-243`; `recipe_id`, `client_ids`.
3. Invoke only the owning control for `PUT sites/{site}/meal-planner/residents/{client}` (`sites.meals.residents.update`, action `updateResident`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:432-470`; `tag_ids`, `dislikes`, `iddsi_level`, `iddsi_label`, `fluids`.
4. Invoke only the owning control for `PUT sites/{site}/meal-planner/settings` (`sites.meals.settings`, action `saveSettings`). Source category: **mutation outcome source gap (saveSettings)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:418-426`; `weekly_food_budget_cents`.

## Source-applicable states and transitions

- **mutation outcome source gap (checkConflicts)** is applicable only to `checkConflicts` / `ROUTE-2840` at `app/Http/Controllers/Sites/SiteMealPlanController.php:221`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateResident` / `ROUTE-2841` at `app/Http/Controllers/Sites/SiteMealPlanController.php:432`; it is not runtime-observed.
- **mutation outcome source gap (saveSettings)** is applicable only to `saveSettings` / `ROUTE-2842` at `app/Http/Controllers/Sites/SiteMealPlanController.php:418`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2840` / `checkConflicts`: fields `recipe_id`, `client_ids`.
- `ROUTE-2841` / `updateResident`: fields `tag_ids`, `dislikes`, `iddsi_level`, `iddsi_label`, `fluids`.
- `ROUTE-2842` / `saveSettings`: fields `weekly_food_budget_cents`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealPlanController.php:447 `$client->mealDietaryTags()->sync($data['tag_ids'] ?? []);`; app/Http/Controllers/Sites/SiteMealPlanController.php:450 `$client->mealDislikes()->whereNull('product_id')->delete();`; app/Http/Controllers/Sites/SiteMealPlanController.php:456 `ClientMealDislike::create([`; app/Http/Controllers/Sites/SiteMealPlanController.php:463 `$client->update([`; app/Http/Controllers/Sites/SiteMealPlanController.php:424 `$site->update(['weekly_food_budget_cents' => $data['weekly_food_budget_cents'] ?? null]);`; responses app/Http/Controllers/Sites/SiteMealPlanController.php:230 `return response()->json([`; app/Http/Controllers/Sites/SiteMealPlanController.php:242 `return response()->json($report);`; app/Http/Controllers/Sites/SiteMealPlanController.php:469 `return $this->inertiaOrJson($request, 'Resident dietary profile updated');`; app/Http/Controllers/Sites/SiteMealPlanController.php:425 `return $this->inertiaOrJson($request, 'Meal planner settings saved');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/meal-planner/check-conflicts` — `sites.meals.checkConflicts` — `App\Http\Controllers\Sites\SiteMealPlanController@checkConflicts` — `app/Http/Controllers/Sites/SiteMealPlanController.php:221` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `PUT sites/{site}/meal-planner/residents/{client}` — `sites.meals.residents.update` — `App\Http\Controllers\Sites\SiteMealPlanController@updateResident` — `app/Http/Controllers/Sites/SiteMealPlanController.php:432` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `PUT sites/{site}/meal-planner/settings` — `sites.meals.settings` — `App\Http\Controllers\Sites\SiteMealPlanController@saveSettings` — `app/Http/Controllers/Sites/SiteMealPlanController.php:418` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.shopping.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
