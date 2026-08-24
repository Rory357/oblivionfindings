# CR-CONTROL-ROOM-TIME-ENTRY: Control Room Time Entry

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-TIME-ENTRY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts/{alert}/time-entries` (`control-room.time-entries.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts/{alert}/time-entries` (`control-room.time-entries.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/alerts/{alert}/time-entries` (`control-room.time-entries.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:152-194`; `duration_minutes`.
3. Invoke only the owning control for `POST control-room/alerts/{alert}/time-entries/start` (`control-room.time-entries.start`, action `start`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:60-95`; no exact validation fields extracted.
4. Invoke only the owning control for `DELETE control-room/time-entries/{entry}` (`control-room.time-entries.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:199-223`; no exact validation fields extracted.
5. Invoke only the owning control for `POST control-room/time-entries/{entry}/stop` (`control-room.time-entries.stop`, action `stop`). Source category: **mutation outcome source gap (stop)**; controller `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:100-147`; `description`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0237` at `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0238` at `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:152`; it is not runtime-observed.
- **created/recorded** is applicable only to `start` / `ROUTE-0239` at `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:60`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0319` at `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:199`; it is not runtime-observed.
- **mutation outcome source gap (stop)** is applicable only to `stop` / `ROUTE-0320` at `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:100`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0238` / `store`: fields `duration_minutes`.
- `ROUTE-0239` / `start`: failure app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:73 `return back()->withErrors(['alert' => 'You already have a running timer on this alert.']);`.
- `ROUTE-0320` / `stop`: fields `description`; failure app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:107 `return back()->withErrors(['alert' => 'This time entry is not running.']);`.

## Failure and recovery paths

- `start`: app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:73 `return back()->withErrors(['alert' => 'You already have a running timer on this alert.']);`.
- `stop`: app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:107 `return back()->withErrors(['alert' => 'This time entry is not running.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:170 `$entry = TimeEntry::create([`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:79 `$entry = TimeEntry::create([`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:208 `$entry->delete();`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:120 `$entry->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:47 `return response()->json([`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:190 `return $this->inertiaOrJson($request, 'Time logged.');`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:193 `return response()->json(['entry' => $entry], 201);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:73 `return back()->withErrors(['alert' => 'You already have a running timer on this alert.']);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:76 `return response()->json(['message' => 'You already have a running timer on this alert.'], 422);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:86 `return $this->inertiaOrJson($request, 'Timer started.');`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:89 `return response()->json([`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:219 `return $this->inertiaOrJson($request, 'Time entry deleted.');`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:222 `return response()->json(['message' => 'Time entry deleted.']);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:107 `return back()->withErrors(['alert' => 'This time entry is not running.']);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:110 `return response()->json(['message' => 'This time entry is not running.'], 422);`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:136 `return $this->inertiaOrJson($request, "Timer stopped — {$entry->duration_minutes} min logged.");`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:139 `return response()->json([`; audit calls app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:183 `AuditLogger::log('controlRoom.timeEntry.created', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:213 `AuditLogger::log('controlRoom.timeEntry.deleted', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:129 `AuditLogger::log('controlRoom.timeEntry.stopped', $entry->alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/alerts/{alert}/time-entries` — `control-room.time-entries.index` — `App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController@index` — `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:19` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/time-entries` — `control-room.time-entries.store` — `App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController@store` — `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:152` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/time-entries/start` — `control-room.time-entries.start` — `App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController@start` — `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:60` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `DELETE control-room/time-entries/{entry}` — `control-room.time-entries.destroy` — `App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController@destroy` — `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:199` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/time-entries/{entry}/stop` — `control-room.time-entries.stop` — `App\Http\Controllers\ControlRoom\ControlRoomTimeEntryController@stop` — `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php:100` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomTimeEntryController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
