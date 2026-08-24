# CR-CONTROL-ROOM-BROADCAST: Control Room Broadcast

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-BROADCAST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/broadcast` (`control-room.broadcast.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/broadcast` (`control-room.broadcast.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/broadcast/{groupId}` (`control-room.broadcast.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:197-249`.
3. Invoke only the owning control for `POST control-room/broadcast` (`control-room.broadcast.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:102-192`; `content`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0248` at `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0249` at `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:102`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0250` at `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:197`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/broadcast-show.tsx`, `resources/js/pages/control-room/broadcast.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0249` / `store`: fields `content`; success app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:191 `->with('success', "Broadcast queued for {$targetUsers->count()} recipients via ".count($channels).' channel(s).');`.
- `ROUTE-0250` / `show`: failure app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:213 `abort(404);`.

## Failure and recovery paths

- `show`: app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:213 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:172 `Communication::insert($chunk);`; responses app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:56 `return [`; app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:88 `return Inertia::render('control-room/broadcast', [`; app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:133 `return redirect()->route('control-room.broadcast.index')`; app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:170 `// because bulk insert doesn't return IDs reliably across DB drivers.`; app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:190 `return redirect()->route('control-room.broadcast.index')`; app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:245 `return Inertia::render('control-room/broadcast-show', [`; audit calls app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:180 `AuditLogger::log('controlRoom.broadcast.sent', null, [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:178 `->each(fn ($id) => DeliverBroadcastCommunicationJob::dispatch((int) $id));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD control-room/broadcast` — `control-room.broadcast.index` — `App\Http\Controllers\ControlRoom\ControlRoomBroadcastController@index` — `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:21` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/broadcast` — `control-room.broadcast.store` — `App\Http\Controllers\ControlRoom\ControlRoomBroadcastController@store` — `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:102` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/broadcast/{groupId}` — `control-room.broadcast.show` — `App\Http\Controllers\ControlRoom\ControlRoomBroadcastController@show` — `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php:197` — middleware `web, auth, permission:controlRoom.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomBroadcastController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/broadcast-show.tsx`, `resources/js/pages/control-room/broadcast.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
