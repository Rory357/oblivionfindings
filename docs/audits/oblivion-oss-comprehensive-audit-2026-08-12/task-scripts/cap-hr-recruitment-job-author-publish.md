# CAP-HR-RECRUITMENT-JOB-AUTHOR-PUBLISH: Recruitment job authoring publication closure and sync

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`
- Owning module: Human resources
- Legacy family: `HR-RECRUITMENT-JOB`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST hr/recruitment/jobs` (`hr.jobs.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:110-161`; `title`.
3. Invoke only the owning control for `PUT hr/recruitment/jobs/{job}` (`hr.jobs.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:163-212`; `title`.
4. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/close` (`hr.jobs.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:230-243`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/publish` (`hr.jobs.publish`, action `publish`). Source category: **mutation outcome source gap (publish)**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:214-228`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/sync-posting` (`hr.jobs.sync-posting`, action `syncPosting`). Source category: **retried/replayed/reconciled**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:309-349`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/unpublish-posting` (`hr.jobs.unpublish-posting`, action `unpublishPosting`). Source category: **mutation outcome source gap (unpublishPosting)**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:351-367`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1692` at `app/Http/Controllers/Hr/RecruitmentJobController.php:110`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1693` at `app/Http/Controllers/Hr/RecruitmentJobController.php:163`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-1695` at `app/Http/Controllers/Hr/RecruitmentJobController.php:230`; it is not runtime-observed.
- **mutation outcome source gap (publish)** is applicable only to `publish` / `ROUTE-1696` at `app/Http/Controllers/Hr/RecruitmentJobController.php:214`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `syncPosting` / `ROUTE-1699` at `app/Http/Controllers/Hr/RecruitmentJobController.php:309`; it is not runtime-observed.
- **mutation outcome source gap (unpublishPosting)** is applicable only to `unpublishPosting` / `ROUTE-1700` at `app/Http/Controllers/Hr/RecruitmentJobController.php:351`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1692` / `store`: fields `title`; success app/Http/Controllers/Hr/RecruitmentJobController.php:160 `return redirect()->back()->with('success', 'Job requisition created.');`.
- `ROUTE-1693` / `update`: fields `title`; success app/Http/Controllers/Hr/RecruitmentJobController.php:211 `return redirect()->back()->with('success', 'Job requisition updated.');`.
- `ROUTE-1695` / `close`: success app/Http/Controllers/Hr/RecruitmentJobController.php:242 `return redirect()->back()->with('success', 'Job closed.');`.
- `ROUTE-1696` / `publish`: success app/Http/Controllers/Hr/RecruitmentJobController.php:227 `return redirect()->back()->with('success', 'Job published to careers page.');`.
- `ROUTE-1699` / `syncPosting`: success app/Http/Controllers/Hr/RecruitmentJobController.php:348 `return redirect()->back()->with('success', 'Marked as posted to the selected channels.');`; failure app/Http/Controllers/Hr/RecruitmentJobController.php:317 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/RecruitmentJobController.php:327 `return redirect()->back()->withErrors([`.
- `ROUTE-1700` / `unpublishPosting`: success app/Http/Controllers/Hr/RecruitmentJobController.php:366 `return redirect()->back()->with('success', 'External posting channels removed.');`.

## Failure and recovery paths

- `syncPosting`: app/Http/Controllers/Hr/RecruitmentJobController.php:317 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/RecruitmentJobController.php:327 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/RecruitmentJobController.php:150 `HrJobRequisition::create([`; app/Http/Controllers/Hr/RecruitmentJobController.php:209 `$job->update($validated);`; app/Http/Controllers/Hr/RecruitmentJobController.php:237 `$job->update([`; app/Http/Controllers/Hr/RecruitmentJobController.php:221 `$job->update([`; app/Http/Controllers/Hr/RecruitmentJobController.php:335 `$job->update([`; app/Http/Controllers/Hr/RecruitmentJobController.php:358 `$job->update([`; responses app/Http/Controllers/Hr/RecruitmentJobController.php:160 `return redirect()->back()->with('success', 'Job requisition created.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:211 `return redirect()->back()->with('success', 'Job requisition updated.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:242 `return redirect()->back()->with('success', 'Job closed.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:227 `return redirect()->back()->with('success', 'Job published to careers page.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:317 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/RecruitmentJobController.php:327 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/RecruitmentJobController.php:348 `return redirect()->back()->with('success', 'Marked as posted to the selected channels.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:366 `return redirect()->back()->with('success', 'External posting channels removed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/recruitment/jobs` — `hr.jobs.store` — `App\Http\Controllers\Hr\RecruitmentJobController@store` — `app/Http/Controllers/Hr/RecruitmentJobController.php:110` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `PUT hr/recruitment/jobs/{job}` — `hr.jobs.update` — `App\Http\Controllers\Hr\RecruitmentJobController@update` — `app/Http/Controllers/Hr/RecruitmentJobController.php:163` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/close` — `hr.jobs.close` — `App\Http\Controllers\Hr\RecruitmentJobController@close` — `app/Http/Controllers/Hr/RecruitmentJobController.php:230` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/publish` — `hr.jobs.publish` — `App\Http\Controllers\Hr\RecruitmentJobController@publish` — `app/Http/Controllers/Hr/RecruitmentJobController.php:214` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/sync-posting` — `hr.jobs.sync-posting` — `App\Http\Controllers\Hr\RecruitmentJobController@syncPosting` — `app/Http/Controllers/Hr/RecruitmentJobController.php:309` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/unpublish-posting` — `hr.jobs.unpublish-posting` — `App\Http\Controllers\Hr\RecruitmentJobController@unpublishPosting` — `app/Http/Controllers/Hr/RecruitmentJobController.php:351` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/RecruitmentJobController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
