# CAP-HR-CANDIDATE-APPLICATION-PROGRESSION: Application progression and rejection

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
2. Invoke only the owning control for `POST hr/recruitment/applications/{application}/advance` (`hr.applications.advance`, action `advanceApplication`). Source category: **mutation outcome source gap (advanceApplication)**; controller `app/Http/Controllers/Hr/CandidateController.php:567-590`; `target_stage`.
3. Invoke only the owning control for `POST hr/recruitment/applications/{application}/reject` (`hr.applications.reject`, action `rejectApplication`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/CandidateController.php:596-633`; `rejection_reason`.
4. Invoke only the owning control for `POST hr/recruitment/applications/bulk` (`hr.applications.bulk`, action `bulkAction`). Source category: **mutation outcome source gap (bulkAction)**; controller `app/Http/Controllers/Hr/CandidateController.php:640-734`; `action`.

## Source-applicable states and transitions

- **mutation outcome source gap (advanceApplication)** is applicable only to `advanceApplication` / `ROUTE-1668` at `app/Http/Controllers/Hr/CandidateController.php:567`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectApplication` / `ROUTE-1672` at `app/Http/Controllers/Hr/CandidateController.php:596`; it is not runtime-observed.
- **mutation outcome source gap (bulkAction)** is applicable only to `bulkAction` / `ROUTE-1673` at `app/Http/Controllers/Hr/CandidateController.php:640`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1668` / `advanceApplication`: fields `target_stage`; success app/Http/Controllers/Hr/CandidateController.php:589 `return redirect()->back()->with('success', 'Candidate advanced to next stage.');`.
- `ROUTE-1672` / `rejectApplication`: fields `rejection_reason`; success app/Http/Controllers/Hr/CandidateController.php:632 `return redirect()->back()->with('success', 'Application rejected.');`.
- `ROUTE-1673` / `bulkAction`: fields `action`; success app/Http/Controllers/Hr/CandidateController.php:733 `return redirect()->back()->with('success', $message);`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CandidateController.php:612 `$application->update([`; app/Http/Controllers/Hr/CandidateController.php:691 `$candidate->update(['tags' => $tags->values()->all(), 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/CandidateController.php:700 `$candidate->update(['status' => 'rejected', 'current_stage_entered_at' => now(), 'updated_by' => $user->id]);`; app/Http/Controllers/Hr/CandidateController.php:703 `->update(['status' => 'rejected', 'rejection_reason' => $validated['reason'] ?? null]);`; responses app/Http/Controllers/Hr/CandidateController.php:586 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CandidateController.php:589 `return redirect()->back()->with('success', 'Candidate advanced to next stage.');`; app/Http/Controllers/Hr/CandidateController.php:632 `return redirect()->back()->with('success', 'Application rejected.');`; app/Http/Controllers/Hr/CandidateController.php:660 `return redirect()->back()->with('error', 'Enter a tag to apply or remove.');`; app/Http/Controllers/Hr/CandidateController.php:733 `return redirect()->back()->with('success', $message);`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Hr/CandidateController.php:626 `->notify(new RejectionNotification($candidate, $application, $validated['decline_message'] ?? null));`; app/Http/Controllers/Hr/CandidateController.php:712 `->notify(new RejectionNotification($candidate, $rejectedApplication, $validated['decline_message'] ?? null));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST hr/recruitment/applications/{application}/advance` — `hr.applications.advance` — `App\Http\Controllers\Hr\CandidateController@advanceApplication` — `app/Http/Controllers/Hr/CandidateController.php:567` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/applications/{application}/reject` — `hr.applications.reject` — `App\Http\Controllers\Hr\CandidateController@rejectApplication` — `app/Http/Controllers/Hr/CandidateController.php:596` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`
- `POST hr/recruitment/applications/bulk` — `hr.applications.bulk` — `App\Http\Controllers\Hr\CandidateController@bulkAction` — `app/Http/Controllers/Hr/CandidateController.php:640` — middleware `web, auth, permission:hr.recruitment.view, permission:hr.recruitment.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CandidateController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
