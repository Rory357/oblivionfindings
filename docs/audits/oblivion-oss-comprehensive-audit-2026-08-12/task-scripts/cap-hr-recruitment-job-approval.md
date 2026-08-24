# CAP-HR-RECRUITMENT-JOB-APPROVAL: Recruitment job approval decision

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
2. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/approve` (`hr.jobs.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:272-290`; no exact validation fields extracted.
3. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/reject-approval` (`hr.jobs.reject-approval`, action `rejectApproval`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:293-307`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/recruitment/jobs/{job}/submit-approval` (`hr.jobs.submit-approval`, action `submitForApproval`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/RecruitmentJobController.php:246-269`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1694` at `app/Http/Controllers/Hr/RecruitmentJobController.php:272`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectApproval` / `ROUTE-1697` at `app/Http/Controllers/Hr/RecruitmentJobController.php:293`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitForApproval` / `ROUTE-1698` at `app/Http/Controllers/Hr/RecruitmentJobController.php:246`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1694` / `approve`: success app/Http/Controllers/Hr/RecruitmentJobController.php:289 `return redirect()->back()->with('success', 'Requisition approved and published.');`.
- `ROUTE-1697` / `rejectApproval`: success app/Http/Controllers/Hr/RecruitmentJobController.php:306 `return redirect()->back()->with('success', 'Approval rejected — requisition returned to draft.');`.
- `ROUTE-1698` / `submitForApproval`: success app/Http/Controllers/Hr/RecruitmentJobController.php:268 `return redirect()->back()->with('success', 'Requisition submitted for approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/RecruitmentJobController.php:283 `$job->update([`; app/Http/Controllers/Hr/RecruitmentJobController.php:304 `$job->update(['status' => 'draft', 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/RecruitmentJobController.php:257 `$job->update(['status' => 'pending_approval', 'updated_by' => $user->id]);`; responses app/Http/Controllers/Hr/RecruitmentJobController.php:280 `return redirect()->back()->with('error', 'Only a requisition pending approval can be approved.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:289 `return redirect()->back()->with('success', 'Requisition approved and published.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:301 `return redirect()->back()->with('error', 'Only a requisition pending approval can be rejected.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:306 `return redirect()->back()->with('success', 'Approval rejected — requisition returned to draft.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:254 `return redirect()->back()->with('error', 'Only a draft requisition can be submitted for approval.');`; app/Http/Controllers/Hr/RecruitmentJobController.php:268 `return redirect()->back()->with('success', 'Requisition submitted for approval.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/RecruitmentJobController.php:262 `$manager->notify(new \App\Domain\Hr\Notifications\RequisitionApprovalRequestNotification($job, $user));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/recruitment/jobs/{job}/approve` — `hr.jobs.approve` — `App\Http\Controllers\Hr\RecruitmentJobController@approve` — `app/Http/Controllers/Hr/RecruitmentJobController.php:272` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/reject-approval` — `hr.jobs.reject-approval` — `App\Http\Controllers\Hr\RecruitmentJobController@rejectApproval` — `app/Http/Controllers/Hr/RecruitmentJobController.php:293` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/jobs/{job}/submit-approval` — `hr.jobs.submit-approval` — `App\Http\Controllers\Hr\RecruitmentJobController@submitForApproval` — `app/Http/Controllers/Hr/RecruitmentJobController.php:246` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/RecruitmentJobController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
