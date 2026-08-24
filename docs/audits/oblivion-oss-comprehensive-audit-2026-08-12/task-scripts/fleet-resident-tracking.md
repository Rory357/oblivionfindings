# FLEET-RESIDENT-TRACKING: Resident Tracking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-RESIDENT-TRACKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/resident-tracking` (`fleet-assets.resident-tracking.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/resident-tracking` (`fleet-assets.resident-tracking.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/resident-tracking/assign` (`fleet-assets.resident-tracking.assign`, action `assignPage`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:327-333`.
3. Use `GET|HEAD fleet-assets/resident-tracking/history/{client}` (`fleet-assets.resident-tracking.history`, action `history`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:462-527`.
4. Invoke only the owning control for `POST fleet-assets/resident-tracking/{client}/acknowledge-panic` (`fleet-assets.resident-tracking.acknowledge-panic`, action `acknowledgePanic`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:572-580`; no exact validation fields extracted.
5. Invoke only the owning control for `POST fleet-assets/resident-tracking/{client}/locate-now` (`fleet-assets.resident-tracking.locate-now`, action `locateNow`). Source category: **mutation outcome source gap (locateNow)**; controller `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:550-570`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/resident-tracking/{device}/unassign` (`fleet-assets.resident-tracking.unassign`, action `unassign`). Source category: **unassigned**; controller `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:454-460`; no exact validation fields extracted.
7. Invoke only the owning control for `POST fleet-assets/resident-tracking/assign` (`fleet-assets.resident-tracking.assign.store`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:416-452`; `tracker_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0814` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:34`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgePanic` / `ROUTE-0815` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:572`; it is not runtime-observed.
- **mutation outcome source gap (locateNow)** is applicable only to `locateNow` / `ROUTE-0816` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:550`; it is not runtime-observed.
- **unassigned** is applicable only to `unassign` / `ROUTE-0817` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:454`; it is not runtime-observed.
- **information presented** is applicable only to `assignPage` / `ROUTE-0818` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:327`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-0819` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:416`; it is not runtime-observed.
- **information presented** is applicable only to `history` / `ROUTE-0820` at `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:462`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/resident-tracking/history.tsx`, `resources/js/pages/fleet-assets/resident-tracking/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0815` / `acknowledgePanic`: success app/Http/Controllers/FleetAssets/ResidentTrackingController.php:579 `return back()->with('success', 'Panic acknowledged.');`.
- `ROUTE-0816` / `locateNow`: success app/Http/Controllers/FleetAssets/ResidentTrackingController.php:569 `return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');`; failure app/Http/Controllers/FleetAssets/ResidentTrackingController.php:562 `throw ValidationException::withMessages([`.
- `ROUTE-0817` / `unassign`: success app/Http/Controllers/FleetAssets/ResidentTrackingController.php:459 `->with('success', 'Tracker unassigned from resident.');`.
- `ROUTE-0819` / `assign`: fields `tracker_id`; success app/Http/Controllers/FleetAssets/ResidentTrackingController.php:451 `->with('success', 'Tracker assigned to resident.');`; failure app/Http/Controllers/FleetAssets/ResidentTrackingController.php:447 `return back()->withErrors(['tracker_id' => $e->getMessage()]);`.

## Failure and recovery paths

- `locateNow`: app/Http/Controllers/FleetAssets/ResidentTrackingController.php:562 `throw ValidationException::withMessages([`.
- `assign`: app/Http/Controllers/FleetAssets/ResidentTrackingController.php:447 `return back()->withErrors(['tracker_id' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/FleetAssets/ResidentTrackingController.php:55 `return false;`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:58 `return $authorizedClientIds === null || in_array($assignment->assignable_id, $authorizedClientIds);`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:93 `return null;`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:98 `return null;`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:101 `return $this->buildResidentPayload($device, $client, $activeOutingClientIds);`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:128 `return [`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:197 `return Inertia::render('fleet-assets/resident-tracking/index', [`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:579 `return back()->with('success', 'Panic acknowledged.');`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:569 `return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:458 `return redirect()->route('fleet-assets.resident-tracking.index', ['new' => 1])`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:329 `return redirect()->route(`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:447 `return back()->withErrors(['tracker_id' => $e->getMessage()]);`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:450 `return redirect()->route('fleet-assets.resident-tracking.index', ['new' => 1])`; app/Http/Controllers/FleetAssets/ResidentTrackingController.php:502 `return Inertia::render('fleet-assets/resident-tracking/history', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/resident-tracking` — `fleet-assets.resident-tracking.index` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@index` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:34` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/resident-tracking/{client}/acknowledge-panic` — `fleet-assets.resident-tracking.acknowledge-panic` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@acknowledgePanic` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:572` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/resident-tracking/{client}/locate-now` — `fleet-assets.resident-tracking.locate-now` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@locateNow` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:550` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/resident-tracking/{device}/unassign` — `fleet-assets.resident-tracking.unassign` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@unassign` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:454` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/resident-tracking/assign` — `fleet-assets.resident-tracking.assign` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@assignPage` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:327` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/resident-tracking/assign` — `fleet-assets.resident-tracking.assign.store` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@assign` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:416` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/resident-tracking/history/{client}` — `fleet-assets.resident-tracking.history` — `App\Http\Controllers\FleetAssets\ResidentTrackingController@history` — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php:462` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`, `resources/js/pages/fleet-assets/resident-tracking/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
