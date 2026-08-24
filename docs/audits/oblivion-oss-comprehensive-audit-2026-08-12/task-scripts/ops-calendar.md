# OPS-CALENDAR: Calendar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`, `permission:shifts.create`, `permission:shifts.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/rostering/calendar/events` (`operations.rostering.calendar.events`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`, `permission:shifts.create`, `permission:shifts.update`.
- Exact middleware atoms: `web`, `auth`, `role_scope:my-day`, `permission:rostering.viewAny`, `permission:shifts.create`, `permission:shifts.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/rostering/calendar/events` (`operations.rostering.calendar.events`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/rostering/calendar/shifts` (`operations.rostering.calendar.shifts.store`, action `storeShift`). Source category: **created/recorded**; controller `app/Http/Controllers/CalendarController.php:212-330`; `client_id`.
3. Invoke only the owning control for `PATCH operations/rostering/calendar/shifts/{shift}` (`operations.rostering.calendar.shifts.update`, action `updateShift`). Source category: **updated/revised**; controller `app/Http/Controllers/CalendarController.php:332-524`; `client_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `events` / `ROUTE-2143` at `app/Http/Controllers/CalendarController.php:35`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeShift` / `ROUTE-2144` at `app/Http/Controllers/CalendarController.php:212`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateShift` / `ROUTE-2145` at `app/Http/Controllers/CalendarController.php:332`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2143` / `events`: fields `start`.
- `ROUTE-2144` / `storeShift`: fields `client_id`; failure app/Http/Controllers/CalendarController.php:311 `throw $e;`.
- `ROUTE-2145` / `updateShift`: fields `client_id`; failure app/Http/Controllers/CalendarController.php:339 `abort(403);`; app/Http/Controllers/CalendarController.php:343 `abort(422, 'This shift is locked and can no longer be edited from the calendar.');`; app/Http/Controllers/CalendarController.php:382 `abort(422, 'In-progress shifts cannot be unassigned from the calendar. Use the replacement workflow instead.');`; app/Http/Controllers/CalendarController.php:496 `throw $e;`.

## Failure and recovery paths

- `storeShift`: app/Http/Controllers/CalendarController.php:311 `throw $e;`.
- `updateShift`: app/Http/Controllers/CalendarController.php:339 `abort(403);`; app/Http/Controllers/CalendarController.php:343 `abort(422, 'This shift is locked and can no longer be edited from the calendar.');`; app/Http/Controllers/CalendarController.php:382 `abort(422, 'In-progress shifts cannot be unassigned from the calendar. Use the replacement workflow instead.');`; app/Http/Controllers/CalendarController.php:496 `throw $e;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/CalendarController.php:295 `$shift = Shift::create([`; app/Http/Controllers/CalendarController.php:485 `$shift->update(Arr::except($data, ['tasks', 'coverage_reservation_token', 'coverage_rule_id']));`; responses app/Http/Controllers/CalendarController.php:107 `return [$shift->id => $match];`; app/Http/Controllers/CalendarController.php:118 `return [`; app/Http/Controllers/CalendarController.php:209 `return response()->json($shiftEvents->concat($coverageGapEvents)->values());`; app/Http/Controllers/CalendarController.php:307 `return $shift;`; app/Http/Controllers/CalendarController.php:326 `return response()->json([`; app/Http/Controllers/CalendarController.php:520 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/rostering/calendar/events` — `operations.rostering.calendar.events` — `App\Http\Controllers\CalendarController@events` — `app/Http/Controllers/CalendarController.php:35` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny`
- `POST operations/rostering/calendar/shifts` — `operations.rostering.calendar.shifts.store` — `App\Http\Controllers\CalendarController@storeShift` — `app/Http/Controllers/CalendarController.php:212` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny, permission:shifts.create`
- `PATCH operations/rostering/calendar/shifts/{shift}` — `operations.rostering.calendar.shifts.update` — `App\Http\Controllers\CalendarController@updateShift` — `app/Http/Controllers/CalendarController.php:332` — middleware `web, auth, role_scope:my-day, permission:rostering.viewAny, permission:shifts.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/CalendarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
