# CAP-HR-SUCCESSION-CANDIDATES: Succession candidates and talent-pool nomination

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-SUCCESSION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/succession` (`hr.succession.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/succession` (`hr.succession.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/succession/{plan}/candidates` (`hr.succession.candidates.store`, action `addCandidate`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/SuccessionController.php:262-286`; `employee_profile_id`.
3. Invoke only the owning control for `DELETE hr/succession/candidates/{candidate}` (`hr.succession.candidates.destroy`, action `removeCandidate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/SuccessionController.php:327-335`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/succession/candidates/{candidate}` (`hr.succession.candidates.update`, action `updateCandidate`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/SuccessionController.php:291-309`; `readiness`.
5. Invoke only the owning control for `POST hr/succession/candidates/{candidate}/nominate` (`hr.succession.candidates.nominate`, action `nominateToTalentPool`). Source category: **mutation outcome source gap (nominateToTalentPool)**; controller `app/Http/Controllers/Hr/SuccessionController.php:341-353`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `addCandidate` / `ROUTE-1764` at `app/Http/Controllers/Hr/SuccessionController.php:262`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeCandidate` / `ROUTE-1765` at `app/Http/Controllers/Hr/SuccessionController.php:327`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCandidate` / `ROUTE-1766` at `app/Http/Controllers/Hr/SuccessionController.php:291`; it is not runtime-observed.
- **mutation outcome source gap (nominateToTalentPool)** is applicable only to `nominateToTalentPool` / `ROUTE-1767` at `app/Http/Controllers/Hr/SuccessionController.php:341`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1764` / `addCandidate`: fields `employee_profile_id`; success app/Http/Controllers/Hr/SuccessionController.php:285 `return redirect()->back()->with('success', 'Candidate added to succession plan.');`.
- `ROUTE-1765` / `removeCandidate`: success app/Http/Controllers/Hr/SuccessionController.php:334 `return redirect()->back()->with('success', 'Candidate removed from plan.');`.
- `ROUTE-1766` / `updateCandidate`: fields `readiness`; success app/Http/Controllers/Hr/SuccessionController.php:308 `return redirect()->back()->with('success', 'Candidate updated.');`.
- `ROUTE-1767` / `nominateToTalentPool`: success app/Http/Controllers/Hr/SuccessionController.php:352 `return redirect()->back()->with('success', 'Candidate nominated to the ready-now talent pool.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/SuccessionController.php:275 `$plan->candidates()->create([`; app/Http/Controllers/Hr/SuccessionController.php:332 `$candidate->delete();`; app/Http/Controllers/Hr/SuccessionController.php:303 `$candidate->update(array_merge($data, [`; app/Http/Controllers/Hr/SuccessionController.php:346 `$candidate->update([`; responses app/Http/Controllers/Hr/SuccessionController.php:285 `return redirect()->back()->with('success', 'Candidate added to succession plan.');`; app/Http/Controllers/Hr/SuccessionController.php:334 `return redirect()->back()->with('success', 'Candidate removed from plan.');`; app/Http/Controllers/Hr/SuccessionController.php:308 `return redirect()->back()->with('success', 'Candidate updated.');`; app/Http/Controllers/Hr/SuccessionController.php:352 `return redirect()->back()->with('success', 'Candidate nominated to the ready-now talent pool.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/succession/{plan}/candidates` — `hr.succession.candidates.store` — `App\Http\Controllers\Hr\SuccessionController@addCandidate` — `app/Http/Controllers/Hr/SuccessionController.php:262` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `DELETE hr/succession/candidates/{candidate}` — `hr.succession.candidates.destroy` — `App\Http\Controllers\Hr\SuccessionController@removeCandidate` — `app/Http/Controllers/Hr/SuccessionController.php:327` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/succession/candidates/{candidate}` — `hr.succession.candidates.update` — `App\Http\Controllers\Hr\SuccessionController@updateCandidate` — `app/Http/Controllers/Hr/SuccessionController.php:291` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/succession/candidates/{candidate}/nominate` — `hr.succession.candidates.nominate` — `App\Http\Controllers\Hr\SuccessionController@nominateToTalentPool` — `app/Http/Controllers/Hr/SuccessionController.php:341` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/SuccessionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
