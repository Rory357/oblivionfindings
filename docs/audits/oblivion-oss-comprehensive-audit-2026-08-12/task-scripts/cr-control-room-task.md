# CR-CONTROL-ROOM-TASK: Control Room Task

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-TASK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts/{alert}/tasks` (`control-room.tasks.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts/{alert}/tasks` (`control-room.tasks.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/alerts/{alert}/tasks` (`control-room.tasks.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:51-88`; `title`.
3. Invoke only the owning control for `POST control-room/alerts/{alert}/tasks/reorder` (`control-room.tasks.reorder`, action `reorder`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:175-197`; `task_ids`.
4. Invoke only the owning control for `DELETE control-room/tasks/{task}` (`control-room.tasks.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:155-170`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT control-room/tasks/{task}` (`control-room.tasks.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:93-116`; `title`.
6. Invoke only the owning control for `POST control-room/tasks/{task}/status` (`control-room.tasks.status`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:121-150`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0234` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0235` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:51`; it is not runtime-observed.
- **updated/revised** is applicable only to `reorder` / `ROUTE-0236` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:175`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0316` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:155`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0317` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:93`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-0318` at `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:121`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0235` / `store`: fields `title`; success app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:87 `return back()->with('success', 'Task created.');`.
- `ROUTE-0236` / `reorder`: fields `task_ids`; success app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:196 `return back()->with('success', 'Tasks reordered.');`.
- `ROUTE-0316` / `destroy`: success app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:169 `return back()->with('success', 'Task deleted.');`.
- `ROUTE-0317` / `update`: fields `title`; success app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:115 `return back()->with('success', 'Task updated.');`.
- `ROUTE-0318` / `updateStatus`: success app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:149 `return back()->with('success', 'Task status updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:68 `$task = AlertTask::create([`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:188 `->update(['sort_order' => $index + 1]);`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:163 `$task->delete();`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:108 `$task->update(array_filter($data, fn ($v) => $v !== null));`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:141 `$task->update($updates);`; responses app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:45 `return response()->json(['tasks' => $tasks]);`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:87 `return back()->with('success', 'Task created.');`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:196 `return back()->with('success', 'Tasks reordered.');`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:169 `return back()->with('success', 'Task deleted.');`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:115 `return back()->with('success', 'Task updated.');`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:149 `return back()->with('success', 'Task status updated.');`; audit calls app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:82 `AuditLogger::log('controlRoom.task.created', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:191 `AuditLogger::log('controlRoom.task.reordered', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:165 `AuditLogger::log('controlRoom.task.deleted', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:110 `AuditLogger::log('controlRoom.task.updated', $task->alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:143 `AuditLogger::log('controlRoom.task.statusChanged', $task->alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/alerts/{alert}/tasks` — `control-room.tasks.index` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@index` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:16` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/tasks` — `control-room.tasks.store` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@store` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:51` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/tasks/reorder` — `control-room.tasks.reorder` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@reorder` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:175` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `DELETE control-room/tasks/{task}` — `control-room.tasks.destroy` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@destroy` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:155` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `PUT control-room/tasks/{task}` — `control-room.tasks.update` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@update` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:93` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/tasks/{task}/status` — `control-room.tasks.status` — `App\Http\Controllers\ControlRoom\ControlRoomTaskController@updateStatus` — `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php:121` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomTaskController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
