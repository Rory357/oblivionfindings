# SET-MODULE-SETTINGS: Module Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-MODULE-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/modules` (`settings.modules`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/modules` (`settings.modules`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/modules` (`settings.modules.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/ModuleSettingsController.php:41-64`; `module_states`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2655` at `app/Http/Controllers/Settings/ModuleSettingsController.php:31`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2656` at `app/Http/Controllers/Settings/ModuleSettingsController.php:41`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/modules.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2656` / `update`: fields `module_states`; success app/Http/Controllers/Settings/ModuleSettingsController.php:63 `return back()->with('success', 'Module settings updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/ModuleSettingsController.php:53 `AppSetting::updateOrCreate(`; app/Http/Controllers/Settings/ModuleSettingsController.php:58 `AppSetting::updateOrCreate(`; responses app/Http/Controllers/Settings/ModuleSettingsController.php:35 `return inertia('settings/modules', [`; app/Http/Controllers/Settings/ModuleSettingsController.php:63 `return back()->with('success', 'Module settings updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/modules` — `settings.modules` — `App\Http\Controllers\Settings\ModuleSettingsController@index` — `app/Http/Controllers/Settings/ModuleSettingsController.php:31` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/modules` — `settings.modules.update` — `App\Http\Controllers\Settings\ModuleSettingsController@update` — `app/Http/Controllers/Settings/ModuleSettingsController.php:41` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/ModuleSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/modules.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
