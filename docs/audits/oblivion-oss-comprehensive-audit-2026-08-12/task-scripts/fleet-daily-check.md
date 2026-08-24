# FLEET-DAILY-CHECK: Daily Check

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-DAILY-CHECK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/daily-check` (`fleet-assets.daily-check.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/daily-check` (`fleet-assets.daily-check.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST fleet-assets/daily-check` (`fleet-assets.daily-check.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/DailyCheckController.php:68-124`; `asset_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0722` at `app/Http/Controllers/FleetAssets/DailyCheckController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0723` at `app/Http/Controllers/FleetAssets/DailyCheckController.php:68`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/daily-check.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0723` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/DailyCheckController.php:123 `return back()->with('success', 'Daily check recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/DailyCheckController.php:77 `$template = FleetChecklistTemplate::firstOrCreate(`; app/Http/Controllers/FleetAssets/DailyCheckController.php:100 `$existing->update([`; app/Http/Controllers/FleetAssets/DailyCheckController.php:110 `FleetChecklistRun::create([`; responses app/Http/Controllers/FleetAssets/DailyCheckController.php:43 `return [`; app/Http/Controllers/FleetAssets/DailyCheckController.php:58 `return Inertia::render('fleet-assets/daily-check', [`; app/Http/Controllers/FleetAssets/DailyCheckController.php:123 `return back()->with('success', 'Daily check recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/daily-check` — `fleet-assets.daily-check.index` — `App\Http\Controllers\FleetAssets\DailyCheckController@index` — `app/Http/Controllers/FleetAssets/DailyCheckController.php:15` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`
- `POST fleet-assets/daily-check` — `fleet-assets.daily-check.store` — `App\Http\Controllers\FleetAssets\DailyCheckController@store` — `app/Http/Controllers/FleetAssets/DailyCheckController.php:68` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/DailyCheckController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/daily-check.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
