# OPS-CLIENT-TRANSPORT-BOOKING: Client Transport Booking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-TRANSPORT-BOOKING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/clients/{client}/transport-bookings` (`operations.clients.transport-bookings.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientTransportBookingController.php:20-46`; `purpose`.
3. Invoke only the owning control for `DELETE operations/clients/{client}/transport-bookings/{booking}` (`operations.clients.transport-bookings.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ClientTransportBookingController.php:88-96`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/clients/{client}/transport-bookings/{booking}` (`operations.clients.transport-bookings.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientTransportBookingController.php:62-86`; `purpose`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2050` at `app/Http/Controllers/Operations/ClientTransportBookingController.php:20`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2051` at `app/Http/Controllers/Operations/ClientTransportBookingController.php:88`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2052` at `app/Http/Controllers/Operations/ClientTransportBookingController.php:62`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2050` / `store`: fields `purpose`; success app/Http/Controllers/Operations/ClientTransportBookingController.php:45 `return back()->with('success', 'Transport booked.');`.
- `ROUTE-2051` / `destroy`: success app/Http/Controllers/Operations/ClientTransportBookingController.php:95 `return back()->with('success', 'Transport booking removed.');`.
- `ROUTE-2052` / `update`: fields `purpose`; success app/Http/Controllers/Operations/ClientTransportBookingController.php:85 `return back()->with('success', 'Transport booking updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientTransportBookingController.php:37 `ClientTransportBooking::create([`; app/Http/Controllers/Operations/ClientTransportBookingController.php:93 `$booking->delete();`; app/Http/Controllers/Operations/ClientTransportBookingController.php:83 `$booking->update($data);`; responses app/Http/Controllers/Operations/ClientTransportBookingController.php:45 `return back()->with('success', 'Transport booked.');`; app/Http/Controllers/Operations/ClientTransportBookingController.php:95 `return back()->with('success', 'Transport booking removed.');`; app/Http/Controllers/Operations/ClientTransportBookingController.php:85 `return back()->with('success', 'Transport booking updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/transport-bookings` — `operations.clients.transport-bookings.store` — `App\Http\Controllers\Operations\ClientTransportBookingController@store` — `app/Http/Controllers/Operations/ClientTransportBookingController.php:20` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/transport-bookings/{booking}` — `operations.clients.transport-bookings.destroy` — `App\Http\Controllers\Operations\ClientTransportBookingController@destroy` — `app/Http/Controllers/Operations/ClientTransportBookingController.php:88` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/transport-bookings/{booking}` — `operations.clients.transport-bookings.update` — `App\Http\Controllers\Operations\ClientTransportBookingController@update` — `app/Http/Controllers/Operations/ClientTransportBookingController.php:62` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientTransportBookingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
