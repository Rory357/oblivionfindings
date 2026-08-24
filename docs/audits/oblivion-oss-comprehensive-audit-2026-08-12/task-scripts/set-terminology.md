# SET-TERMINOLOGY: Terminology

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.terminology.manage`
- Owning module: Settings and system access
- Legacy family: `SET-TERMINOLOGY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/terminology` (`settings.terminology`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.terminology.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.terminology.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/terminology` (`settings.terminology`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT settings/terminology` (`settings.terminology.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Settings/TerminologyController.php:29-56`; `labels`.

## Source-applicable states and transitions

- **information presented** is applicable only to `edit` / `ROUTE-2698` at `app/Http/Controllers/Settings/TerminologyController.php:11`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2699` at `app/Http/Controllers/Settings/TerminologyController.php:29`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/terminology.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2699` / `update`: fields `labels`; success app/Http/Controllers/Settings/TerminologyController.php:55 `return redirect()->back()->with('success', 'Terminology updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/TerminologyController.php:45 `AppSetting::query()->where('key', $dbKey)->delete();`; app/Http/Controllers/Settings/TerminologyController.php:49 `AppSetting::updateOrCreate(`; responses app/Http/Controllers/Settings/TerminologyController.php:23 `return inertia('settings/terminology', [`; app/Http/Controllers/Settings/TerminologyController.php:55 `return redirect()->back()->with('success', 'Terminology updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/terminology` — `settings.terminology` — `App\Http\Controllers\Settings\TerminologyController@edit` — `app/Http/Controllers/Settings/TerminologyController.php:11` — middleware `web, auth, permission:settings.terminology.manage`
- `PUT settings/terminology` — `settings.terminology.update` — `App\Http\Controllers\Settings\TerminologyController@update` — `app/Http/Controllers/Settings/TerminologyController.php:29` — middleware `web, auth, permission:settings.terminology.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/TerminologyController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/terminology.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
