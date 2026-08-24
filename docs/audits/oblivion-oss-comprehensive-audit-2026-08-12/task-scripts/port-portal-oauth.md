# PORT-PORTAL-OAUTH: Portal OAuth

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution
- Owning module: Client and family portal
- Legacy family: `PORT-PORTAL-OAUTH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/auth/google/callback` (`unnamed`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution.
- Exact middleware atoms: `web`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/auth/google/callback` (`unnamed`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD portal/auth/google/redirect` (`portal.auth.google`, action `redirectGoogle`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Auth/PortalOAuthController.php:45-48`.
3. Use `GET|HEAD portal/auth/microsoft/callback` (`unnamed`, action `callbackMicrosoft`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Auth/PortalOAuthController.php:25-39`.
4. Use `GET|HEAD portal/auth/microsoft/redirect` (`portal.auth.microsoft`, action `redirectMicrosoft`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Auth/PortalOAuthController.php:18-23`.

## Source-applicable states and transitions

- **information presented** is applicable only to `callbackGoogle` / `ROUTE-2241` at `app/Http/Controllers/Auth/PortalOAuthController.php:50`; it is not runtime-observed.
- **information presented** is applicable only to `redirectGoogle` / `ROUTE-2242` at `app/Http/Controllers/Auth/PortalOAuthController.php:45`; it is not runtime-observed.
- **information presented** is applicable only to `callbackMicrosoft` / `ROUTE-2243` at `app/Http/Controllers/Auth/PortalOAuthController.php:25`; it is not runtime-observed.
- **information presented** is applicable only to `redirectMicrosoft` / `ROUTE-2244` at `app/Http/Controllers/Auth/PortalOAuthController.php:18`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/auth/google/callback` — `unnamed` — `App\Http\Controllers\Auth\PortalOAuthController@callbackGoogle` — `app/Http/Controllers/Auth/PortalOAuthController.php:50` — middleware `web`
- `GET|HEAD portal/auth/google/redirect` — `portal.auth.google` — `App\Http\Controllers\Auth\PortalOAuthController@redirectGoogle` — `app/Http/Controllers/Auth/PortalOAuthController.php:45` — middleware `web`
- `GET|HEAD portal/auth/microsoft/callback` — `unnamed` — `App\Http\Controllers\Auth\PortalOAuthController@callbackMicrosoft` — `app/Http/Controllers/Auth/PortalOAuthController.php:25` — middleware `web`
- `GET|HEAD portal/auth/microsoft/redirect` — `portal.auth.microsoft` — `App\Http\Controllers\Auth\PortalOAuthController@redirectMicrosoft` — `app/Http/Controllers/Auth/PortalOAuthController.php:18` — middleware `web`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Auth/PortalOAuthController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
