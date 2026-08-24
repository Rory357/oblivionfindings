# SITE-DASHBOARD: Dashboard

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.meals.view`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-DASHBOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `catering` (`catering.meal-planner`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.meals.view`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.meals.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD catering` (`catering.meal-planner`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD catering/library-counts` (`catering.library-counts`, action `libraryCounts`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Catering/DashboardController.php:18-25`.

## Source-applicable states and transitions

- **information presented** is applicable only to `mealPlanner` / `ROUTE-0097` at `app/Http/Controllers/Catering/DashboardController.php:32`; it is not runtime-observed.
- **information presented** is applicable only to `libraryCounts` / `ROUTE-0098` at `app/Http/Controllers/Catering/DashboardController.php:18`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/catering/meal-planner.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD catering` — `catering.meal-planner` — `App\Http\Controllers\Catering\DashboardController@mealPlanner` — `app/Http/Controllers/Catering/DashboardController.php:32` — middleware `web, auth, verified, permission:sites.meals.view`
- `GET|HEAD catering/library-counts` — `catering.library-counts` — `App\Http\Controllers\Catering\DashboardController@libraryCounts` — `app/Http/Controllers/Catering/DashboardController.php:18` — middleware `web, auth, verified, permission:sites.meals.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Catering/DashboardController.php`.
- Exact render/action page relationships: `resources/js/pages/catering/meal-planner.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
