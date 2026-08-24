# CAP-HR-CANDIDATE-INTERVIEWS: Interview scheduling and scoring

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
2. Invoke only the owning control for `POST hr/recruitment/applications/{application}/interviews` (`hr.interviews.store`, action `storeInterview`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CandidateController.php:917-953`; `scheduled_at`.
3. Invoke only the owning control for `PUT hr/recruitment/interviews/{interview}` (`hr.interviews.update`, action `updateInterview`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CandidateController.php:955-980`; no exact validation fields extracted.
4. Invoke only the owning control for `POST hr/recruitment/interviews/{interview}/score` (`hr.interviews.score`, action `scoreInterview`). Source category: **mutation outcome source gap (scoreInterview)**; controller `app/Http/Controllers/Hr/CandidateController.php:982-1042`; `overall_score`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeInterview` / `ROUTE-1669` at `app/Http/Controllers/Hr/CandidateController.php:917`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateInterview` / `ROUTE-1689` at `app/Http/Controllers/Hr/CandidateController.php:955`; it is not runtime-observed.
- **mutation outcome source gap (scoreInterview)** is applicable only to `scoreInterview` / `ROUTE-1690` at `app/Http/Controllers/Hr/CandidateController.php:982`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1669` / `storeInterview`: fields `scheduled_at`; success app/Http/Controllers/Hr/CandidateController.php:952 `return redirect()->back()->with('success', 'Interview scheduled — calendar invites emailed to the candidate and panel.');`.
- `ROUTE-1689` / `updateInterview`: success app/Http/Controllers/Hr/CandidateController.php:979 `return redirect()->back()->with('success', 'Interview updated.');`.
- `ROUTE-1690` / `scoreInterview`: fields `overall_score`; success app/Http/Controllers/Hr/CandidateController.php:1041 `return redirect()->back()->with('success', 'Interview scorecard saved.');`; failure app/Http/Controllers/Hr/CandidateController.php:1007 `return redirect()->back()->withErrors(['criteria_scores' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:1016 `return redirect()->back()->withErrors(['overall_score' => 'Provide an overall score or structured criteria scores.']);`.

## Failure and recovery paths

- `scoreInterview`: app/Http/Controllers/Hr/CandidateController.php:1007 `return redirect()->back()->withErrors(['criteria_scores' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:1016 `return redirect()->back()->withErrors(['overall_score' => 'Provide an overall score or structured criteria scores.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:935 `$interview = HrInterview::create([`; app/Http/Controllers/Hr/CandidateController.php:971 `$interview->update([`; app/Http/Controllers/Hr/CandidateController.php:1035 `$interview->update([`; responses app/Http/Controllers/Hr/CandidateController.php:952 `return redirect()->back()->with('success', 'Interview scheduled — calendar invites emailed to the candidate and panel.');`; app/Http/Controllers/Hr/CandidateController.php:979 `return redirect()->back()->with('success', 'Interview updated.');`; app/Http/Controllers/Hr/CandidateController.php:1007 `return redirect()->back()->withErrors(['criteria_scores' => $exception->getMessage()]);`; app/Http/Controllers/Hr/CandidateController.php:1016 `return redirect()->back()->withErrors(['overall_score' => 'Provide an overall score or structured criteria scores.']);`; app/Http/Controllers/Hr/CandidateController.php:1041 `return redirect()->back()->with('success', 'Interview scorecard saved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/recruitment/applications/{application}/interviews` — `hr.interviews.store` — `App\Http\Controllers\Hr\CandidateController@storeInterview` — `app/Http/Controllers/Hr/CandidateController.php:917` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `PUT hr/recruitment/interviews/{interview}` — `hr.interviews.update` — `App\Http\Controllers\Hr\CandidateController@updateInterview` — `app/Http/Controllers/Hr/CandidateController.php:955` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/interviews/{interview}/score` — `hr.interviews.score` — `App\Http\Controllers\Hr\CandidateController@scoreInterview` — `app/Http/Controllers/Hr/CandidateController.php:982` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
