# CR-CONTROL-ROOM-SLA: Control Room Sla

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-SLA`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/sla` (`control-room.sla.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/sla` (`control-room.sla.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD control-room/sla/breaches` (`control-room.sla.breaches`, action `breachReport`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:225-323`.
3. Invoke only the owning control for `POST control-room/sla` (`control-room.sla.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:117-157`; `name`.
4. Invoke only the owning control for `PUT control-room/sla/{sla}` (`control-room.sla.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:162-202`; `name`.
5. Invoke only the owning control for `POST control-room/sla/{sla}/toggle-active` (`control-room.sla.toggle-active`, action `toggleActive`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:207-220`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0309` at `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0310` at `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:117`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0312` at `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:162`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleActive` / `ROUTE-0313` at `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:207`; it is not runtime-observed.
- **information presented** is applicable only to `breachReport` / `ROUTE-0314` at `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:225`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/sla/breaches.tsx`, `resources/js/pages/control-room/sla/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0310` / `store`: fields `name`; success app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:156 `return back()->with('success', 'SLA definition created.');`.
- `ROUTE-0312` / `update`: fields `name`; success app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:201 `return back()->with('success', 'SLA definition updated.');`.
- `ROUTE-0313` / `toggleActive`: success app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:219 `return back()->with('success', $sla->is_active ? 'SLA activated.' : 'SLA deactivated.');`.
- `ROUTE-0314` / `breachReport`: fields `date_from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:149 `$sla = SlaDefinition::create($data);`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:194 `$sla->update($data);`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:212 `$sla->update(['is_active' => !$sla->is_active]);`; responses app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:73 `return [`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:106 `return Inertia::render('control-room/sla/index', [`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:156 `return back()->with('success', 'SLA definition created.');`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:201 `return back()->with('success', 'SLA definition updated.');`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:219 `return back()->with('success', $sla->is_active ? 'SLA activated.' : 'SLA deactivated.');`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:274 `return [`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:308 `return Inertia::render('control-room/sla/breaches', [`; audit calls app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:151 `AuditLogger::log('controlRoom.sla.create', $sla, [`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:196 `AuditLogger::log('controlRoom.sla.update', $sla, [`; app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:214 `AuditLogger::log('controlRoom.sla.toggleActive', $sla, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/sla` — `control-room.sla.index` — `App\Http\Controllers\ControlRoom\ControlRoomSlaController@index` — `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:18` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/sla` — `control-room.sla.store` — `App\Http\Controllers\ControlRoom\ControlRoomSlaController@store` — `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:117` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `PUT control-room/sla/{sla}` — `control-room.sla.update` — `App\Http\Controllers\ControlRoom\ControlRoomSlaController@update` — `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:162` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/sla/{sla}/toggle-active` — `control-room.sla.toggle-active` — `App\Http\Controllers\ControlRoom\ControlRoomSlaController@toggleActive` — `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:207` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `GET|HEAD control-room/sla/breaches` — `control-room.sla.breaches` — `App\Http\Controllers\ControlRoom\ControlRoomSlaController@breachReport` — `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php:225` — middleware `web, auth, permission:controlRoom.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomSlaController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/sla/breaches.tsx`, `resources/js/pages/control-room/sla/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
