# RESP-RESPITE-BOOKING-REQUEST: Respite Booking Request

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`, `permission:respite.bookings.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-BOOKING-REQUEST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/requests/{request}` (`respite.requests.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`, `permission:respite.bookings.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.create`, `permission:respite.viewAny`, `permission:respite.update`, `permission:respite.bookings.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/requests/{request}` (`respite.requests.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/requests/create` (`respite.requests.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteBookingRequestController.php:57-69`.
3. Invoke only the owning control for `POST respite/requests` (`respite.requests.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteBookingRequestController.php:71-145`; `client_id`, `service_context_id`, `requested_start`, `requested_end`, `requirements`, `intake_snapshot`, `preference_notes`, `referral_id`, `funding_source`.
4. Invoke only the owning control for `PUT respite/requests/{request}` (`respite.requests.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteBookingRequestController.php:162-234`; FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; `requested_start`, `requested_end`, `requirements`, `intake_snapshot`, `preference_notes`, `funding_source`.
5. Invoke only the owning control for `POST respite/requests/{request}/approve` (`respite.requests.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Respite/RespiteBookingRequestController.php:236-331`; FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; `funding_override_reason`.
6. Invoke only the owning control for `POST respite/requests/{request}/promote` (`respite.requests.promote`, action `promote`). Source category: **mutation outcome source gap (promote)**; controller `app/Http/Controllers/Respite/RespiteBookingRequestController.php:333-414`; FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; `location_id`, `capacity_override_reason`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2422` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:71`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2423` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:147`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2424` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:162`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2425` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:236`; it is not runtime-observed.
- **mutation outcome source gap (promote)** is applicable only to `promote` / `ROUTE-2426` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:333`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2427` at `app/Http/Controllers/Respite/RespiteBookingRequestController.php:57`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/requests/create.tsx`, `resources/js/pages/respite/requests/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2422` / `store`: fields `client_id`, `service_context_id`, `requested_start`, `requested_end`, `requirements`, `intake_snapshot`, `preference_notes`, `referral_id`, `funding_source`; success app/Http/Controllers/Respite/RespiteBookingRequestController.php:139 `return back()->with('success', 'Respite booking request submitted.');`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:144 `->with('success', 'Respite booking request submitted.');`.
- `ROUTE-2423` / `show`: FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`.
- `ROUTE-2424` / `update`: FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; fields `requested_start`, `requested_end`, `requirements`, `intake_snapshot`, `preference_notes`, `funding_source`; success app/Http/Controllers/Respite/RespiteBookingRequestController.php:233 `return back()->with('success', 'Booking request updated.');`.
- `ROUTE-2425` / `approve`: FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; fields `funding_override_reason`; success app/Http/Controllers/Respite/RespiteBookingRequestController.php:324 `$redirect = back()->with('success', 'Booking request approved.');`.
- `ROUTE-2426` / `promote`: FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`; fields `location_id`, `capacity_override_reason`; success app/Http/Controllers/Respite/RespiteBookingRequestController.php:413 `return back()->with('success', 'Waitlisted request promoted.');`; failure app/Http/Controllers/Respite/RespiteBookingRequestController.php:344 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `promote`: app/Http/Controllers/Respite/RespiteBookingRequestController.php:344 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteBookingRequestController.php:106 `$requestModel = RespiteBookingRequest::create($validated);`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:115 `$referral->update([`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:225 `$request->update($validated);`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:249 `$request->update([`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:298 `$booking->save();`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:352 `$request->update([`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:396 `$booking->save();`; responses app/Http/Controllers/Respite/RespiteBookingRequestController.php:139 `return back()->with('success', 'Respite booking request submitted.');`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:142 `return redirect()`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:156 `return Inertia::render('respite/requests/show', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:233 `return back()->with('success', 'Booking request updated.');`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:309 `return $booking;`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:330 `return $redirect;`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:404 `return $booking;`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:413 `return back()->with('success', 'Waitlisted request promoted.');`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:59 `return Inertia::render('respite/requests/create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteBookingRequestController.php:121 `event(new RespiteEvent('respite.referral.updated', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:129 `event(new RespiteEvent('respite.booking_request.submitted', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:227 `event(new RespiteEvent('respite.booking_request.updated', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:312 `event(new RespiteEvent('respite.booking_request.approved', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:318 `event(new RespiteEvent('respite.booking.created', [`; app/Http/Controllers/Respite/RespiteBookingRequestController.php:407 `event(new RespiteEvent('respite.booking_request.promoted', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/requests` — `respite.requests.store` — `App\Http\Controllers\Respite\RespiteBookingRequestController@store` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:71` — middleware `web, auth, permission:respite.create`
- `GET|HEAD respite/requests/{request}` — `respite.requests.show` — `App\Http\Controllers\Respite\RespiteBookingRequestController@show` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:147` — middleware `web, auth, permission:respite.viewAny`
- `PUT respite/requests/{request}` — `respite.requests.update` — `App\Http\Controllers\Respite\RespiteBookingRequestController@update` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:162` — middleware `web, auth, permission:respite.update`
- `POST respite/requests/{request}/approve` — `respite.requests.approve` — `App\Http\Controllers\Respite\RespiteBookingRequestController@approve` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:236` — middleware `web, auth, permission:respite.bookings.manage`
- `POST respite/requests/{request}/promote` — `respite.requests.promote` — `App\Http\Controllers\Respite\RespiteBookingRequestController@promote` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:333` — middleware `web, auth, permission:respite.bookings.manage`
- `GET|HEAD respite/requests/create` — `respite.requests.create` — `App\Http\Controllers\Respite\RespiteBookingRequestController@create` — `app/Http/Controllers/Respite/RespiteBookingRequestController.php:57` — middleware `web, auth, permission:respite.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteBookingRequestController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/requests/create.tsx`, `resources/js/pages/respite/requests/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
