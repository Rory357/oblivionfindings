# CAP-HR-CANDIDATE-COMMUNICATIONS: Recruitment communications and templates

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
2. Invoke only the owning control for `POST hr/recruitment/candidates/bulk-email` (`hr.candidates.bulk-email`, action `bulkEmail`). Source category: **mutation outcome source gap (bulkEmail)**; controller `app/Http/Controllers/Hr/CandidateController.php:740-787`; `candidate_ids`.
3. Invoke only the owning control for `POST hr/recruitment/email-templates` (`hr.email-templates.store`, action `storeEmailTemplate`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:790-811`; `name`.
4. Invoke only the owning control for `DELETE hr/recruitment/email-templates/{template}` (`hr.email-templates.destroy`, action `destroyEmailTemplate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/CandidateController.php:813-823`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (bulkEmail)** is applicable only to `bulkEmail` / `ROUTE-1682` at `app/Http/Controllers/Hr/CandidateController.php:740`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeEmailTemplate` / `ROUTE-1686` at `app/Http/Controllers/Hr/CandidateController.php:790`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyEmailTemplate` / `ROUTE-1687` at `app/Http/Controllers/Hr/CandidateController.php:813`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1682` / `bulkEmail`: fields `candidate_ids`; success app/Http/Controllers/Hr/CandidateController.php:786 `return redirect()->back()->with('success', $message);`.
- `ROUTE-1686` / `storeEmailTemplate`: fields `name`; success app/Http/Controllers/Hr/CandidateController.php:810 `return redirect()->back()->with('success', 'Email template saved.');`.
- `ROUTE-1687` / `destroyEmailTemplate`: success app/Http/Controllers/Hr/CandidateController.php:822 `return redirect()->back()->with('success', 'Email template removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:802 `HrCandidateEmailTemplate::query()->create([`; app/Http/Controllers/Hr/CandidateController.php:820 `$template->delete();`; responses app/Http/Controllers/Hr/CandidateController.php:786 `return redirect()->back()->with('success', $message);`; app/Http/Controllers/Hr/CandidateController.php:810 `return redirect()->back()->with('success', 'Email template saved.');`; app/Http/Controllers/Hr/CandidateController.php:822 `return redirect()->back()->with('success', 'Email template removed.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/CandidateController.php:769 `->notify(new CandidateMessageNotification($candidate, $validated['subject'], $validated['body']));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/recruitment/candidates/bulk-email` — `hr.candidates.bulk-email` — `App\Http\Controllers\Hr\CandidateController@bulkEmail` — `app/Http/Controllers/Hr/CandidateController.php:740` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/email-templates` — `hr.email-templates.store` — `App\Http\Controllers\Hr\CandidateController@storeEmailTemplate` — `app/Http/Controllers/Hr/CandidateController.php:790` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `DELETE hr/recruitment/email-templates/{template}` — `hr.email-templates.destroy` — `App\Http\Controllers\Hr\CandidateController@destroyEmailTemplate` — `app/Http/Controllers/Hr/CandidateController.php:813` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
