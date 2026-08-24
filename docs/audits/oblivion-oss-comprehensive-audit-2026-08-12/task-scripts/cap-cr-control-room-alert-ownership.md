# CAP-CR-CONTROL-ROOM-ALERT-OWNERSHIP: Alert ownership and bulk assignment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.assign`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-ALERT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/alerts` (`control-room.alerts.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.assign`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.assign`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/alerts` (`control-room.alerts.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/alerts/{alert}/assign` (`control-room.alerts.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:622-671`; `assigned_to_user_id`.
3. Invoke only the owning control for `POST control-room/alerts/{alert}/assign-to-me` (`control-room.alerts.assign-to-me`, action `assignToMe`). Source category: **assigned**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:396-420`; no exact validation fields extracted.
4. Invoke only the owning control for `POST control-room/alerts/{alert}/unassign` (`control-room.alerts.unassign`, action `unassign`). Source category: **unassigned**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:676-718`; no exact validation fields extracted.
5. Invoke only the owning control for `POST control-room/alerts/bulk-assign` (`control-room.alerts.bulk-assign`, action `bulkAssign`). Source category: **assigned**; controller `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:341-391`; `alert_ids`.

## Source-applicable states and transitions

- **assigned** is applicable only to `assign` / `ROUTE-0218` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:622`; it is not runtime-observed.
- **assigned** is applicable only to `assignToMe` / `ROUTE-0219` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:396`; it is not runtime-observed.
- **unassigned** is applicable only to `unassign` / `ROUTE-0241` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:676`; it is not runtime-observed.
- **assigned** is applicable only to `bulkAssign` / `ROUTE-0247` at `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:341`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0218` / `assign`: fields `assigned_to_user_id`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:670 `return back()->with('success', 'Alert assigned.');`; failure app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:629 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `ROUTE-0219` / `assignToMe`: success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:419 `return back()->with('success', 'Alert assigned to you.');`; failure app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:403 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `ROUTE-0241` / `unassign`: success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:717 `return back()->with('success', 'Alert unassigned.');`; failure app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:683 `return back()->withErrors(['alert' => "Cannot unassign an alert in '{$alert->status}' status."]);`.
- `ROUTE-0247` / `bulkAssign`: fields `alert_ids`; success app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:390 `return back()->with('success', $message);`.

## Failure and recovery paths

- `assign`: app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:629 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `assignToMe`: app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:403 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`.
- `unassign`: app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:683 `return back()->withErrors(['alert' => "Cannot unassign an alert in '{$alert->status}' status."]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:654 `$alert->update([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:406 `$alert->update([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:702 `$alert->update([`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:369 `$alert->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:629 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:670 `return back()->with('success', 'Alert assigned.');`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:403 `return back()->withErrors(['alert' => "Cannot assign an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:419 `return back()->with('success', 'Alert assigned to you.');`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:683 `return back()->withErrors(['alert' => "Cannot unassign an alert in '{$alert->status}' status."]);`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:717 `return back()->with('success', 'Alert unassigned.');`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:390 `return back()->with('success', $message);`; audit calls app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:664 `AuditLogger::log('controlRoom.alert.assign', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:412 `AuditLogger::log('controlRoom.alert.assign', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:711 `AuditLogger::log('controlRoom.alert.unassign', $alert, [`; app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:375 `AuditLogger::log('controlRoom.alert.assign', $alert, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST control-room/alerts/{alert}/assign` — `control-room.alerts.assign` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@assign` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:622` — middleware `web, auth, permission:controlRoom.alerts.assign`
- `POST control-room/alerts/{alert}/assign-to-me` — `control-room.alerts.assign-to-me` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@assignToMe` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:396` — middleware `web, auth, permission:controlRoom.alerts.assign`
- `POST control-room/alerts/{alert}/unassign` — `control-room.alerts.unassign` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@unassign` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:676` — middleware `web, auth, permission:controlRoom.alerts.assign`
- `POST control-room/alerts/bulk-assign` — `control-room.alerts.bulk-assign` — `App\Http\Controllers\ControlRoom\ControlRoomAlertController@bulkAssign` — `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php:341` — middleware `web, auth, permission:controlRoom.alerts.assign`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomAlertController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
