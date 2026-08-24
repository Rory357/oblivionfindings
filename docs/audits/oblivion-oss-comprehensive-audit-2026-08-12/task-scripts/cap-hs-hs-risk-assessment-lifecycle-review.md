# CAP-HS-HS-RISK-ASSESSMENT-LIFECYCLE-REVIEW: Risk assessment activation review supersession and archive

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-RISK-ASSESSMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/risk-assessments` (`health-safety.risk-assessments.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/risk-assessments` (`health-safety.risk-assessments.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/activate` (`health-safety.risk-assessments.activate`, action `activate`). Source category: **mutation outcome source gap (activate)**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:167-180`; FormRequest `app/Http/Requests/HealthSafety/ActivateHsRiskAssessmentRequest.php:19`; `approver_note`.
3. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/archive` (`health-safety.risk-assessments.archive`, action `archive`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:223-228`; no exact validation fields extracted.
4. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/review` (`health-safety.risk-assessments.review`, action `markForReview`). Source category: **mutation outcome source gap (markForReview)**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:182-191`; no exact validation fields extracted.
5. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/supersede` (`health-safety.risk-assessments.supersede`, action `supersede`). Source category: **mutation outcome source gap (supersede)**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:210-221`; FormRequest `app/Http/Requests/HealthSafety/SupersedeHsRiskAssessmentRequest.php:line unresolved`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (activate)** is applicable only to `activate` / `ROUTE-1218` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:167`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archive` / `ROUTE-1219` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:223`; it is not runtime-observed.
- **mutation outcome source gap (markForReview)** is applicable only to `markForReview` / `ROUTE-1224` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:182`; it is not runtime-observed.
- **mutation outcome source gap (supersede)** is applicable only to `supersede` / `ROUTE-1225` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:210`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1218` / `activate`: FormRequest `app/Http/Requests/HealthSafety/ActivateHsRiskAssessmentRequest.php:19`; fields `approver_note`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:179 `return back()->with('success', 'Approved & activated.');`.
- `ROUTE-1219` / `archive`: success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:227 `return back()->with('success', 'Assessment archived.');`.
- `ROUTE-1224` / `markForReview`: success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:190 `return back()->with('success', 'Marked for review.');`.
- `ROUTE-1225` / `supersede`: FormRequest `app/Http/Requests/HealthSafety/SupersedeHsRiskAssessmentRequest.php:line unresolved`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:219 `->with('success', "Superseded — {$new->reference_number} created as a draft.")`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:176 `$assessment->update(['approval_note' => $request->input('approver_note')]);`; responses app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:172 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:179 `return back()->with('success', 'Approved & activated.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:227 `return back()->with('success', 'Assessment archived.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:187 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:190 `return back()->with('success', 'Marked for review.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:215 `return back()->with('error', $e->getMessage());`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:218 `return back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/risk-assessments/{assessment}/activate` — `health-safety.risk-assessments.activate` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@activate` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:167` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/risk-assessments/{assessment}/archive` — `health-safety.risk-assessments.archive` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@archive` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:223` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/risk-assessments/{assessment}/review` — `health-safety.risk-assessments.review` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@markForReview` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:182` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/risk-assessments/{assessment}/supersede` — `health-safety.risk-assessments.supersede` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@supersede` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:210` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
