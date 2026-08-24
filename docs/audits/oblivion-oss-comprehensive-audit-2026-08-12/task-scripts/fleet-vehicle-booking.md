# FLEET-VEHICLE-BOOKING: Vehicle Booking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.bookings.approve|fleet.manage`, `permission:fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-VEHICLE-BOOKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/bookings` (`fleet-assets.bookings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.bookings.approve|fleet.manage`, `permission:fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.bookings.approve|fleet.manage`, `permission:fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/bookings` (`fleet-assets.bookings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/bookings/{booking}` (`fleet-assets.bookings.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleBookingController.php:369-379`.
3. Use `GET|HEAD fleet-assets/bookings/create` (`fleet-assets.bookings.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/VehicleBookingController.php:165-172`.
4. Invoke only the owning control for `POST fleet-assets/bookings` (`fleet-assets.bookings.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:309-367`; `asset_id`.
5. Invoke only the owning control for `POST fleet-assets/bookings/{booking}/approve` (`fleet-assets.bookings.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:381-399`; no exact validation fields extracted.
6. Invoke only the owning control for `POST fleet-assets/bookings/{booking}/cancel` (`fleet-assets.bookings.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:473-490`; no exact validation fields extracted.
7. Invoke only the owning control for `POST fleet-assets/bookings/{booking}/checkout` (`fleet-assets.bookings.checkout`, action `checkout`). Source category: **mutation outcome source gap (checkout)**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:425-445`; `odometer_out`.
8. Invoke only the owning control for `POST fleet-assets/bookings/{booking}/reject` (`fleet-assets.bookings.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:401-423`; `rejection_reason`.
9. Invoke only the owning control for `POST fleet-assets/bookings/{booking}/return` (`fleet-assets.bookings.return`, action `returnVehicle`). Source category: **rejected/returned**; controller `app/Http/Controllers/FleetAssets/VehicleBookingController.php:447-471`; `odometer_in`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0712` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0713` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:309`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0714` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:369`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0715` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:381`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-0716` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:473`; it is not runtime-observed.
- **mutation outcome source gap (checkout)** is applicable only to `checkout` / `ROUTE-0717` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:425`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-0718` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:401`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnVehicle` / `ROUTE-0719` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:447`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0720` at `app/Http/Controllers/FleetAssets/VehicleBookingController.php:165`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/bookings/index.tsx`, `resources/js/pages/fleet-assets/bookings/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0713` / `store`: fields `asset_id`; success app/Http/Controllers/FleetAssets/VehicleBookingController.php:366 `->with('success', 'Booking request submitted.');`; failure app/Http/Controllers/FleetAssets/VehicleBookingController.php:331 `return back()->withErrors([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:356 `return back()->withErrors([`.
- `ROUTE-0715` / `approve`: success app/Http/Controllers/FleetAssets/VehicleBookingController.php:398 `return back()->with('success', 'Booking approved.');`.
- `ROUTE-0716` / `cancel`: success app/Http/Controllers/FleetAssets/VehicleBookingController.php:489 `return back()->with('success', 'Booking cancelled.');`.
- `ROUTE-0717` / `checkout`: fields `odometer_out`; success app/Http/Controllers/FleetAssets/VehicleBookingController.php:444 `return back()->with('success', 'Vehicle checked out.');`.
- `ROUTE-0718` / `reject`: fields `rejection_reason`; success app/Http/Controllers/FleetAssets/VehicleBookingController.php:422 `return back()->with('success', 'Booking rejected.');`.
- `ROUTE-0719` / `returnVehicle`: fields `odometer_in`; success app/Http/Controllers/FleetAssets/VehicleBookingController.php:470 `return back()->with('success', 'Vehicle returned.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/FleetAssets/VehicleBookingController.php:331 `return back()->withErrors([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:356 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/VehicleBookingController.php:352 `return FleetVehicleBooking::create($data);`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:386 `$booking->update([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:481 `$booking->update([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:433 `$booking->update([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:409 `$booking->update([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:457 `$booking->update([`; responses app/Http/Controllers/FleetAssets/VehicleBookingController.php:25 `return response()->streamDownload(function () use ($all) {`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:158 `return Inertia::render('fleet-assets/bookings/index', $data);`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:331 `return back()->withErrors([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:347 `return null;`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:352 `return FleetVehicleBooking::create($data);`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:356 `return back()->withErrors([`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:365 `return redirect()->route('fleet-assets.bookings.show', $booking)`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:373 `return Inertia::render('fleet-assets/bookings/show', [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:398 `return back()->with('success', 'Booking approved.');`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:489 `return back()->with('success', 'Booking cancelled.');`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:444 `return back()->with('success', 'Vehicle checked out.');`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:422 `return back()->with('success', 'Booking rejected.');`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:470 `return back()->with('success', 'Vehicle returned.');`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:169 `return redirect()->to('/fleet-assets/bookings?' . http_build_query(`; audit calls app/Http/Controllers/FleetAssets/VehicleBookingController.php:361 `AuditLogger::log('fleet.booking.create', $booking, [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:391 `AuditLogger::log('fleet.booking.approve', $booking, [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:485 `AuditLogger::log('fleet.booking.cancel', $booking, [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:440 `AuditLogger::log('fleet.booking.checkout', $booking, [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:414 `AuditLogger::log('fleet.booking.reject', $booking, [`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:466 `AuditLogger::log('fleet.booking.return', $booking, [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/FleetAssets/VehicleBookingController.php:396 `$booking->user->notify(new FleetBookingApprovedNotification($booking));`; app/Http/Controllers/FleetAssets/VehicleBookingController.php:420 `$booking->user->notify(new FleetBookingRejectedNotification($booking));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD fleet-assets/bookings` — `fleet-assets.bookings.index` — `App\Http\Controllers\FleetAssets\VehicleBookingController@index` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:17` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/bookings` — `fleet-assets.bookings.store` — `App\Http\Controllers\FleetAssets\VehicleBookingController@store` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:309` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/bookings/{booking}` — `fleet-assets.bookings.show` — `App\Http\Controllers\FleetAssets\VehicleBookingController@show` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:369` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/bookings/{booking}/approve` — `fleet-assets.bookings.approve` — `App\Http\Controllers\FleetAssets\VehicleBookingController@approve` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:381` — middleware `web, auth, permission:fleet.bookings.approve|fleet.manage`
- `POST fleet-assets/bookings/{booking}/cancel` — `fleet-assets.bookings.cancel` — `App\Http\Controllers\FleetAssets\VehicleBookingController@cancel` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:473` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/bookings/{booking}/checkout` — `fleet-assets.bookings.checkout` — `App\Http\Controllers\FleetAssets\VehicleBookingController@checkout` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:425` — middleware `web, auth, permission:fleet.manage`
- `POST fleet-assets/bookings/{booking}/reject` — `fleet-assets.bookings.reject` — `App\Http\Controllers\FleetAssets\VehicleBookingController@reject` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:401` — middleware `web, auth, permission:fleet.bookings.approve|fleet.manage`
- `POST fleet-assets/bookings/{booking}/return` — `fleet-assets.bookings.return` — `App\Http\Controllers\FleetAssets\VehicleBookingController@returnVehicle` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:447` — middleware `web, auth, permission:fleet.manage`
- `GET|HEAD fleet-assets/bookings/create` — `fleet-assets.bookings.create` — `App\Http\Controllers\FleetAssets\VehicleBookingController@create` — `app/Http/Controllers/FleetAssets/VehicleBookingController.php:165` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/VehicleBookingController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/bookings/index.tsx`, `resources/js/pages/fleet-assets/bookings/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
