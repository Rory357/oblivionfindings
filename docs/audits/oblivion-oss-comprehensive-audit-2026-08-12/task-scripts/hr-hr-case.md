# HR-HR-CASE: Hr Case

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.cases.view`, `permission:hr.cases.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-CASE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/cases` (`hr.cases.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.cases.view`, `permission:hr.cases.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.cases.view`, `permission:hr.cases.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/cases` (`hr.cases.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/cases/{case}` (`hr.cases.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrCaseController.php:236-336`.
3. Use `GET|HEAD hr/cases/{case}/events/create` (`hr.cases.events.create`, action `createEvent`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrCaseController.php:221-229`.
4. Use `GET|HEAD hr/cases/create` (`hr.cases.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/HrCaseController.php:209-215`.
5. Invoke only the owning control for `POST hr/cases` (`hr.cases.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrCaseController.php:341-372`; `user_id`.
6. Invoke only the owning control for `PUT hr/cases/{case}` (`hr.cases.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/HrCaseController.php:377-403`; `case_type`.
7. Invoke only the owning control for `POST hr/cases/{case}/close` (`hr.cases.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/HrCaseController.php:436-457`; `outcome`.
8. Invoke only the owning control for `POST hr/cases/{case}/events` (`hr.cases.events.store`, action `storeEvent`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/HrCaseController.php:408-431`; `event_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1305` at `app/Http/Controllers/Hr/HrCaseController.php:57`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1306` at `app/Http/Controllers/Hr/HrCaseController.php:341`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1307` at `app/Http/Controllers/Hr/HrCaseController.php:236`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1308` at `app/Http/Controllers/Hr/HrCaseController.php:377`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1309` at `app/Http/Controllers/Hr/HrCaseController.php:436`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeEvent` / `ROUTE-1312` at `app/Http/Controllers/Hr/HrCaseController.php:408`; it is not runtime-observed.
- **information presented** is applicable only to `createEvent` / `ROUTE-1313` at `app/Http/Controllers/Hr/HrCaseController.php:221`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1314` at `app/Http/Controllers/Hr/HrCaseController.php:209`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/cases/index.tsx`, `resources/js/pages/hr/cases/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1306` / `store`: fields `user_id`; success app/Http/Controllers/Hr/HrCaseController.php:371 `return redirect()->back()->with('success', 'HR case opened.');`.
- `ROUTE-1308` / `update`: fields `case_type`; success app/Http/Controllers/Hr/HrCaseController.php:402 `return redirect()->back()->with('success', 'HR case updated.');`.
- `ROUTE-1309` / `close`: fields `outcome`; success app/Http/Controllers/Hr/HrCaseController.php:456 `return redirect()->back()->with('success', 'HR case closed.');`.
- `ROUTE-1312` / `storeEvent`: fields `event_type`; success app/Http/Controllers/Hr/HrCaseController.php:430 `return redirect()->back()->with('success', 'Event added to case timeline.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/HrCaseController.php:361 `HrCase::create([`; app/Http/Controllers/Hr/HrCaseController.php:400 `$case->update($data);`; app/Http/Controllers/Hr/HrCaseController.php:448 `$case->update([`; app/Http/Controllers/Hr/HrCaseController.php:424 `HrCaseEvent::create([`; responses app/Http/Controllers/Hr/HrCaseController.php:179 `return Inertia::render('hr/cases/index', [`; app/Http/Controllers/Hr/HrCaseController.php:371 `return redirect()->back()->with('success', 'HR case opened.');`; app/Http/Controllers/Hr/HrCaseController.php:312 `return Inertia::render('hr/cases/show', [`; app/Http/Controllers/Hr/HrCaseController.php:402 `return redirect()->back()->with('success', 'HR case updated.');`; app/Http/Controllers/Hr/HrCaseController.php:456 `return redirect()->back()->with('success', 'HR case closed.');`; app/Http/Controllers/Hr/HrCaseController.php:430 `return redirect()->back()->with('success', 'Event added to case timeline.');`; app/Http/Controllers/Hr/HrCaseController.php:228 `return redirect()->route('hr.cases.show', ['case' => $case->id, 'new' => 'event']);`; app/Http/Controllers/Hr/HrCaseController.php:214 `return redirect()->route('hr.cases.index', ['new' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/cases` — `hr.cases.index` — `App\Http\Controllers\Hr\HrCaseController@index` — `app/Http/Controllers/Hr/HrCaseController.php:57` — middleware `web, auth, permission:hr.cases.view`
- `POST hr/cases` — `hr.cases.store` — `App\Http\Controllers\Hr\HrCaseController@store` — `app/Http/Controllers/Hr/HrCaseController.php:341` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`
- `GET|HEAD hr/cases/{case}` — `hr.cases.show` — `App\Http\Controllers\Hr\HrCaseController@show` — `app/Http/Controllers/Hr/HrCaseController.php:236` — middleware `web, auth, permission:hr.cases.view`
- `PUT hr/cases/{case}` — `hr.cases.update` — `App\Http\Controllers\Hr\HrCaseController@update` — `app/Http/Controllers/Hr/HrCaseController.php:377` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`
- `POST hr/cases/{case}/close` — `hr.cases.close` — `App\Http\Controllers\Hr\HrCaseController@close` — `app/Http/Controllers/Hr/HrCaseController.php:436` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`
- `POST hr/cases/{case}/events` — `hr.cases.events.store` — `App\Http\Controllers\Hr\HrCaseController@storeEvent` — `app/Http/Controllers/Hr/HrCaseController.php:408` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`
- `GET|HEAD hr/cases/{case}/events/create` — `hr.cases.events.create` — `App\Http\Controllers\Hr\HrCaseController@createEvent` — `app/Http/Controllers/Hr/HrCaseController.php:221` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`
- `GET|HEAD hr/cases/create` — `hr.cases.create` — `App\Http\Controllers\Hr\HrCaseController@create` — `app/Http/Controllers/Hr/HrCaseController.php:209` — middleware `web, auth, permission:hr.cases.view, permission:hr.cases.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/HrCaseController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/cases/index.tsx`, `resources/js/pages/hr/cases/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
