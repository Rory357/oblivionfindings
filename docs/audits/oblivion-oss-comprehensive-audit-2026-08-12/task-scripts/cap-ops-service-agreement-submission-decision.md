# CAP-OPS-SERVICE-AGREEMENT-SUBMISSION-DECISION: Service agreement submission approval and rejection

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:service_agreements.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-SERVICE-AGREEMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/service-agreements` (`operations.service_agreements.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:service_agreements.update`.
- Exact middleware atoms: `web`, `auth`, `permission:service_agreements.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/service-agreements` (`operations.service_agreements.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/approve` (`operations.service_agreements.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:425-456`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/reject` (`operations.service_agreements.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:458-491`; `reason`.
4. Invoke only the owning control for `POST operations/service-agreements/{serviceAgreement}/submit-for-approval` (`operations.service_agreements.submit_for_approval`, action `submitForApproval`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ServiceAgreementController.php:394-423`; no exact validation fields extracted.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2175` at `app/Http/Controllers/Operations/ServiceAgreementController.php:425`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-2181` at `app/Http/Controllers/Operations/ServiceAgreementController.php:458`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitForApproval` / `ROUTE-2182` at `app/Http/Controllers/Operations/ServiceAgreementController.php:394`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2175` / `approve`: success app/Http/Controllers/Operations/ServiceAgreementController.php:455 `return redirect()->back()->with('success', 'Agreement approved and now active.');`.
- `ROUTE-2181` / `reject`: fields `reason`; success app/Http/Controllers/Operations/ServiceAgreementController.php:490 `return redirect()->back()->with('success', 'Agreement returned to draft.');`.
- `ROUTE-2182` / `submitForApproval`: success app/Http/Controllers/Operations/ServiceAgreementController.php:422 `return redirect()->back()->with('success', 'Agreement submitted for approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ServiceAgreementController.php:438 `ServiceAgreementStatusChange::create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:447 `$agreement->update([`; app/Http/Controllers/Operations/ServiceAgreementController.php:475 `ServiceAgreementStatusChange::create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:484 `$agreement->update([`; app/Http/Controllers/Operations/ServiceAgreementController.php:407 `ServiceAgreementStatusChange::create([`; app/Http/Controllers/Operations/ServiceAgreementController.php:416 `$agreement->update([`; responses app/Http/Controllers/Operations/ServiceAgreementController.php:455 `return redirect()->back()->with('success', 'Agreement approved and now active.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:490 `return redirect()->back()->with('success', 'Agreement returned to draft.');`; app/Http/Controllers/Operations/ServiceAgreementController.php:422 `return redirect()->back()->with('success', 'Agreement submitted for approval.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/service-agreements/{serviceAgreement}/approve` — `operations.service_agreements.approve` — `App\Http\Controllers\Operations\ServiceAgreementController@approve` — `app/Http/Controllers/Operations/ServiceAgreementController.php:425` — middleware `web, auth, permission:service_agreements.update`
- `POST operations/service-agreements/{serviceAgreement}/reject` — `operations.service_agreements.reject` — `App\Http\Controllers\Operations\ServiceAgreementController@reject` — `app/Http/Controllers/Operations/ServiceAgreementController.php:458` — middleware `web, auth, permission:service_agreements.update`
- `POST operations/service-agreements/{serviceAgreement}/submit-for-approval` — `operations.service_agreements.submit_for_approval` — `App\Http\Controllers\Operations\ServiceAgreementController@submitForApproval` — `app/Http/Controllers/Operations/ServiceAgreementController.php:394` — middleware `web, auth, permission:service_agreements.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ServiceAgreementController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
