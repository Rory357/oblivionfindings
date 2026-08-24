# CAP-SITE-SITE-MEAL-PLAN-WEEKLY-PLANNING: Weekly meal planning copy clear and summary

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-plan` (`sites.meals.plan.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/meal-plan/week-summary` (`sites.meals.plan.weekSummary`, action `weekSummary`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteMealPlanController.php:533-573`.
3. Use `GET|HEAD sites/{site}/meal-planner/bootstrap` (`sites.meals.bootstrap`, action `bootstrap`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteMealPlanController.php:42-123`.
4. Invoke only the owning control for `POST sites/{site}/meal-plan` (`sites.meals.plan.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:245-281`; no exact validation fields extracted.
5. Invoke only the owning control for `DELETE sites/{site}/meal-plan-week/clear` (`sites.meals.plan.clearWeek`, action `clearWeek`). Source category: **mutation outcome source gap (clearWeek)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:472-482`; no exact validation fields extracted.
6. Invoke only the owning control for `POST sites/{site}/meal-plan-week/copy` (`sites.meals.plan.copyWeek`, action `copyWeek`). Source category: **mutation outcome source gap (copyWeek)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:484-531`; `from_week`, `to_week`, `replace`.
7. Invoke only the owning control for `DELETE sites/{site}/meal-plan/{entry}` (`sites.meals.plan.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:327-333`; no exact validation fields extracted.
8. Invoke only the owning control for `PUT sites/{site}/meal-plan/{entry}` (`sites.meals.plan.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:283-325`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2830` at `app/Http/Controllers/Sites/SiteMealPlanController.php:192`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2831` at `app/Http/Controllers/Sites/SiteMealPlanController.php:245`; it is not runtime-observed.
- **mutation outcome source gap (clearWeek)** is applicable only to `clearWeek` / `ROUTE-2832` at `app/Http/Controllers/Sites/SiteMealPlanController.php:472`; it is not runtime-observed.
- **mutation outcome source gap (copyWeek)** is applicable only to `copyWeek` / `ROUTE-2833` at `app/Http/Controllers/Sites/SiteMealPlanController.php:484`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2834` at `app/Http/Controllers/Sites/SiteMealPlanController.php:327`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2835` at `app/Http/Controllers/Sites/SiteMealPlanController.php:283`; it is not runtime-observed.
- **information presented** is applicable only to `weekSummary` / `ROUTE-2838` at `app/Http/Controllers/Sites/SiteMealPlanController.php:533`; it is not runtime-observed.
- **information presented** is applicable only to `bootstrap` / `ROUTE-2839` at `app/Http/Controllers/Sites/SiteMealPlanController.php:42`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2833` / `copyWeek`: fields `from_week`, `to_week`, `replace`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealPlanController.php:252 `$entry = SiteMealPlanEntry::create([`; app/Http/Controllers/Sites/SiteMealPlanController.php:480 `->delete();`; app/Http/Controllers/Sites/SiteMealPlanController.php:500 `->delete();`; app/Http/Controllers/Sites/SiteMealPlanController.php:511 `SiteMealPlanEntry::create([`; app/Http/Controllers/Sites/SiteMealPlanController.php:331 `$entry->delete();`; app/Http/Controllers/Sites/SiteMealPlanController.php:314 `$entry->update($payload);`; responses app/Http/Controllers/Sites/SiteMealPlanController.php:209 `return response()->json([`; app/Http/Controllers/Sites/SiteMealPlanController.php:280 `return $this->inertiaOrJson($request, 'Meal added');`; app/Http/Controllers/Sites/SiteMealPlanController.php:481 `return $this->inertiaOrJson($request, 'Week cleared');`; app/Http/Controllers/Sites/SiteMealPlanController.php:530 `return $this->inertiaOrJson($request, "Copied {$copied} meal" . ($copied === 1 ? '' : 's'));`; app/Http/Controllers/Sites/SiteMealPlanController.php:332 `return $this->inertiaOrJson($request, 'Meal removed');`; app/Http/Controllers/Sites/SiteMealPlanController.php:324 `return $this->inertiaOrJson($request, 'Meal updated');`; app/Http/Controllers/Sites/SiteMealPlanController.php:564 `return response()->json([`; app/Http/Controllers/Sites/SiteMealPlanController.php:96 `return response()->json([`; audit calls app/Http/Controllers/Sites/SiteMealPlanController.php:273 `AuditLogger::log('meal.allergen_override', $entry, [`; app/Http/Controllers/Sites/SiteMealPlanController.php:317 `AuditLogger::log('meal.allergen_override', $entry, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/meal-plan` — `sites.meals.plan.index` — `App\Http\Controllers\Sites\SiteMealPlanController@index` — `app/Http/Controllers/Sites/SiteMealPlanController.php:192` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `POST sites/{site}/meal-plan` — `sites.meals.plan.store` — `App\Http\Controllers\Sites\SiteMealPlanController@store` — `app/Http/Controllers/Sites/SiteMealPlanController.php:245` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `DELETE sites/{site}/meal-plan-week/clear` — `sites.meals.plan.clearWeek` — `App\Http\Controllers\Sites\SiteMealPlanController@clearWeek` — `app/Http/Controllers/Sites/SiteMealPlanController.php:472` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `POST sites/{site}/meal-plan-week/copy` — `sites.meals.plan.copyWeek` — `App\Http\Controllers\Sites\SiteMealPlanController@copyWeek` — `app/Http/Controllers/Sites/SiteMealPlanController.php:484` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `DELETE sites/{site}/meal-plan/{entry}` — `sites.meals.plan.destroy` — `App\Http\Controllers\Sites\SiteMealPlanController@destroy` — `app/Http/Controllers/Sites/SiteMealPlanController.php:327` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `PUT sites/{site}/meal-plan/{entry}` — `sites.meals.plan.update` — `App\Http\Controllers\Sites\SiteMealPlanController@update` — `app/Http/Controllers/Sites/SiteMealPlanController.php:283` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `GET|HEAD sites/{site}/meal-plan/week-summary` — `sites.meals.plan.weekSummary` — `App\Http\Controllers\Sites\SiteMealPlanController@weekSummary` — `app/Http/Controllers/Sites/SiteMealPlanController.php:533` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`
- `GET|HEAD sites/{site}/meal-planner/bootstrap` — `sites.meals.bootstrap` — `App\Http\Controllers\Sites\SiteMealPlanController@bootstrap` — `app/Http/Controllers/Sites/SiteMealPlanController.php:42` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
