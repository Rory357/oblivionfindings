# RESP-RESPITE-PROCEDURE-RUN: Respite Procedure Run

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.procedures.run`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-PROCEDURE-RUN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/procedure-runs` (`respite.procedure-runs.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.procedures.run`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.procedures.run`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/procedure-runs` (`respite.procedure-runs.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD respite/procedure-runs/{procedureRun}` (`respite.procedure-runs.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureRunController.php:124-141`.
3. Use `GET|HEAD respite/procedure-runs/create` (`respite.procedure-runs.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureRunController.php:45-60`.
4. Use `GET|HEAD respite/procedure-runs/my-active` (`respite.procedure-runs.my-active`, action `myActive`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureRunController.php:285-297`.
5. Use `GET|HEAD respite/procedure-runs/overdue` (`respite.procedure-runs.overdue`, action `overdue`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Respite/RespiteProcedureRunController.php:299-310`.
6. Invoke only the owning control for `POST respite/procedure-runs` (`respite.procedure-runs.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:62-122`; `procedure_template_id`, `subject_type`, `subject_id`, `variables`.
7. Invoke only the owning control for `POST respite/procedure-runs/{procedureRun}/cancel` (`respite.procedure-runs.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:228-255`; `cancellation_reason`.
8. Invoke only the owning control for `POST respite/procedure-runs/{procedureRun}/complete` (`respite.procedure-runs.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:168-200`; no exact validation fields extracted.
9. Invoke only the owning control for `POST respite/procedure-runs/{procedureRun}/escalate` (`respite.procedure-runs.escalate`, action `escalate`). Source category: **escalated/flagged**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:257-283`; `escalate_to_user_id`, `escalation_reason`.
10. Invoke only the owning control for `POST respite/procedure-runs/{procedureRun}/fail` (`respite.procedure-runs.fail`, action `fail`). Source category: **mutation outcome source gap (fail)**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:202-226`; `failure_reason`.
11. Invoke only the owning control for `POST respite/procedure-runs/{procedureRun}/start` (`respite.procedure-runs.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteProcedureRunController.php:143-166`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2401` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2402` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:62`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2403` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:124`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-2404` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:228`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-2405` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:168`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `escalate` / `ROUTE-2406` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:257`; it is not runtime-observed.
- **mutation outcome source gap (fail)** is applicable only to `fail` / `ROUTE-2407` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:202`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-2408` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:143`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2409` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:45`; it is not runtime-observed.
- **information presented** is applicable only to `myActive` / `ROUTE-2410` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:285`; it is not runtime-observed.
- **information presented** is applicable only to `overdue` / `ROUTE-2411` at `app/Http/Controllers/Respite/RespiteProcedureRunController.php:299`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/procedure-runs/create.tsx`, `resources/js/pages/respite/procedure-runs/index.tsx`, `resources/js/pages/respite/procedure-runs/my-active.tsx`, `resources/js/pages/respite/procedure-runs/overdue.tsx`, `resources/js/pages/respite/procedure-runs/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2402` / `store`: fields `procedure_template_id`, `subject_type`, `subject_id`, `variables`; success app/Http/Controllers/Respite/RespiteProcedureRunController.php:121 `->with('success', 'Procedure started.');`.
- `ROUTE-2404` / `cancel`: fields `cancellation_reason`; success app/Http/Controllers/Respite/RespiteProcedureRunController.php:254 `return back()->with('success', 'Procedure cancelled.');`.
- `ROUTE-2405` / `complete`: success app/Http/Controllers/Respite/RespiteProcedureRunController.php:199 `return back()->with('success', 'Procedure completed.');`.
- `ROUTE-2406` / `escalate`: fields `escalate_to_user_id`, `escalation_reason`; success app/Http/Controllers/Respite/RespiteProcedureRunController.php:282 `return back()->with('success', 'Procedure escalated.');`.
- `ROUTE-2407` / `fail`: fields `failure_reason`; success app/Http/Controllers/Respite/RespiteProcedureRunController.php:225 `return back()->with('success', 'Procedure marked as failed.');`.
- `ROUTE-2408` / `start`: success app/Http/Controllers/Respite/RespiteProcedureRunController.php:165 `return back()->with('success', 'Procedure started.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteProcedureRunController.php:78 `$run = RespiteProcedureRun::create([`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:234 `$procedureRun->update([`; responses app/Http/Controllers/Respite/RespiteProcedureRunController.php:38 `return Inertia::render('respite/procedure-runs/index', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:98 `return $run;`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:119 `return redirect()`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:138 `return Inertia::render('respite/procedure-runs/show', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:254 `return back()->with('success', 'Procedure cancelled.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:171 `return back()->with('error', 'Procedure cannot be completed in its current state.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:180 `return back()->with('error', "Cannot complete procedure: {$incompleteTasks} task(s) still pending.");`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:199 `return back()->with('success', 'Procedure completed.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:282 `return back()->with('success', 'Procedure escalated.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:225 `return back()->with('success', 'Procedure marked as failed.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:146 `return back()->with('error', 'Procedure has already been started.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:165 `return back()->with('success', 'Procedure started.');`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:55 `return Inertia::render('respite/procedure-runs/create', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:294 `return Inertia::render('respite/procedure-runs/my-active', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:307 `return Inertia::render('respite/procedure-runs/overdue', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteProcedureRunController.php:111 `event(new RespiteEvent('respite.procedure.started', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:250 `event(new RespiteEvent('respite.procedure.cancelled', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:195 `event(new RespiteEvent('respite.procedure.completed', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:276 `event(new RespiteEvent('respite.procedure.escalated', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:220 `event(new RespiteEvent('respite.procedure.failed', [`; app/Http/Controllers/Respite/RespiteProcedureRunController.php:161 `event(new RespiteEvent('respite.procedure.in_progress', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD respite/procedure-runs` — `respite.procedure-runs.index` — `App\Http\Controllers\Respite\RespiteProcedureRunController@index` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:20` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs` — `respite.procedure-runs.store` — `App\Http\Controllers\Respite\RespiteProcedureRunController@store` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:62` — middleware `web, auth, permission:respite.procedures.run`
- `GET|HEAD respite/procedure-runs/{procedureRun}` — `respite.procedure-runs.show` — `App\Http\Controllers\Respite\RespiteProcedureRunController@show` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:124` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs/{procedureRun}/cancel` — `respite.procedure-runs.cancel` — `App\Http\Controllers\Respite\RespiteProcedureRunController@cancel` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:228` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs/{procedureRun}/complete` — `respite.procedure-runs.complete` — `App\Http\Controllers\Respite\RespiteProcedureRunController@complete` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:168` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs/{procedureRun}/escalate` — `respite.procedure-runs.escalate` — `App\Http\Controllers\Respite\RespiteProcedureRunController@escalate` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:257` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs/{procedureRun}/fail` — `respite.procedure-runs.fail` — `App\Http\Controllers\Respite\RespiteProcedureRunController@fail` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:202` — middleware `web, auth, permission:respite.procedures.run`
- `POST respite/procedure-runs/{procedureRun}/start` — `respite.procedure-runs.start` — `App\Http\Controllers\Respite\RespiteProcedureRunController@start` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:143` — middleware `web, auth, permission:respite.procedures.run`
- `GET|HEAD respite/procedure-runs/create` — `respite.procedure-runs.create` — `App\Http\Controllers\Respite\RespiteProcedureRunController@create` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:45` — middleware `web, auth, permission:respite.procedures.run`
- `GET|HEAD respite/procedure-runs/my-active` — `respite.procedure-runs.my-active` — `App\Http\Controllers\Respite\RespiteProcedureRunController@myActive` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:285` — middleware `web, auth, permission:respite.procedures.run`
- `GET|HEAD respite/procedure-runs/overdue` — `respite.procedure-runs.overdue` — `App\Http\Controllers\Respite\RespiteProcedureRunController@overdue` — `app/Http/Controllers/Respite/RespiteProcedureRunController.php:299` — middleware `web, auth, permission:respite.procedures.run`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteProcedureRunController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/procedure-runs/create.tsx`, `resources/js/pages/respite/procedure-runs/index.tsx`, `resources/js/pages/respite/procedure-runs/my-active.tsx`, `resources/js/pages/respite/procedure-runs/overdue.tsx`, `resources/js/pages/respite/procedure-runs/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
