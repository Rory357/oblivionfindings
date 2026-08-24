# CAP-HR-CANDIDATE-REFERENCES: Reference capture and review

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`
- Owning module: Human resources
- Legacy family: `HR-CANDIDATE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/recruitment/applications/{application}/offer/create` (`hr.offers.create`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.recruitment.view`, `permission:hr.recruitment.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/recruitment/applications/{application}/offer/create` (`hr.offers.create`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/recruitment/applications/{application}/references` (`hr.references.store`, action `storeReference`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:1048-1088`; `referee_name`.
3. Invoke only the owning control for `PUT hr/recruitment/references/{reference}` (`hr.references.update`, action `updateReference`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CandidateController.php:1090-1117`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeReference` / `ROUTE-1671` at `app/Http/Controllers/Hr/CandidateController.php:1048`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateReference` / `ROUTE-1715` at `app/Http/Controllers/Hr/CandidateController.php:1090`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1671` / `storeReference`: fields `referee_name`; success app/Http/Controllers/Hr/CandidateController.php:1085 `return redirect()->back()->with('success', $reference->referee_email`.
- `ROUTE-1715` / `updateReference`: success app/Http/Controllers/Hr/CandidateController.php:1116 `return redirect()->back()->with('success', 'Reference check updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:1063 `$reference = HrReferenceCheck::create([`; app/Http/Controllers/Hr/CandidateController.php:1108 `$reference->update([`; responses app/Http/Controllers/Hr/CandidateController.php:1085 `return redirect()->back()->with('success', $reference->referee_email`; app/Http/Controllers/Hr/CandidateController.php:1116 `return redirect()->back()->with('success', 'Reference check updated.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/CandidateController.php:1079 `->notify(new ReferenceRequestNotification($reference, $candidate?->full_name ?? 'a candidate'));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/recruitment/applications/{application}/references` — `hr.references.store` — `App\Http\Controllers\Hr\CandidateController@storeReference` — `app/Http/Controllers/Hr/CandidateController.php:1048` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `PUT hr/recruitment/references/{reference}` — `hr.references.update` — `App\Http\Controllers\Hr\CandidateController@updateReference` — `app/Http/Controllers/Hr/CandidateController.php:1090` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
