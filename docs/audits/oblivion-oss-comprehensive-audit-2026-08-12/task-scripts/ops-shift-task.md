# OPS-SHIFT-TASK: Shift Task

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT-TASK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny`.
- Exact middleware atoms: `web`, `auth`, `permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `PATCH operations/shifts/{shift}/tasks/{task}` (`operations.shifts.tasks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ShiftTaskController.php:11-48`; `is_completed`.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `update` / `ROUTE-2211` at `app/Http/Controllers/ShiftTaskController.php:11`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2211` / `update`: fields `is_completed`; failure app/Http/Controllers/ShiftTaskController.php:18 `abort(403);`.

## Failure and recovery paths

- `update`: app/Http/Controllers/ShiftTaskController.php:18 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ShiftTaskController.php:33 `$task->update([`; app/Http/Controllers/ShiftTaskController.php:39 `$task->update([`; responses app/Http/Controllers/ShiftTaskController.php:23 `return response()->json(['ok' => false, 'message' => 'This shift has been completed and is now locked.'], 422);`; app/Http/Controllers/ShiftTaskController.php:47 `return response()->json(['ok' => true, 'task' => $task->fresh()]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH operations/shifts/{shift}/tasks/{task}` — `operations.shifts.tasks.update` — `App\Http\Controllers\ShiftTaskController@update` — `app/Http/Controllers/ShiftTaskController.php:11` — middleware `web, auth, permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ShiftTaskController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
