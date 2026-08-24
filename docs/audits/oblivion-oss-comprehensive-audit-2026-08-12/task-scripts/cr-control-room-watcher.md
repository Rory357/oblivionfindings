# CR-CONTROL-ROOM-WATCHER: Control Room Watcher

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-WATCHER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts/{alert}/watchers` (`control-room.watchers.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts/{alert}/watchers` (`control-room.watchers.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/alerts/{alert}/watchers` (`control-room.watchers.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:43-83`; `user_id`.
3. Invoke only the owning control for `DELETE control-room/alerts/{alert}/watchers/{userId}` (`control-room.watchers.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:118-140`; no exact validation fields extracted.
4. Invoke only the owning control for `POST control-room/alerts/{alert}/watchers/toggle` (`control-room.watchers.toggle`, action `toggle`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:88-113`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0242` at `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0243` at `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:43`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0244` at `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:118`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggle` / `ROUTE-0245` at `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:88`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0243` / `store`: fields `user_id`; failure app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:59 `return back()->withErrors(['alert' => 'User is already watching this alert.']);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:59 `return back()->withErrors(['alert' => 'User is already watching this alert.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:65 `$watcher = AlertWatcher::create([`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:131 `$watcher->delete();`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:98 `$existing->delete();`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:104 `AlertWatcher::create([`; responses app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:37 `return response()->json(['watchers' => $watchers]);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:59 `return back()->withErrors(['alert' => 'User is already watching this alert.']);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:62 `return response()->json(['message' => 'User is already watching this alert.'], 422);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:79 `return $this->inertiaOrJson($request, 'Watcher added.');`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:82 `return response()->json(['watcher' => $watcher], 201);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:128 `return response()->json(['message' => 'Watcher not found.'], 404);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:139 `return response()->json(['message' => 'Watcher removed.']);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:101 `return $this->inertiaOrJson($request, 'Stopped watching this alert.', ['watching' => false]);`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:112 `return $this->inertiaOrJson($request, 'Watching this alert.', ['watching' => true]);`; audit calls app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:73 `AuditLogger::log('controlRoom.watcher.added', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:134 `AuditLogger::log('controlRoom.watcher.removed', $alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/alerts/{alert}/watchers` — `control-room.watchers.index` — `App\Http\Controllers\ControlRoom\ControlRoomWatcherController@index` — `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:19` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/watchers` — `control-room.watchers.store` — `App\Http\Controllers\ControlRoom\ControlRoomWatcherController@store` — `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:43` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `DELETE control-room/alerts/{alert}/watchers/{userId}` — `control-room.watchers.destroy` — `App\Http\Controllers\ControlRoom\ControlRoomWatcherController@destroy` — `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:118` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/alerts/{alert}/watchers/toggle` — `control-room.watchers.toggle` — `App\Http\Controllers\ControlRoom\ControlRoomWatcherController@toggle` — `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php:88` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomWatcherController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
