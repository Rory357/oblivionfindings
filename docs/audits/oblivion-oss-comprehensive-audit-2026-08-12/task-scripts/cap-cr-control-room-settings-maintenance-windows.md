# CAP-CR-CONTROL-ROOM-SETTINGS-MAINTENANCE-WINDOWS: Control Room maintenance windows

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
2. Invoke only the owning control for `POST control-room/settings/maintenance` (`control-room.settings.maintenance.store`, action `storeMaintenanceWindow`). Source category: **created/recorded**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:381-402`; `name`, `description`, `signal_source_id`, `site_id`, `starts_at`, `ends_at`.
3. Invoke only the owning control for `PUT control-room/settings/maintenance/{window}` (`control-room.settings.maintenance.update`, action `updateMaintenanceWindow`). Source category: **updated/revised**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:407-427`; `name`, `description`, `signal_source_id`, `site_id`, `starts_at`, `ends_at`.
4. Invoke only the owning control for `POST control-room/settings/maintenance/{window}/cancel` (`control-room.settings.maintenance.cancel`, action `cancelMaintenanceWindow`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:432-443`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeMaintenanceWindow` / `ROUTE-0291` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:381`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateMaintenanceWindow` / `ROUTE-0292` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:407`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelMaintenanceWindow` / `ROUTE-0293` at `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:432`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0291` / `storeMaintenanceWindow`: fields `name`, `description`, `signal_source_id`, `site_id`, `starts_at`, `ends_at`; success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:401 `->with('success', 'Maintenance window scheduled.');`.
- `ROUTE-0292` / `updateMaintenanceWindow`: fields `name`, `description`, `signal_source_id`, `site_id`, `starts_at`, `ends_at`; success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:426 `->with('success', 'Maintenance window updated.');`.
- `ROUTE-0293` / `cancelMaintenanceWindow`: success app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:442 `->with('success', 'Maintenance window cancelled.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:398 `MaintenanceWindow::create($validated);`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:423 `$window->update($validated);`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:439 `$window->update(['status' => 'cancelled']);`; responses app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:400 `return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:425 `return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])`; app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:441 `return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST control-room/settings/maintenance` — `control-room.settings.maintenance.store` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@storeMaintenanceWindow` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:381` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `PUT control-room/settings/maintenance/{window}` — `control-room.settings.maintenance.update` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@updateMaintenanceWindow` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:407` — middleware `web, auth, permission:controlRoom.alerts.manage`
- `POST control-room/settings/maintenance/{window}/cancel` — `control-room.settings.maintenance.cancel` — `App\Http\Controllers\ControlRoom\ControlRoomSettingsController@cancelMaintenanceWindow` — `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php:432` — middleware `web, auth, permission:controlRoom.alerts.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
