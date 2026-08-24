# CR-CONTROL-ROOM-MESSAGING: Control Room Messaging

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-MESSAGING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/messaging` (`control-room.messaging.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/messaging` (`control-room.messaging.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/messaging/thread` (`control-room.messaging.thread`, action `thread`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:117-164`.
3. Invoke only the owning control for `POST control-room/messaging/{communication}/read` (`control-room.messaging.read`, action `markRead`). Source category: **mutation outcome source gap (markRead)**; controller `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:213-227`; no exact validation fields extracted.
4. Invoke only the owning control for `POST control-room/messaging/send` (`control-room.messaging.send`, action `send`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:169-208`; `content`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0273` at `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:18`; it is not runtime-observed.
- **mutation outcome source gap (markRead)** is applicable only to `markRead` / `ROUTE-0274` at `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:213`; it is not runtime-observed.
- **created/recorded** is applicable only to `send` / `ROUTE-0275` at `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:169`; it is not runtime-observed.
- **information presented** is applicable only to `thread` / `ROUTE-0276` at `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:117`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/messaging.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0275` / `send`: fields `content`.
- `ROUTE-0276` / `thread`: failure app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:127 `abort(400, 'Either alert_id or user_id is required.');`.

## Failure and recovery paths

- `thread`: app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:127 `abort(400, 'Either alert_id or user_id is required.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:221 `$communication->update([`; app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:180 `$communication = Communication::create([`; responses app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:105 `return Inertia::render('control-room/messaging', [`; app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:226 `return response()->json(['success' => true]);`; app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:197 `return response()->json([`; app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:130 `return redirect()`; app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:161 `return response()->json([`; audit calls app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:192 `AuditLogger::log('controlRoom.messaging.sent', $communication, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/messaging` — `control-room.messaging.index` — `App\Http\Controllers\ControlRoom\ControlRoomMessagingController@index` — `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:18` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/messaging/{communication}/read` — `control-room.messaging.read` — `App\Http\Controllers\ControlRoom\ControlRoomMessagingController@markRead` — `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:213` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/messaging/send` — `control-room.messaging.send` — `App\Http\Controllers\ControlRoom\ControlRoomMessagingController@send` — `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:169` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/messaging/thread` — `control-room.messaging.thread` — `App\Http\Controllers\ControlRoom\ControlRoomMessagingController@thread` — `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php:117` — middleware `web, auth, permission:controlRoom.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/messaging.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
