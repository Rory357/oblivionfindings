# SITE-RECIPE: Recipe

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.recipes.view`, `permission:catering.recipes.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-RECIPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `catering/recipes` (`catering.recipes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:catering.recipes.view`, `permission:catering.recipes.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:catering.recipes.view`, `permission:catering.recipes.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD catering/recipes` (`catering.recipes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD catering/recipes/{recipe}` (`catering.recipes.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Catering/RecipeController.php:22-26`.
3. Use `GET|HEAD catering/recipes/{recipe}/edit` (`catering.recipes.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Catering/RecipeController.php:34-47`.
4. Use `GET|HEAD catering/recipes/create` (`catering.recipes.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Catering/RecipeController.php:28-32`.
5. Invoke only the owning control for `POST catering/recipes` (`catering.recipes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Catering/RecipeController.php:49-81`; no exact validation fields extracted.
6. Invoke only the owning control for `DELETE catering/recipes/{recipe}` (`catering.recipes.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Catering/RecipeController.php:125-136`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT catering/recipes/{recipe}` (`catering.recipes.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Catering/RecipeController.php:83-123`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0103` at `app/Http/Controllers/Catering/RecipeController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0104` at `app/Http/Controllers/Catering/RecipeController.php:49`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0105` at `app/Http/Controllers/Catering/RecipeController.php:125`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0106` at `app/Http/Controllers/Catering/RecipeController.php:22`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0107` at `app/Http/Controllers/Catering/RecipeController.php:83`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0108` at `app/Http/Controllers/Catering/RecipeController.php:34`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0109` at `app/Http/Controllers/Catering/RecipeController.php:28`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Catering/RecipeController.php:56 `$recipe = MealRecipe::create([`; app/Http/Controllers/Catering/RecipeController.php:70 `$recipe->tags()->sync($data['tag_ids'] ?? []);`; app/Http/Controllers/Catering/RecipeController.php:128 `$recipe->delete();`; app/Http/Controllers/Catering/RecipeController.php:113 `$recipe->update($update);`; app/Http/Controllers/Catering/RecipeController.php:114 `$recipe->tags()->sync($data['tag_ids'] ?? []);`; responses app/Http/Controllers/Catering/RecipeController.php:19 `return redirect()->route('catering.meal-planner');`; app/Http/Controllers/Catering/RecipeController.php:73 `return $recipe;`; app/Http/Controllers/Catering/RecipeController.php:77 `return response()->json(['recipe' => $recipe->load(['ingredients', 'tags:id'])]);`; app/Http/Controllers/Catering/RecipeController.php:80 `return redirect()->route('catering.meal-planner')->with('status', 'Recipe created');`; app/Http/Controllers/Catering/RecipeController.php:132 `return response()->json(['deleted' => true]);`; app/Http/Controllers/Catering/RecipeController.php:135 `return redirect()->route('catering.meal-planner')->with('status', 'Recipe archived');`; app/Http/Controllers/Catering/RecipeController.php:25 `return redirect()->route('catering.meal-planner');`; app/Http/Controllers/Catering/RecipeController.php:119 `return response()->json(['recipe' => $recipe->fresh(['ingredients', 'tags:id'])]);`; app/Http/Controllers/Catering/RecipeController.php:122 `return redirect()->route('catering.meal-planner')->with('status', 'Recipe updated');`; app/Http/Controllers/Catering/RecipeController.php:42 `return response()->json(['recipe' => $recipe]);`; app/Http/Controllers/Catering/RecipeController.php:46 `return redirect()->route('catering.meal-planner');`; app/Http/Controllers/Catering/RecipeController.php:31 `return redirect()->route('catering.meal-planner');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD catering/recipes` — `catering.recipes.index` — `App\Http\Controllers\Catering\RecipeController@index` — `app/Http/Controllers/Catering/RecipeController.php:13` — middleware `web, auth, verified, permission:catering.recipes.view`
- `POST catering/recipes` — `catering.recipes.store` — `App\Http\Controllers\Catering\RecipeController@store` — `app/Http/Controllers/Catering/RecipeController.php:49` — middleware `web, auth, verified, permission:catering.recipes.view, permission:catering.recipes.manage`
- `DELETE catering/recipes/{recipe}` — `catering.recipes.destroy` — `App\Http\Controllers\Catering\RecipeController@destroy` — `app/Http/Controllers/Catering/RecipeController.php:125` — middleware `web, auth, verified, permission:catering.recipes.view, permission:catering.recipes.manage`
- `GET|HEAD catering/recipes/{recipe}` — `catering.recipes.show` — `App\Http\Controllers\Catering\RecipeController@show` — `app/Http/Controllers/Catering/RecipeController.php:22` — middleware `web, auth, verified, permission:catering.recipes.view`
- `PUT catering/recipes/{recipe}` — `catering.recipes.update` — `App\Http\Controllers\Catering\RecipeController@update` — `app/Http/Controllers/Catering/RecipeController.php:83` — middleware `web, auth, verified, permission:catering.recipes.view, permission:catering.recipes.manage`
- `GET|HEAD catering/recipes/{recipe}/edit` — `catering.recipes.edit` — `App\Http\Controllers\Catering\RecipeController@edit` — `app/Http/Controllers/Catering/RecipeController.php:34` — middleware `web, auth, verified, permission:catering.recipes.view, permission:catering.recipes.manage`
- `GET|HEAD catering/recipes/create` — `catering.recipes.create` — `App\Http\Controllers\Catering\RecipeController@create` — `app/Http/Controllers/Catering/RecipeController.php:28` — middleware `web, auth, verified, permission:catering.recipes.view, permission:catering.recipes.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Catering/RecipeController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
