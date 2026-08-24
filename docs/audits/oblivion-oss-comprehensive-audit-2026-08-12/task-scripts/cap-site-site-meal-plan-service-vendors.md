# CAP-SITE-SITE-MEAL-PLAN-SERVICE-VENDORS: Meal service status and takeaway vendors

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-MEAL-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/meal-planner/takeaway-vendors` (`sites.meals.takeawayVendors`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.meals.view`, `permission:sites.meals.plan`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/meal-planner/takeaway-vendors` (`sites.meals.takeawayVendors`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/meal-plan/{entry}/serve` (`sites.meals.plan.serve`, action `markServed`). Source category: **mutation outcome source gap (markServed)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:335-355`; no exact validation fields extracted.
3. Invoke only the owning control for `POST sites/{site}/meal-plan/{entry}/unserve` (`sites.meals.plan.unserve`, action `unserve`). Source category: **mutation outcome source gap (unserve)**; controller `app/Http/Controllers/Sites/SiteMealPlanController.php:357-375`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (markServed)** is applicable only to `markServed` / `ROUTE-2836` at `app/Http/Controllers/Sites/SiteMealPlanController.php:335`; it is not runtime-observed.
- **mutation outcome source gap (unserve)** is applicable only to `unserve` / `ROUTE-2837` at `app/Http/Controllers/Sites/SiteMealPlanController.php:357`; it is not runtime-observed.
- **information presented** is applicable only to `takeawayVendors` / `ROUTE-2843` at `app/Http/Controllers/Sites/SiteMealPlanController.php:579`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteMealPlanController.php:344 `$entry->update([`; app/Http/Controllers/Sites/SiteMealPlanController.php:369 `$entry->update([`; responses app/Http/Controllers/Sites/SiteMealPlanController.php:341 `return $this->inertiaOrJson($request, 'Already served');`; app/Http/Controllers/Sites/SiteMealPlanController.php:352 `return $this->inertiaOrJson($request, $count > 0`; app/Http/Controllers/Sites/SiteMealPlanController.php:363 `return $this->inertiaOrJson($request, 'Not served');`; app/Http/Controllers/Sites/SiteMealPlanController.php:374 `return $this->inertiaOrJson($request, $count > 0 ? 'Un-served · stock restored' : 'Marked not served');`; app/Http/Controllers/Sites/SiteMealPlanController.php:589 `return response()->json(['vendors' => $vendors]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/meal-plan/{entry}/serve` — `sites.meals.plan.serve` — `App\Http\Controllers\Sites\SiteMealPlanController@markServed` — `app/Http/Controllers/Sites/SiteMealPlanController.php:335` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `POST sites/{site}/meal-plan/{entry}/unserve` — `sites.meals.plan.unserve` — `App\Http\Controllers\Sites\SiteMealPlanController@unserve` — `app/Http/Controllers/Sites/SiteMealPlanController.php:357` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view, permission:sites.meals.plan`
- `GET|HEAD sites/{site}/meal-planner/takeaway-vendors` — `sites.meals.takeawayVendors` — `App\Http\Controllers\Sites\SiteMealPlanController@takeawayVendors` — `app/Http/Controllers/Sites/SiteMealPlanController.php:579` — middleware `web, auth, verified, permission:sites.viewAny, permission:sites.meals.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteMealPlanController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
