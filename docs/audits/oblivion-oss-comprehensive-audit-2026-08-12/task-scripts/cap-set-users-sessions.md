# CAP-SET-USERS-SESSIONS: User session termination

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`, `verified`
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

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`, `verified`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`, `verified`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD system/users` (`system.users.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `DELETE settings/users/{target}/sessions` (`settings.users.terminate-all-sessions`, action `terminateAllSessions`). Source category: **mutation outcome source gap (terminateAllSessions)**; controller `app/Http/Controllers/System/UsersController.php:483-499`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE settings/users/{target}/sessions/{session}` (`settings.users.terminate-session`, action `terminateSession`). Source category: **mutation outcome source gap (terminateSession)**; controller `app/Http/Controllers/System/UsersController.php:461-478`; no exact validation fields extracted.
4. Invoke only the owning control for `DELETE system/users/{target}/sessions` (`system.users.terminate-all-sessions`, action `terminateAllSessions`). Source category: **mutation outcome source gap (terminateAllSessions)**; controller `app/Http/Controllers/System/UsersController.php:483-499`; no exact validation fields extracted.
5. Invoke only the owning control for `DELETE system/users/{target}/sessions/{session}` (`system.users.terminate-session`, action `terminateSession`). Source category: **mutation outcome source gap (terminateSession)**; controller `app/Http/Controllers/System/UsersController.php:461-478`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (terminateAllSessions)** is applicable only to `terminateAllSessions` / `ROUTE-2705` at `app/Http/Controllers/System/UsersController.php:483`; it is not runtime-observed.
- **mutation outcome source gap (terminateSession)** is applicable only to `terminateSession` / `ROUTE-2706` at `app/Http/Controllers/System/UsersController.php:461`; it is not runtime-observed.
- **mutation outcome source gap (terminateAllSessions)** is applicable only to `terminateAllSessions` / `ROUTE-2966` at `app/Http/Controllers/System/UsersController.php:483`; it is not runtime-observed.
- **mutation outcome source gap (terminateSession)** is applicable only to `terminateSession` / `ROUTE-2967` at `app/Http/Controllers/System/UsersController.php:461`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2705` / `terminateAllSessions`: success app/Http/Controllers/System/UsersController.php:498 `return back()->with('success', 'All other sessions terminated.');`.
- `ROUTE-2706` / `terminateSession`: success app/Http/Controllers/System/UsersController.php:477 `return back()->with('success', 'Session terminated.');`.
- `ROUTE-2966` / `terminateAllSessions`: success app/Http/Controllers/System/UsersController.php:498 `return back()->with('success', 'All other sessions terminated.');`.
- `ROUTE-2967` / `terminateSession`: success app/Http/Controllers/System/UsersController.php:477 `return back()->with('success', 'Session terminated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/System/UsersController.php:491 `->delete();`; app/Http/Controllers/System/UsersController.php:469 `->delete();`; responses app/Http/Controllers/System/UsersController.php:498 `return back()->with('success', 'All other sessions terminated.');`; app/Http/Controllers/System/UsersController.php:477 `return back()->with('success', 'Session terminated.');`; audit calls app/Http/Controllers/System/UsersController.php:493 `AuditLogger::log('user.sessions.terminated', $target, [`; app/Http/Controllers/System/UsersController.php:471 `AuditLogger::log('user.session.terminated', $target, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `DELETE settings/users/{target}/sessions` — `settings.users.terminate-all-sessions` — `App\Http\Controllers\System\UsersController@terminateAllSessions` — `app/Http/Controllers/System/UsersController.php:483` — middleware `web, auth, permission:settings.access.manage`
- `DELETE settings/users/{target}/sessions/{session}` — `settings.users.terminate-session` — `App\Http\Controllers\System\UsersController@terminateSession` — `app/Http/Controllers/System/UsersController.php:461` — middleware `web, auth, permission:settings.access.manage`
- `DELETE system/users/{target}/sessions` — `system.users.terminate-all-sessions` — `App\Http\Controllers\System\UsersController@terminateAllSessions` — `app/Http/Controllers/System/UsersController.php:483` — middleware `web, auth, verified, permission:settings.access.manage`
- `DELETE system/users/{target}/sessions/{session}` — `system.users.terminate-session` — `App\Http\Controllers\System\UsersController@terminateSession` — `app/Http/Controllers/System/UsersController.php:461` — middleware `web, auth, verified, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/System/UsersController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
