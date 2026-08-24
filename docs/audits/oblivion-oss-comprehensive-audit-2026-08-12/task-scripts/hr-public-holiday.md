# HR-PUBLIC-HOLIDAY: Public Holiday

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`
- Owning module: Human resources
- Legacy family: `HR-PUBLIC-HOLIDAY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/leave/holidays` (`hr.leave.holidays.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.leave.viewAny`, `permission:hr.leave.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/leave/holidays` (`hr.leave.holidays.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/leave/holidays` (`hr.leave.holidays.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PublicHolidayController.php:56-75`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE hr/leave/holidays/{holiday}` (`hr.leave.holidays.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/PublicHolidayController.php:101-115`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/leave/holidays/{holiday}` (`hr.leave.holidays.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PublicHolidayController.php:77-99`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1502` at `app/Http/Controllers/Hr/PublicHolidayController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1503` at `app/Http/Controllers/Hr/PublicHolidayController.php:56`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1504` at `app/Http/Controllers/Hr/PublicHolidayController.php:101`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1505` at `app/Http/Controllers/Hr/PublicHolidayController.php:77`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/leave/holidays.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PublicHolidayController.php:65 `HrPublicHoliday::query()->create([`; app/Http/Controllers/Hr/PublicHolidayController.php:112 `$holiday->delete();`; app/Http/Controllers/Hr/PublicHolidayController.php:90 `$holiday->update([`; responses app/Http/Controllers/Hr/PublicHolidayController.php:44 `return Inertia::render('hr/leave/holidays', [`; app/Http/Controllers/Hr/PublicHolidayController.php:74 `return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);`; app/Http/Controllers/Hr/PublicHolidayController.php:114 `return redirect()->route('hr.leave.holidays.index', ['year' => $year]);`; app/Http/Controllers/Hr/PublicHolidayController.php:98 `return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/leave/holidays` — `hr.leave.holidays.index` — `App\Http\Controllers\Hr\PublicHolidayController@index` — `app/Http/Controllers/Hr/PublicHolidayController.php:21` — middleware `web, auth, permission:hr.leave.viewAny`
- `POST hr/leave/holidays` — `hr.leave.holidays.store` — `App\Http\Controllers\Hr\PublicHolidayController@store` — `app/Http/Controllers/Hr/PublicHolidayController.php:56` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `DELETE hr/leave/holidays/{holiday}` — `hr.leave.holidays.destroy` — `App\Http\Controllers\Hr\PublicHolidayController@destroy` — `app/Http/Controllers/Hr/PublicHolidayController.php:101` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`
- `PUT hr/leave/holidays/{holiday}` — `hr.leave.holidays.update` — `App\Http\Controllers\Hr\PublicHolidayController@update` — `app/Http/Controllers/Hr/PublicHolidayController.php:77` — middleware `web, auth, permission:hr.leave.viewAny, permission:hr.leave.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PublicHolidayController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/leave/holidays.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
