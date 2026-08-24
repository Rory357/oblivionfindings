# CAP-OPS-CLIENT-LOCATION-PANIC: Client location history locate-now and panic response

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:fleet.manage|assets.trackers.manage`, `permission:assets.telemetry.view`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/location/history` (`operations.clients.location.history`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:fleet.manage|assets.trackers.manage`, `permission:assets.telemetry.view`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:fleet.manage|assets.trackers.manage`, `permission:assets.telemetry.view`, `permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/location/history` (`operations.clients.location.history`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/location/acknowledge-panic` (`operations.clients.location.acknowledge-panic`, action `acknowledgePanic`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/ClientController.php:3583-3620`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/clients/{client}/location/locate-now` (`operations.clients.location.locate-now`, action `locateNow`). Source category: **mutation outcome source gap (locateNow)**; controller `app/Http/Controllers/ClientController.php:3556-3581`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledgePanic` / `ROUTE-2002` at `app/Http/Controllers/ClientController.php:3583`; it is not runtime-observed.
- **information presented** is applicable only to `locationHistory` / `ROUTE-2003` at `app/Http/Controllers/ClientController.php:3532`; it is not runtime-observed.
- **mutation outcome source gap (locateNow)** is applicable only to `locateNow` / `ROUTE-2004` at `app/Http/Controllers/ClientController.php:3556`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2002` / `acknowledgePanic`: success app/Http/Controllers/ClientController.php:3619 `return back()->with('success', 'Panic acknowledged.');`.
- `ROUTE-2004` / `locateNow`: success app/Http/Controllers/ClientController.php:3580 `return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');`; failure app/Http/Controllers/ClientController.php:3573 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `locateNow`: app/Http/Controllers/ClientController.php:3573 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientController.php:3604 `$device->forceFill(['meta' => $meta])->save();`; app/Http/Controllers/ClientController.php:3612 `->update([`; responses app/Http/Controllers/ClientController.php:3619 `return back()->with('success', 'Panic acknowledged.');`; app/Http/Controllers/ClientController.php:3553 `return response()->json(['locations' => $locations]);`; app/Http/Controllers/ClientController.php:3580 `return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/location/acknowledge-panic` — `operations.clients.location.acknowledge-panic` — `App\Http\Controllers\ClientController@acknowledgePanic` — `app/Http/Controllers/ClientController.php:3583` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:fleet.manage|assets.trackers.manage`
- `GET|HEAD operations/clients/{client}/location/history` — `operations.clients.location.history` — `App\Http\Controllers\ClientController@locationHistory` — `app/Http/Controllers/ClientController.php:3532` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:assets.telemetry.view, permission:fleet.viewAny|assets.viewAny|assets.viewAssigned`
- `POST operations/clients/{client}/location/locate-now` — `operations.clients.location.locate-now` — `App\Http\Controllers\ClientController@locateNow` — `app/Http/Controllers/ClientController.php:3556` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:fleet.manage|assets.trackers.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
