# CLI-PORTAL-CALENDAR: Portal Calendar

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-CALENDAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/calendar` (`portal.clients.calendar`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/calendar` (`portal.clients.calendar`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD portal/clients/{client}/calendar/events` (`portal.clients.calendar.events`, action `events`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Portal/PortalCalendarController.php:51-265`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2246` at `app/Http/Controllers/Portal/PortalCalendarController.php:19`; it is not runtime-observed.
- **information presented** is applicable only to `events` / `ROUTE-2247` at `app/Http/Controllers/Portal/PortalCalendarController.php:51`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/calendar.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2246` / `index`: FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`.
- `ROUTE-2247` / `events`: fields `start`; failure app/Http/Controllers/Portal/PortalCalendarController.php:73 `throw ValidationException::withMessages([`; app/Http/Controllers/Portal/PortalCalendarController.php:78 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `events`: app/Http/Controllers/Portal/PortalCalendarController.php:73 `throw ValidationException::withMessages([`; app/Http/Controllers/Portal/PortalCalendarController.php:78 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/calendar` — `portal.clients.calendar` — `App\Http\Controllers\Portal\PortalCalendarController@index` — `app/Http/Controllers/Portal/PortalCalendarController.php:19` — middleware `web, auth`
- `GET|HEAD portal/clients/{client}/calendar/events` — `portal.clients.calendar.events` — `App\Http\Controllers\Portal\PortalCalendarController@events` — `app/Http/Controllers/Portal/PortalCalendarController.php:51` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalCalendarController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/calendar.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
