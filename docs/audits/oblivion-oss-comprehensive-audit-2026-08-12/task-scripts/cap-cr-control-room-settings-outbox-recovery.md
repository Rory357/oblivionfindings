# CAP-CR-CONTROL-ROOM-SETTINGS-OUTBOX-RECOVERY: Failed signal outbox recovery

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`
- Owning module: Control Room
- Legacy family: `CR-CONTROL-ROOM-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `control-room/settings` (`control-room.settings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:controlRoom.alerts.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:controlRoom.alerts.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD control-room/settings` (`control-room.settings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST control-room/settings/signal-outbox/{outbox}/retry` (`control-room.settings.signal-outbox.retry`, action `retrySignalOutbox`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:199-228`; no exact validation fields extracted.

## Source-applicable states and transitions

- **retried/replayed/reconciled** is applicable only to `retrySignalOutbox` / `ROUTE-0302` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:199`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0302` / `retrySignalOutbox`: success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:227 `->with('success', 'Signal outbox retry queued.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:211 `$outbox->update([`; responses app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:225 `return redirect()`; audit calls app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:218 `AuditLogger::log('controlRoom.signalOutbox.retry', $outbox, [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:216 `DispatchFleetSignalOutbox::dispatch($outbox->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST control-room/settings/signal-outbox/{outbox}/retry` — `control-room.settings.signal-outbox.retry` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@retrySignalOutbox` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:199` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
