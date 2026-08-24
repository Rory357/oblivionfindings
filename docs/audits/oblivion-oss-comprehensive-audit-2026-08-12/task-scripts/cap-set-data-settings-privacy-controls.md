# CAP-SET-DATA-SETTINGS-PRIVACY-CONTROLS: Privacy control configuration

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-DATA-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/data` (`settings.data`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/data` (`settings.data`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/data/privacy` (`settings.data.privacy.update`, action `updatePrivacy`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/DataSettingsController.php:150-171`; `anonymisation`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2641` at `app/Http/Controllers/Settings/DataSettingsController.php:91`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePrivacy` / `ROUTE-2644` at `app/Http/Controllers/Settings/DataSettingsController.php:150`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/data.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2644` / `updatePrivacy`: fields `anonymisation`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Settings/DataSettingsController.php:95 `return Inertia::render('settings/data', [`; app/Http/Controllers/Settings/DataSettingsController.php:167 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/data` — `settings.data` — `App\Http\Controllers\Settings\DataSettingsController@index` — `app/Http/Controllers/Settings/DataSettingsController.php:91` — middleware `web, auth, permission:settings.access.manage`
- `PUT settings/data/privacy` — `settings.data.privacy.update` — `App\Http\Controllers\Settings\DataSettingsController@updatePrivacy` — `app/Http/Controllers/Settings/DataSettingsController.php:150` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/DataSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/data.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
