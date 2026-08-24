# CAP-RESP-RESPITE-TASK-EXECUTION-EVIDENCE: Respite task assignment start checklist evidence and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.tasks.view`, `permission:respite.tasks.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-TASK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/tasks` (`respite.tasks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.tasks.view`, `permission:respite.tasks.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.tasks.view`, `permission:respite.tasks.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/tasks` (`respite.tasks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/tasks/{task}` (`respite.tasks.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteTaskController.php:41-58`.
3. Invoke only the owning control for `POST respite/tasks/{task}/add-evidence` (`respite.tasks.add-evidence`, action `addEvidence`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:246-271`; `type`.
4. Invoke only the owning control for `POST respite/tasks/{task}/assign` (`respite.tasks.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:60-92`; `assigned_to_user_id`.
5. Invoke only the owning control for `POST respite/tasks/{task}/complete` (`respite.tasks.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:120-158`; `completion_notes`.
6. Invoke only the owning control for `POST respite/tasks/{task}/start` (`respite.tasks.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:94-118`; no exact validation fields extracted.
7. Invoke only the owning control for `POST respite/tasks/{task}/update-checklist` (`respite.tasks.update-checklist`, action `updateChecklist`). Source category: **updated/revised**; controller `app/Http/Controllers/Respite/RespiteTaskController.php:273-283`; `index`, `completed`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2458` at `app/Http/Controllers/Respite/RespiteTaskController.php:18`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2459` at `app/Http/Controllers/Respite/RespiteTaskController.php:41`; it is not runtime-observed.
- **created/recorded** is applicable only to `addEvidence` / `ROUTE-2460` at `app/Http/Controllers/Respite/RespiteTaskController.php:246`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-2462` at `app/Http/Controllers/Respite/RespiteTaskController.php:60`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2463` at `app/Http/Controllers/Respite/RespiteTaskController.php:120`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-2465` at `app/Http/Controllers/Respite/RespiteTaskController.php:94`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateChecklist` / `ROUTE-2467` at `app/Http/Controllers/Respite/RespiteTaskController.php:273`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/tasks/index.tsx`, `resources/js/pages/respite/tasks/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2460` / `addEvidence`: fields `type`; success app/Http/Controllers/Respite/RespiteTaskController.php:270 `return back()->with('success', 'Evidence added.');`.
- `ROUTE-2462` / `assign`: fields `assigned_to_user_id`; success app/Http/Controllers/Respite/RespiteTaskController.php:91 `return back()->with('success', 'Task assigned.');`.
- `ROUTE-2463` / `complete`: fields `completion_notes`; success app/Http/Controllers/Respite/RespiteTaskController.php:157 `return back()->with('success', 'Task completed.');`.
- `ROUTE-2465` / `start`: success app/Http/Controllers/Respite/RespiteTaskController.php:117 `return back()->with('success', 'Task started.');`.
- `ROUTE-2467` / `updateChecklist`: fields `index`, `completed`; success app/Http/Controllers/Respite/RespiteTaskController.php:282 `return back()->with('success', 'Checklist updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteTaskController.php:68 `$task->update([`; responses app/Http/Controllers/Respite/RespiteTaskController.php:34 `return Inertia::render('respite/tasks/index', [`; app/Http/Controllers/Respite/RespiteTaskController.php:55 `return Inertia::render('respite/tasks/show', [`; app/Http/Controllers/Respite/RespiteTaskController.php:270 `return back()->with('success', 'Evidence added.');`; app/Http/Controllers/Respite/RespiteTaskController.php:91 `return back()->with('success', 'Task assigned.');`; app/Http/Controllers/Respite/RespiteTaskController.php:124 `return back()->with('error', 'Task requires approval before completion.');`; app/Http/Controllers/Respite/RespiteTaskController.php:127 `return back()->with('error', 'All required evidence must be collected before completion.');`; app/Http/Controllers/Respite/RespiteTaskController.php:157 `return back()->with('success', 'Task completed.');`; app/Http/Controllers/Respite/RespiteTaskController.php:97 `return back()->with('error', 'Task has already been started.');`; app/Http/Controllers/Respite/RespiteTaskController.php:117 `return back()->with('success', 'Task started.');`; app/Http/Controllers/Respite/RespiteTaskController.php:282 `return back()->with('success', 'Checklist updated.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteTaskController.php:265 `event(new RespiteEvent('respite.task.evidence_added', [`; app/Http/Controllers/Respite/RespiteTaskController.php:85 `event(new RespiteEvent('respite.task.assigned', [`; app/Http/Controllers/Respite/RespiteTaskController.php:152 `event(new RespiteEvent('respite.task.completed', [`; app/Http/Controllers/Respite/RespiteTaskController.php:112 `event(new RespiteEvent('respite.task.started', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/tasks` — `respite.tasks.index` — `App\Http\Controllers\Respite\RespiteTaskController@index` — `app/Http/Controllers/Respite/RespiteTaskController.php:18` — middleware `web, auth, permission:respite.tasks.view`
- `GET|HEAD respite/tasks/{task}` — `respite.tasks.show` — `App\Http\Controllers\Respite\RespiteTaskController@show` — `app/Http/Controllers/Respite/RespiteTaskController.php:41` — middleware `web, auth, permission:respite.tasks.view`
- `POST respite/tasks/{task}/add-evidence` — `respite.tasks.add-evidence` — `App\Http\Controllers\Respite\RespiteTaskController@addEvidence` — `app/Http/Controllers/Respite/RespiteTaskController.php:246` — middleware `web, auth, permission:respite.tasks.manage`
- `POST respite/tasks/{task}/assign` — `respite.tasks.assign` — `App\Http\Controllers\Respite\RespiteTaskController@assign` — `app/Http/Controllers/Respite/RespiteTaskController.php:60` — middleware `web, auth, permission:respite.tasks.manage`
- `POST respite/tasks/{task}/complete` — `respite.tasks.complete` — `App\Http\Controllers\Respite\RespiteTaskController@complete` — `app/Http/Controllers/Respite/RespiteTaskController.php:120` — middleware `web, auth, permission:respite.tasks.manage`
- `POST respite/tasks/{task}/start` — `respite.tasks.start` — `App\Http\Controllers\Respite\RespiteTaskController@start` — `app/Http/Controllers/Respite/RespiteTaskController.php:94` — middleware `web, auth, permission:respite.tasks.manage`
- `POST respite/tasks/{task}/update-checklist` — `respite.tasks.update-checklist` — `App\Http\Controllers\Respite\RespiteTaskController@updateChecklist` — `app/Http/Controllers/Respite/RespiteTaskController.php:273` — middleware `web, auth, permission:respite.tasks.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteTaskController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/tasks/index.tsx`, `resources/js/pages/respite/tasks/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
