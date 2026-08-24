# CLI-CLIENT-CALENDAR: Client Calendar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:calendar.create`, `permission:calendar.manage`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/calendar/events` (`client.calendar.events`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:calendar.create`, `permission:calendar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:calendar.create`, `permission:calendar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/calendar/events` (`client.calendar.events`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST clients/{client}/calendar/appointments` (`client.calendar.appointments.store`, action `storeAppointment`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientCalendarController.php:305-331`; `title`, `description`, `appointment_type`, `starts_at`, `ends_at`, `location`, `provider_name`, `share_with_family`.
3. Invoke only the owning control for `DELETE clients/{client}/calendar/appointments/{appointment}` (`client.calendar.appointments.destroy`, action `destroyAppointment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ClientCalendarController.php:373-382`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT clients/{client}/calendar/appointments/{appointment}` (`client.calendar.appointments.update`, action `updateAppointment`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientCalendarController.php:333-371`; `title`, `description`, `appointment_type`, `starts_at`, `ends_at`, `location`, `provider_name`, `share_with_family`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeAppointment` / `ROUTE-0139` at `app/Http/Controllers/ClientCalendarController.php:305`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAppointment` / `ROUTE-0140` at `app/Http/Controllers/ClientCalendarController.php:373`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAppointment` / `ROUTE-0141` at `app/Http/Controllers/ClientCalendarController.php:333`; it is not runtime-observed.
- **information presented** is applicable only to `events` / `ROUTE-0142` at `app/Http/Controllers/ClientCalendarController.php:25`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0139` / `storeAppointment`: fields `title`, `description`, `appointment_type`, `starts_at`, `ends_at`, `location`, `provider_name`, `share_with_family`.
- `ROUTE-0141` / `updateAppointment`: fields `title`, `description`, `appointment_type`, `starts_at`, `ends_at`, `location`, `provider_name`, `share_with_family`; failure app/Http/Controllers/ClientCalendarController.php:363 `throw ValidationException::withMessages([`.
- `ROUTE-0142` / `events`: fields `start`; failure app/Http/Controllers/ClientCalendarController.php:47 `throw ValidationException::withMessages([`; app/Http/Controllers/ClientCalendarController.php:52 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `updateAppointment`: app/Http/Controllers/ClientCalendarController.php:363 `throw ValidationException::withMessages([`.
- `events`: app/Http/Controllers/ClientCalendarController.php:47 `throw ValidationException::withMessages([`; app/Http/Controllers/ClientCalendarController.php:52 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientCalendarController.php:321 `$appointment = ClientAppointment::create([`; app/Http/Controllers/ClientCalendarController.php:379 `$appointment->delete();`; app/Http/Controllers/ClientCalendarController.php:368 `$appointment->update($data);`; responses app/Http/Controllers/ClientCalendarController.php:330 `return response()->json(['success' => true, 'appointment' => $appointment]);`; app/Http/Controllers/ClientCalendarController.php:381 `return response()->json(['success' => true]);`; app/Http/Controllers/ClientCalendarController.php:370 `return response()->json(['success' => true, 'appointment' => $appointment->fresh()]);`; app/Http/Controllers/ClientCalendarController.php:274 `return $ma->client_medication_id === $med->id`; app/Http/Controllers/ClientCalendarController.php:302 `return response()->json($events->values());`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/calendar/appointments` — `client.calendar.appointments.store` — `App\Http\Controllers\ClientCalendarController@storeAppointment` — `app/Http/Controllers/ClientCalendarController.php:305` — middleware `web, auth, permission:calendar.create`
- `DELETE clients/{client}/calendar/appointments/{appointment}` — `client.calendar.appointments.destroy` — `App\Http\Controllers\ClientCalendarController@destroyAppointment` — `app/Http/Controllers/ClientCalendarController.php:373` — middleware `web, auth, permission:calendar.manage`
- `PUT clients/{client}/calendar/appointments/{appointment}` — `client.calendar.appointments.update` — `App\Http\Controllers\ClientCalendarController@updateAppointment` — `app/Http/Controllers/ClientCalendarController.php:333` — middleware `web, auth, permission:calendar.manage`
- `GET|HEAD clients/{client}/calendar/events` — `client.calendar.events` — `App\Http\Controllers\ClientCalendarController@events` — `app/Http/Controllers/ClientCalendarController.php:25` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientCalendarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
