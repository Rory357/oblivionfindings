# HR-ICAL: ICal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-ICAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/ical/{token}` (`hr.ical.feed`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `throttle:60,1`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/ical/{token}` (`hr.ical.feed`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/ical/token` (`hr.ical.token`, action `generateToken`). Source category: **mutation outcome source gap (generateToken)**; controller `app/Http/Controllers/Hr/ICalController.php:120-131`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `feed` / `ROUTE-1480` at `app/Http/Controllers/Hr/ICalController.php:19`; it is not runtime-observed.
- **mutation outcome source gap (generateToken)** is applicable only to `generateToken` / `ROUTE-1481` at `app/Http/Controllers/Hr/ICalController.php:120`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1481` / `generateToken`: success app/Http/Controllers/Hr/ICalController.php:130 `return redirect()->back()->with('success', 'Calendar feed URL generated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/ICalController.php:125 `$token = HrICalToken::updateOrCreate(`; responses app/Http/Controllers/Hr/ICalController.php:114 `return response($ical, 200, [`; app/Http/Controllers/Hr/ICalController.php:130 `return redirect()->back()->with('success', 'Calendar feed URL generated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/ical/{token}` — `hr.ical.feed` — `App\Http\Controllers\Hr\ICalController@feed` — `app/Http/Controllers/Hr/ICalController.php:19` — middleware `web, throttle:60,1`
- `POST hr/ical/token` — `hr.ical.token` — `App\Http\Controllers\Hr\ICalController@generateToken` — `app/Http/Controllers/Hr/ICalController.php:120` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ICalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
