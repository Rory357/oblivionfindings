# FLEET-DRIVER: Driver

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|hr.driver.view`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-DRIVER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/drivers` (`fleet-assets.drivers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|hr.driver.view`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|hr.driver.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/drivers` (`fleet-assets.drivers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/drivers/{user}` (`fleet-assets.drivers.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/DriverController.php:154-225`.
3. Use `GET|HEAD fleet-assets/drivers/{user}/scorecard` (`fleet-assets.drivers.scorecard`, action `scorecard`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/DriverController.php:231-239`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0731` at `app/Http/Controllers/FleetAssets/DriverController.php:17`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0732` at `app/Http/Controllers/FleetAssets/DriverController.php:154`; it is not runtime-observed.
- **information presented** is applicable only to `scorecard` / `ROUTE-0733` at `app/Http/Controllers/FleetAssets/DriverController.php:231`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/drivers/index.tsx`, `resources/js/pages/fleet-assets/drivers/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/drivers` — `fleet-assets.drivers.index` — `App\Http\Controllers\FleetAssets\DriverController@index` — `app/Http/Controllers/FleetAssets/DriverController.php:17` — middleware `web, auth, permission:fleet.viewAny|hr.driver.view`
- `GET|HEAD fleet-assets/drivers/{user}` — `fleet-assets.drivers.show` — `App\Http\Controllers\FleetAssets\DriverController@show` — `app/Http/Controllers/FleetAssets/DriverController.php:154` — middleware `web, auth, permission:fleet.viewAny|hr.driver.view`
- `GET|HEAD fleet-assets/drivers/{user}/scorecard` — `fleet-assets.drivers.scorecard` — `App\Http\Controllers\FleetAssets\DriverController@scorecard` — `app/Http/Controllers/FleetAssets/DriverController.php:231` — middleware `web, auth, permission:fleet.viewAny|hr.driver.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/DriverController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/drivers/index.tsx`, `resources/js/pages/fleet-assets/drivers/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
