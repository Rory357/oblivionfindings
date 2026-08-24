# SET-APPEARANCE: Appearance

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Settings and system access
- Legacy family: `SET-APPEARANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/appearance` (`appearance.edit`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/appearance` (`appearance.edit`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/appearance` (`appearance.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/AppearanceController.php:41-77`; `theme`.
3. Invoke only the owning control for `POST settings/appearance/reset` (`appearance.reset`, action `reset`). Source category: **mutation outcome source gap (reset)**; controller `app/Http/Controllers/Settings/AppearanceController.php:82-96`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-2623` at `app/Http/Controllers/Settings/AppearanceController.php:30`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2624` at `app/Http/Controllers/Settings/AppearanceController.php:41`; it is not runtime-observed.
- **mutation outcome source gap (reset)** is applicable only to `reset` / `ROUTE-2625` at `app/Http/Controllers/Settings/AppearanceController.php:82`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/appearance.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2624` / `update`: fields `theme`; success app/Http/Controllers/Settings/AppearanceController.php:76 `->with('success', 'Appearance preferences saved.');`.
- `ROUTE-2625` / `reset`: success app/Http/Controllers/Settings/AppearanceController.php:95 `return redirect()->back()->with('success', 'Appearance reset to defaults.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/AppearanceController.php:71 `$user->fill($payload)->save();`; app/Http/Controllers/Settings/AppearanceController.php:93 `])->save();`; responses app/Http/Controllers/Settings/AppearanceController.php:35 `return Inertia::render('settings/appearance', [`; app/Http/Controllers/Settings/AppearanceController.php:74 `return redirect()`; app/Http/Controllers/Settings/AppearanceController.php:95 `return redirect()->back()->with('success', 'Appearance reset to defaults.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/appearance` — `appearance.edit` — `App\Http\Controllers\Settings\AppearanceController@edit` — `app/Http/Controllers/Settings/AppearanceController.php:30` — middleware `web, auth`
- `PUT settings/appearance` — `appearance.update` — `App\Http\Controllers\Settings\AppearanceController@update` — `app/Http/Controllers/Settings/AppearanceController.php:41` — middleware `web, auth`
- `POST settings/appearance/reset` — `appearance.reset` — `App\Http\Controllers\Settings\AppearanceController@reset` — `app/Http/Controllers/Settings/AppearanceController.php:82` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/AppearanceController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/appearance.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
