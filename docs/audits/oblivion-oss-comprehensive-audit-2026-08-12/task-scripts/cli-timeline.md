# CLI-TIMELINE: Timeline

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-TIMELINE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/timeline` (`timeline.client`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/timeline` (`timeline.client`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/timeline` (`operations.timeline`, action `my`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/TimelineController.php:18-24`.
3. Use `GET|HEAD staff/{user}/timeline` (`timeline.staff`, action `staff`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/TimelineController.php:26-59`.
4. Use `GET|HEAD timeline` (`timeline.my`, action `my`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/TimelineController.php:18-24`.

## Source-applicable states and transitions

- **information presented** is applicable only to `client` / `ROUTE-0195` at `app/Http/Controllers/TimelineController.php:61`; it is not runtime-observed.
- **information presented** is applicable only to `my` / `ROUTE-2220` at `app/Http/Controllers/TimelineController.php:18`; it is not runtime-observed.
- **information presented** is applicable only to `staff` / `ROUTE-2934` at `app/Http/Controllers/TimelineController.php:26`; it is not runtime-observed.
- **information presented** is applicable only to `my` / `ROUTE-2983` at `app/Http/Controllers/TimelineController.php:18`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/timeline/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/timeline` — `timeline.client` — `App\Http\Controllers\TimelineController@client` — `app/Http/Controllers/TimelineController.php:61` — middleware `web, auth`
- `GET|HEAD operations/timeline` — `operations.timeline` — `App\Http\Controllers\TimelineController@my` — `app/Http/Controllers/TimelineController.php:18` — middleware `web, auth`
- `GET|HEAD staff/{user}/timeline` — `timeline.staff` — `App\Http\Controllers\TimelineController@staff` — `app/Http/Controllers/TimelineController.php:26` — middleware `web, auth`
- `GET|HEAD timeline` — `timeline.my` — `App\Http\Controllers\TimelineController@my` — `app/Http/Controllers/TimelineController.php:18` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/TimelineController.php`.
- Exact render/action page relationships: `resources/js/pages/timeline/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
