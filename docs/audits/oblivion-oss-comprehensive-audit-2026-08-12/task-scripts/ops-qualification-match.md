# OPS-QUALIFICATION-MATCH: Qualification Match

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-QUALIFICATION-MATCH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/qualifications` (`operations.qualifications.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:rostering.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/qualifications` (`operations.qualifications.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/qualifications/check/{shift}` (`operations.qualifications.check`, action `checkShift`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/QualificationMatchController.php:126-169`.
3. Invoke only the owning control for `POST operations/qualifications` (`operations.qualifications.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/QualificationMatchController.php:59-83`; `client_id`.
4. Invoke only the owning control for `DELETE operations/qualifications/{requirement}` (`operations.qualifications.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/QualificationMatchController.php:112-124`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT operations/qualifications/{requirement}` (`operations.qualifications.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/QualificationMatchController.php:85-110`; `qualification_name`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2131` at `app/Http/Controllers/Operations/QualificationMatchController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2132` at `app/Http/Controllers/Operations/QualificationMatchController.php:59`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2133` at `app/Http/Controllers/Operations/QualificationMatchController.php:112`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2134` at `app/Http/Controllers/Operations/QualificationMatchController.php:85`; it is not runtime-observed.
- **information presented** is applicable only to `checkShift` / `ROUTE-2135` at `app/Http/Controllers/Operations/QualificationMatchController.php:126`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/qualifications/CheckShift.tsx`, `resources/js/pages/operations/qualifications/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2131` / `index`: fields `q`.
- `ROUTE-2132` / `store`: fields `client_id`; success app/Http/Controllers/Operations/QualificationMatchController.php:82 `return redirect()->back()->with('success', 'Qualification requirement added.');`.
- `ROUTE-2133` / `destroy`: success app/Http/Controllers/Operations/QualificationMatchController.php:123 `return redirect()->back()->with('success', 'Qualification requirement removed.');`.
- `ROUTE-2134` / `update`: fields `qualification_name`; success app/Http/Controllers/Operations/QualificationMatchController.php:109 `return redirect()->back()->with('success', 'Qualification requirement updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/QualificationMatchController.php:73 `StaffQualificationRequirement::create([`; app/Http/Controllers/Operations/QualificationMatchController.php:121 `$requirement->delete();`; app/Http/Controllers/Operations/QualificationMatchController.php:102 `$requirement->update(array_filter([`; responses app/Http/Controllers/Operations/QualificationMatchController.php:51 `return inertia('operations/qualifications/Index', [`; app/Http/Controllers/Operations/QualificationMatchController.php:82 `return redirect()->back()->with('success', 'Qualification requirement added.');`; app/Http/Controllers/Operations/QualificationMatchController.php:123 `return redirect()->back()->with('success', 'Qualification requirement removed.');`; app/Http/Controllers/Operations/QualificationMatchController.php:109 `return redirect()->back()->with('success', 'Qualification requirement updated.');`; app/Http/Controllers/Operations/QualificationMatchController.php:164 `return inertia('operations/qualifications/CheckShift', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/qualifications` — `operations.qualifications.index` — `App\Http\Controllers\Operations\QualificationMatchController@index` — `app/Http/Controllers/Operations/QualificationMatchController.php:13` — middleware `web, auth, permission:rostering.viewAny`
- `POST operations/qualifications` — `operations.qualifications.store` — `App\Http\Controllers\Operations\QualificationMatchController@store` — `app/Http/Controllers/Operations/QualificationMatchController.php:59` — middleware `web, auth, permission:rostering.viewAny`
- `DELETE operations/qualifications/{requirement}` — `operations.qualifications.destroy` — `App\Http\Controllers\Operations\QualificationMatchController@destroy` — `app/Http/Controllers/Operations/QualificationMatchController.php:112` — middleware `web, auth, permission:rostering.viewAny`
- `PUT operations/qualifications/{requirement}` — `operations.qualifications.update` — `App\Http\Controllers\Operations\QualificationMatchController@update` — `app/Http/Controllers/Operations/QualificationMatchController.php:85` — middleware `web, auth, permission:rostering.viewAny`
- `GET|HEAD operations/qualifications/check/{shift}` — `operations.qualifications.check` — `App\Http\Controllers\Operations\QualificationMatchController@checkShift` — `app/Http/Controllers/Operations/QualificationMatchController.php:126` — middleware `web, auth, permission:rostering.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/QualificationMatchController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/qualifications/CheckShift.tsx`, `resources/js/pages/operations/qualifications/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
