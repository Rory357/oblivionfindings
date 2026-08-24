# CAP-CR-CONTROL-ROOM-SETTINGS-SIGNAL-RULES: Signal routing rules

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
2. Invoke only the owning control for `POST control-room/settings/rules` (`control-room.settings.rules.store`, action `storeSignalRule`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:233-261`; `name`, `signal_type_id`, `signal_type_code`, `signal_source_id`, `priority`, `conditions`, `output_severity`, `output_escalation_level`, `output_tier`, `dedup_window_minutes`, `deduplicate`, `suppress_in_maintenance`, `notify_roles`, `notify_users`, `playbook_id`, `is_active`.
3. Invoke only the owning control for `DELETE control-room/settings/rules/{rule}` (`control-room.settings.rules.delete`, action `deleteSignalRule`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:299-308`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT control-room/settings/rules/{rule}` (`control-room.settings.rules.update`, action `updateSignalRule`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:266-294`; `name`, `signal_type_id`, `signal_type_code`, `signal_source_id`, `priority`, `conditions`, `output_severity`, `output_escalation_level`, `output_tier`, `dedup_window_minutes`, `deduplicate`, `suppress_in_maintenance`, `notify_roles`, `notify_users`, `playbook_id`, `is_active`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeSignalRule` / `ROUTE-0299` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:233`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteSignalRule` / `ROUTE-0300` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:299`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSignalRule` / `ROUTE-0301` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:266`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0299` / `storeSignalRule`: fields `name`, `signal_type_id`, `signal_type_code`, `signal_source_id`, `priority`, `conditions`, `output_severity`, `output_escalation_level`, `output_tier`, `dedup_window_minutes`, `deduplicate`, `suppress_in_maintenance`, `notify_roles`, `notify_users`, `playbook_id`, `is_active`; success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:260 `->with('success', 'Signal rule created.');`.
- `ROUTE-0300` / `deleteSignalRule`: success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:307 `->with('success', 'Signal rule deleted.');`.
- `ROUTE-0301` / `updateSignalRule`: fields `name`, `signal_type_id`, `signal_type_code`, `signal_source_id`, `priority`, `conditions`, `output_severity`, `output_escalation_level`, `output_tier`, `dedup_window_minutes`, `deduplicate`, `suppress_in_maintenance`, `notify_roles`, `notify_users`, `playbook_id`, `is_active`; success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:293 `->with('success', 'Signal rule updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:257 `SignalRule::create($validated);`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:304 `$rule->delete();`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:290 `$rule->update($validated);`; responses app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:259 `return redirect()->route('control-room.settings.index', ['tab' => 'rules'])`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:306 `return redirect()->route('control-room.settings.index', ['tab' => 'rules'])`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:292 `return redirect()->route('control-room.settings.index', ['tab' => 'rules'])`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST control-room/settings/rules` — `control-room.settings.rules.store` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@storeSignalRule` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:233` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `DELETE control-room/settings/rules/{rule}` — `control-room.settings.rules.delete` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@deleteSignalRule` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:299` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `PUT control-room/settings/rules/{rule}` — `control-room.settings.rules.update` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@updateSignalRule` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:266` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
