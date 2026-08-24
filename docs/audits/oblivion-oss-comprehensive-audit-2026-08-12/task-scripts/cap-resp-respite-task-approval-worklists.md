# CAP-RESP-RESPITE-TASK-APPROVAL-WORKLISTS: Respite task approval rejection and approval worklists

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.tasks.approve`, `permission:respite.tasks.manage`, `permission:respite.tasks.view`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-TASK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/tasks/awaiting-approval` (`respite.tasks.awaiting-approval`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.tasks.approve`, `permission:respite.tasks.manage`, `permission:respite.tasks.view`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.tasks.approve`, `permission:respite.tasks.manage`, `permission:respite.tasks.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/tasks/awaiting-approval` (`respite.tasks.awaiting-approval`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/tasks/my-tasks` (`respite.tasks.my-tasks`, action `myTasks`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteTaskController.php:285-298`.
3. Use `GET|HEAD respite/tasks/overdue` (`respite.tasks.overdue`, action `overdue`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteTaskController.php:314-325`.
4. Invoke only the owning control for `POST respite/tasks/{task}/approve` (`respite.tasks.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:186-214`; `approval_notes`.
5. Invoke only the owning control for `POST respite/tasks/{task}/reject` (`respite.tasks.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:216-244`; `rejection_notes`.
6. Invoke only the owning control for `POST respite/tasks/{task}/submit-for-approval` (`respite.tasks.submit-for-approval`, action `submitForApproval`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:160-184`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2461` at `app/Http/Controllers/Respite/RespiteTaskController.php:186`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-2464` at `app/Http/Controllers/Respite/RespiteTaskController.php:216`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitForApproval` / `ROUTE-2466` at `app/Http/Controllers/Respite/RespiteTaskController.php:160`; it is not runtime-observed.
- **information presented** is applicable only to `awaitingApproval` / `ROUTE-2468` at `app/Http/Controllers/Respite/RespiteTaskController.php:300`; it is not runtime-observed.
- **information presented** is applicable only to `myTasks` / `ROUTE-2469` at `app/Http/Controllers/Respite/RespiteTaskController.php:285`; it is not runtime-observed.
- **information presented** is applicable only to `overdue` / `ROUTE-2470` at `app/Http/Controllers/Respite/RespiteTaskController.php:314`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/tasks/awaiting-approval.tsx`, `resources/js/pages/respite/tasks/my-tasks.tsx`, `resources/js/pages/respite/tasks/overdue.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2461` / `approve`: fields `approval_notes`; success app/Http/Controllers/Respite/RespiteTaskController.php:213 `return back()->with('success', 'Task approved.');`.
- `ROUTE-2464` / `reject`: fields `rejection_notes`; success app/Http/Controllers/Respite/RespiteTaskController.php:243 `return back()->with('success', 'Task rejected.');`.
- `ROUTE-2466` / `submitForApproval`: success app/Http/Controllers/Respite/RespiteTaskController.php:183 `return back()->with('success', 'Task submitted for approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Respite/RespiteTaskController.php:189 `return back()->with('error', 'Task is not awaiting approval.');`; app/Http/Controllers/Respite/RespiteTaskController.php:213 `return back()->with('success', 'Task approved.');`; app/Http/Controllers/Respite/RespiteTaskController.php:219 `return back()->with('error', 'Task is not awaiting approval.');`; app/Http/Controllers/Respite/RespiteTaskController.php:243 `return back()->with('success', 'Task rejected.');`; app/Http/Controllers/Respite/RespiteTaskController.php:163 `return back()->with('error', 'Task does not require approval.');`; app/Http/Controllers/Respite/RespiteTaskController.php:183 `return back()->with('success', 'Task submitted for approval.');`; app/Http/Controllers/Respite/RespiteTaskController.php:309 `return Inertia::render('respite/tasks/awaiting-approval', [`; app/Http/Controllers/Respite/RespiteTaskController.php:295 `return Inertia::render('respite/tasks/my-tasks', [`; app/Http/Controllers/Respite/RespiteTaskController.php:322 `return Inertia::render('respite/tasks/overdue', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteTaskController.php:208 `event(new RespiteEvent('respite.task.approved', [`; app/Http/Controllers/Respite/RespiteTaskController.php:238 `event(new RespiteEvent('respite.task.rejected', [`; app/Http/Controllers/Respite/RespiteTaskController.php:178 `event(new RespiteEvent('respite.task.awaiting_approval', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/tasks/{task}/approve` — `respite.tasks.approve` — `App\Http\Controllers\Respite\RespiteTaskController@approve` — `app/Http/Controllers/Respite/RespiteTaskController.php:186` — middleware `web, auth, permission:respite.tasks.approve`
- `POST respite/tasks/{task}/reject` — `respite.tasks.reject` — `App\Http\Controllers\Respite\RespiteTaskController@reject` — `app/Http/Controllers/Respite/RespiteTaskController.php:216` — middleware `web, auth, permission:respite.tasks.approve`
- `POST respite/tasks/{task}/submit-for-approval` — `respite.tasks.submit-for-approval` — `App\Http\Controllers\Respite\RespiteTaskController@submitForApproval` — `app/Http/Controllers/Respite/RespiteTaskController.php:160` — middleware `web, auth, permission:respite.tasks.manage`
- `GET|HEAD respite/tasks/awaiting-approval` — `respite.tasks.awaiting-approval` — `App\Http\Controllers\Respite\RespiteTaskController@awaitingApproval` — `app/Http/Controllers/Respite/RespiteTaskController.php:300` — middleware `web, auth, permission:respite.tasks.view`
- `GET|HEAD respite/tasks/my-tasks` — `respite.tasks.my-tasks` — `App\Http\Controllers\Respite\RespiteTaskController@myTasks` — `app/Http/Controllers/Respite/RespiteTaskController.php:285` — middleware `web, auth, permission:respite.tasks.view`
- `GET|HEAD respite/tasks/overdue` — `respite.tasks.overdue` — `App\Http\Controllers\Respite\RespiteTaskController@overdue` — `app/Http/Controllers/Respite/RespiteTaskController.php:314` — middleware `web, auth, permission:respite.tasks.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteTaskController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/tasks/awaiting-approval.tsx`, `resources/js/pages/respite/tasks/my-tasks.tsx`, `resources/js/pages/respite/tasks/overdue.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
