# RESP-RESPITE-BOOKING: Respite Booking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.bookings.manage`, `permission:respite.viewAny`, `permission:respite.update`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-BOOKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/bookings/{booking}` (`respite.bookings.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.bookings.manage`, `permission:respite.viewAny`, `permission:respite.update`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.bookings.manage`, `permission:respite.viewAny`, `permission:respite.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/bookings/{booking}` (`respite.bookings.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/bookings/create` (`respite.bookings.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteBookingController.php:43-54`.
3. Invoke only the owning control for `POST respite/bookings` (`respite.bookings.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteBookingController.php:56-148`; `booking_request_id`, `client_id`, `start_at`, `end_at`, `assigned_coordinator_id`, `location_id`, `funding_source`.
4. Invoke only the owning control for `PUT respite/bookings/{booking}` (`respite.bookings.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteBookingController.php:161-225`; `start_at`, `end_at`.
5. Invoke only the owning control for `POST respite/bookings/{booking}/confirm` (`respite.bookings.confirm`, action `confirm`). Source category: **mutation outcome source gap (confirm)**; controller `app/Http/Controllers/Respite/RespiteBookingController.php:227-284`; `capacity_override_reason`, `readiness_override_reason`, `service_agreement_id`, `consent_authority`, `consent_authority_name`, `consent_authority_contact`, `consent_authority_evidence`, `code_of_rights_provided`, `consent_to_respite`, `consent_capacity_basis`, `advocate_offered`, `rights_format_provided`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2365` at `app/Http/Controllers/Respite/RespiteBookingController.php:56`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2366` at `app/Http/Controllers/Respite/RespiteBookingController.php:150`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2367` at `app/Http/Controllers/Respite/RespiteBookingController.php:161`; it is not runtime-observed.
- **mutation outcome source gap (confirm)** is applicable only to `confirm` / `ROUTE-2368` at `app/Http/Controllers/Respite/RespiteBookingController.php:227`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2369` at `app/Http/Controllers/Respite/RespiteBookingController.php:43`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/bookings/create.tsx`, `resources/js/pages/respite/bookings/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2365` / `store`: fields `booking_request_id`, `client_id`, `start_at`, `end_at`, `assigned_coordinator_id`, `location_id`, `funding_source`; success app/Http/Controllers/Respite/RespiteBookingController.php:147 `->with('success', 'Respite booking created.');`; failure app/Http/Controllers/Respite/RespiteBookingController.php:100 `throw ValidationException::withMessages([`.
- `ROUTE-2367` / `update`: fields `start_at`, `end_at`; success app/Http/Controllers/Respite/RespiteBookingController.php:224 `return back()->with('success', 'Booking updated.');`.
- `ROUTE-2368` / `confirm`: fields `capacity_override_reason`, `readiness_override_reason`, `service_agreement_id`, `consent_authority`, `consent_authority_name`, `consent_authority_contact`, `consent_authority_evidence`, `code_of_rights_provided`, `consent_to_respite`, `consent_capacity_basis`, `advocate_offered`, `rights_format_provided`; success app/Http/Controllers/Respite/RespiteBookingController.php:283 `return back()->with('success', 'Booking confirmed.');`.
- `ROUTE-2369` / `create`: FormRequest `app/Models/RespiteBookingRequest.php:line unresolved`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Respite/RespiteBookingController.php:100 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteBookingController.php:132 `$booking = RespiteBooking::create($validated);`; app/Http/Controllers/Respite/RespiteBookingController.php:214 `$booking->update($validated);`; app/Http/Controllers/Respite/RespiteBookingController.php:267 `$booking->update([`; responses app/Http/Controllers/Respite/RespiteBookingController.php:136 `return $booking;`; app/Http/Controllers/Respite/RespiteBookingController.php:145 `return redirect()`; app/Http/Controllers/Respite/RespiteBookingController.php:155 `return Inertia::render('respite/bookings/show', [`; app/Http/Controllers/Respite/RespiteBookingController.php:224 `return back()->with('success', 'Booking updated.');`; app/Http/Controllers/Respite/RespiteBookingController.php:283 `return back()->with('success', 'Booking confirmed.');`; app/Http/Controllers/Respite/RespiteBookingController.php:45 `return Inertia::render('respite/bookings/create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteBookingController.php:139 `event(new RespiteEvent('respite.booking.created', [`; app/Http/Controllers/Respite/RespiteBookingController.php:218 `event(new RespiteEvent('respite.booking.updated', [`; app/Http/Controllers/Respite/RespiteBookingController.php:277 `event(new RespiteEvent('respite.booking.confirmed', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/bookings` — `respite.bookings.store` — `App\Http\Controllers\Respite\RespiteBookingController@store` — `app/Http/Controllers/Respite/RespiteBookingController.php:56` — middleware `web, auth, permission:respite.bookings.manage`
- `GET|HEAD respite/bookings/{booking}` — `respite.bookings.show` — `App\Http\Controllers\Respite\RespiteBookingController@show` — `app/Http/Controllers/Respite/RespiteBookingController.php:150` — middleware `web, auth, permission:respite.viewAny`
- `PUT respite/bookings/{booking}` — `respite.bookings.update` — `App\Http\Controllers\Respite\RespiteBookingController@update` — `app/Http/Controllers/Respite/RespiteBookingController.php:161` — middleware `web, auth, permission:respite.update`
- `POST respite/bookings/{booking}/confirm` — `respite.bookings.confirm` — `App\Http\Controllers\Respite\RespiteBookingController@confirm` — `app/Http/Controllers/Respite/RespiteBookingController.php:227` — middleware `web, auth, permission:respite.bookings.manage`
- `GET|HEAD respite/bookings/create` — `respite.bookings.create` — `App\Http\Controllers\Respite\RespiteBookingController@create` — `app/Http/Controllers/Respite/RespiteBookingController.php:43` — middleware `web, auth, permission:respite.bookings.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteBookingController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/bookings/create.tsx`, `resources/js/pages/respite/bookings/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
