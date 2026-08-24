# CR-CONTROL-ROOM-INCIDENT: Control Room Incident

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-INCIDENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/incidents` (`control-room.incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.viewAny`, `permission:controlRoom.alerts.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/incidents` (`control-room.incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/incidents/create-alert` (`control-room.incidents.create-alert`, action `createAlertFromIncident`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:244-336`; `source_type`.
3. Invoke only the owning control for `POST control-room/incidents/flag` (`control-room.incidents.flag`, action `flagAsIncident`). Source category: **escalated/flagged**; controller `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:346-434`; `client_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0265` at `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `createAlertFromIncident` / `ROUTE-0266` at `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:244`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `flagAsIncident` / `ROUTE-0267` at `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:346`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/control-room/incidents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0266` / `createAlertFromIncident`: fields `source_type`; success app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:334 `->with('success', 'Alert created from incident.')`.
- `ROUTE-0267` / `flagAsIncident`: fields `client_id`; success app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:431 `->with('success', "Incident INC-{$result['incident']->id} flagged and alert raised.")`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:310 `$alert = ControlRoomAlert::create($alertData);`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:313 `\App\Models\ControlRoom\AlertQueue::create([`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:364 `$incident = ClientIncident::create([`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:399 `$alert = ControlRoomAlert::create($alertData);`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:402 `\App\Models\ControlRoom\AlertQueue::create([`; responses app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:224 `return Inertia::render('control-room/incidents', [`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:333 `return back()`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:421 `return ['incident' => $incident, 'alert' => $alert];`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:430 `return back()`; audit calls app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:327 `AuditLogger::log('controlRoom.alert.createFromIncident', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:424 `AuditLogger::log('controlRoom.alert.flagAsIncident', $result['alert'], [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD control-room/incidents` — `control-room.incidents.index` — `App\Http\Controllers\ControlRoom\ControlRoomIncidentController@index` — `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:28` — middleware `web, auth, permission:controlRoom.viewAny`
- `POST control-room/incidents/create-alert` — `control-room.incidents.create-alert` — `App\Http\Controllers\ControlRoom\ControlRoomIncidentController@createAlertFromIncident` — `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:244` — middleware `web, auth, permission:controlRoom.alerts.create`
- `POST control-room/incidents/flag` — `control-room.incidents.flag` — `App\Http\Controllers\ControlRoom\ControlRoomIncidentController@flagAsIncident` — `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php:346` — middleware `web, auth, permission:controlRoom.alerts.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php`.
- Exact render/action page relationships: `resources/js/pages/control-room/incidents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
