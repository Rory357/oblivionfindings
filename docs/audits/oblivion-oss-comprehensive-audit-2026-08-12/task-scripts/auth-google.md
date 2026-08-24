# AUTH-GOOGLE: Google

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution
- Owning module: Authentication and account security
- Legacy family: `AUTH-GOOGLE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `auth/google/callback` (`auth.google.callback`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution.
- Exact middleware atoms: `web`, `throttle:auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD auth/google/callback` (`auth.google.callback`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD auth/google/redirect` (`auth.google.redirect`, action `redirect`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Auth/GoogleController.php:12-20`.

## Source-applicable states and transitions

- **information presented** is applicable only to `callback` / `ROUTE-0078` at `app/Http/Controllers/Auth/GoogleController.php:22`; it is not runtime-observed.
- **information presented** is applicable only to `redirect` / `ROUTE-0079` at `app/Http/Controllers/Auth/GoogleController.php:12`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0078` / `callback`: success app/Http/Controllers/Auth/GoogleController.php:42 `return redirect('/settings/profile')->with('success', 'Google account linked.');`; app/Http/Controllers/Auth/GoogleController.php:71 `->with('success', 'Thanks for signing up! Your account is awaiting approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD auth/google/callback` — `auth.google.callback` — `App\Http\Controllers\Auth\GoogleController@callback` — `app/Http/Controllers/Auth/GoogleController.php:22` — middleware `web, throttle:auth`
- `GET|HEAD auth/google/redirect` — `auth.google.redirect` — `App\Http\Controllers\Auth\GoogleController@redirect` — `app/Http/Controllers/Auth/GoogleController.php:12` — middleware `web, throttle:auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Auth/GoogleController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
