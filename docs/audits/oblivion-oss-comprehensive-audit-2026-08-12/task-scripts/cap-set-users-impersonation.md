# CAP-SET-USERS-IMPERSONATION: User impersonation start and stop

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:settings.access.impersonate`
- Owning module: Settings and system access
- Legacy family: `SET-USERS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `system/users` (`system.users.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:settings.access.impersonate`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:settings.access.impersonate`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD system/users` (`system.users.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST system/users/{target}/impersonate` (`system.users.impersonate`, action `impersonate`). Source category: **mutation outcome source gap (impersonate)**; controller `app/Http/Controllers/System/UsersController.php:504-523`; no exact validation fields extracted.
3. Invoke only the owning control for `POST system/users/stop-impersonating` (`system.users.stop-impersonating`, action `stopImpersonating`). Source category: **mutation outcome source gap (stopImpersonating)**; controller `app/Http/Controllers/System/UsersController.php:528-546`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (impersonate)** is applicable only to `impersonate` / `ROUTE-2965` at `app/Http/Controllers/System/UsersController.php:504`; it is not runtime-observed.
- **mutation outcome source gap (stopImpersonating)** is applicable only to `stopImpersonating` / `ROUTE-2970` at `app/Http/Controllers/System/UsersController.php:528`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2970` / `stopImpersonating`: success app/Http/Controllers/System/UsersController.php:545 `->with('success', 'You have stopped impersonating.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/System/UsersController.php:521 `return redirect()->route('dashboard')`; app/Http/Controllers/System/UsersController.php:544 `return redirect()->route('system.users.index')`; audit calls app/Http/Controllers/System/UsersController.php:512 `AuditLogger::log('user.impersonate.start', $target, [`; app/Http/Controllers/System/UsersController.php:536 `AuditLogger::log('user.impersonate.stop', $impersonatedUser, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST system/users/{target}/impersonate` — `system.users.impersonate` — `App\Http\Controllers\System\UsersController@impersonate` — `app/Http/Controllers/System/UsersController.php:504` — middleware `web, auth, verified, permission:settings.access.impersonate`
- `POST system/users/stop-impersonating` — `system.users.stop-impersonating` — `App\Http\Controllers\System\UsersController@stopImpersonating` — `app/Http/Controllers/System/UsersController.php:528` — middleware `web, auth, verified`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/System/UsersController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
