# OPS-ROSTER: Roster

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-ROSTER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `my-roster` (`my-roster`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD my-roster` (`my-roster`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD my-roster/data` (`my-roster.data`, action `data`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/RosterController.php:24-29`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1890` at `app/Http/Controllers/RosterController.php:17`; it is not runtime-observed.
- **information presented** is applicable only to `data` / `ROUTE-1891` at `app/Http/Controllers/RosterController.php:24`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/my-roster/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD my-roster` — `my-roster` — `App\Http\Controllers\RosterController@index` — `app/Http/Controllers/RosterController.php:17` — middleware `web, auth`
- `GET|HEAD my-roster/data` — `my-roster.data` — `App\Http\Controllers\RosterController@data` — `app/Http/Controllers/RosterController.php:24` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/RosterController.php`.
- Exact render/action page relationships: `resources/js/pages/my-roster/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
