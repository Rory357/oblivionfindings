# FLEET-FLEET-TRIP: Fleet Trip

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny`, `permission:fleet.trips.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-FLEET-TRIP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/trips/{trip}/playback` (`fleet-assets.trips.playback`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny`, `permission:fleet.trips.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny`, `permission:fleet.trips.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/trips/{trip}/playback` (`fleet-assets.trips.playback`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/trips/{trip}/playback/data` (`fleet-assets.trips.playback.data`, action `playback`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Fleet/FleetTripController.php:73-101`.
3. Invoke only the owning control for `DELETE fleet/trips/{trip}` (`fleet.trips.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Fleet/FleetTripController.php:145-158`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT fleet/trips/{trip}` (`fleet.trips.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Fleet/FleetTripController.php:103-122`; `driver_session_id`.
5. Invoke only the owning control for `POST fleet/trips/{trip}/close` (`fleet.trips.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Fleet/FleetTripController.php:124-143`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0832` at `app/Http/Controllers/Fleet/FleetTripController.php:15`; it is not runtime-observed.
- **information presented** is applicable only to `playback` / `ROUTE-0833` at `app/Http/Controllers/Fleet/FleetTripController.php:73`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0846` at `app/Http/Controllers/Fleet/FleetTripController.php:145`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0848` at `app/Http/Controllers/Fleet/FleetTripController.php:103`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-0849` at `app/Http/Controllers/Fleet/FleetTripController.php:124`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/trips/playback.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0846` / `destroy`: success app/Http/Controllers/Fleet/FleetTripController.php:157 `return redirect()->route('fleet-assets.trips.index')->with('success', 'Trip deleted.');`.
- `ROUTE-0848` / `update`: fields `driver_session_id`; success app/Http/Controllers/Fleet/FleetTripController.php:121 `return back()->with('success', 'Trip updated.');`.
- `ROUTE-0849` / `close`: success app/Http/Controllers/Fleet/FleetTripController.php:142 `return back()->with('success', 'Trip closed.');`; failure app/Http/Controllers/Fleet/FleetTripController.php:130 `return back()->withErrors(['trip' => 'Trip is already closed.']);`.

## Failure and recovery paths

- `close`: app/Http/Controllers/Fleet/FleetTripController.php:130 `return back()->withErrors(['trip' => 'Trip is already closed.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Fleet/FleetTripController.php:155 `$trip->delete();`; app/Http/Controllers/Fleet/FleetTripController.php:114 `$trip->update($data);`; app/Http/Controllers/Fleet/FleetTripController.php:133 `$trip->update([`; responses app/Http/Controllers/Fleet/FleetTripController.php:33 `return Inertia::render('fleet-assets/trips/playback', [`; app/Http/Controllers/Fleet/FleetTripController.php:92 `return response()->json([`; app/Http/Controllers/Fleet/FleetTripController.php:157 `return redirect()->route('fleet-assets.trips.index')->with('success', 'Trip deleted.');`; app/Http/Controllers/Fleet/FleetTripController.php:121 `return back()->with('success', 'Trip updated.');`; app/Http/Controllers/Fleet/FleetTripController.php:130 `return back()->withErrors(['trip' => 'Trip is already closed.']);`; app/Http/Controllers/Fleet/FleetTripController.php:142 `return back()->with('success', 'Trip closed.');`; audit calls app/Http/Controllers/Fleet/FleetTripController.php:150 `AuditLogger::log('fleet.trip.delete', $trip, [`; app/Http/Controllers/Fleet/FleetTripController.php:116 `AuditLogger::log('fleet.trip.update', $trip, [`; app/Http/Controllers/Fleet/FleetTripController.php:138 `AuditLogger::log('fleet.trip.close', $trip, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD fleet-assets/trips/{trip}/playback` — `fleet-assets.trips.playback` — `App\Http\Controllers\Fleet\FleetTripController@show` — `app/Http/Controllers/Fleet/FleetTripController.php:15` — middleware `web, auth, permission:fleet.viewAny`
- `GET|HEAD fleet-assets/trips/{trip}/playback/data` — `fleet-assets.trips.playback.data` — `App\Http\Controllers\Fleet\FleetTripController@playback` — `app/Http/Controllers/Fleet/FleetTripController.php:73` — middleware `web, auth, permission:fleet.viewAny`
- `DELETE fleet/trips/{trip}` — `fleet.trips.destroy` — `App\Http\Controllers\Fleet\FleetTripController@destroy` — `app/Http/Controllers/Fleet/FleetTripController.php:145` — middleware `web, auth, permission:fleet.trips.manage`
- `PUT fleet/trips/{trip}` — `fleet.trips.update` — `App\Http\Controllers\Fleet\FleetTripController@update` — `app/Http/Controllers/Fleet/FleetTripController.php:103` — middleware `web, auth, permission:fleet.trips.manage`
- `POST fleet/trips/{trip}/close` — `fleet.trips.close` — `App\Http\Controllers\Fleet\FleetTripController@close` — `app/Http/Controllers/Fleet/FleetTripController.php:124` — middleware `web, auth, permission:fleet.trips.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Fleet/FleetTripController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/trips/playback.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
