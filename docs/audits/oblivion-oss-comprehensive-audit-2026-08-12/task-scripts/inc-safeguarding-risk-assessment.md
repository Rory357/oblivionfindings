# INC-SAFEGUARDING-RISK-ASSESSMENT: Safeguarding Risk Assessment

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-RISK-ASSESSMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST safeguarding/{concern}/risk-assessments` (`safeguarding.riskAssessments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingRiskAssessmentController.php:16-56`; `risk_factors`, `protective_factors`, `risk_to_self`, `risk_to_others`, `risk_from_others`, `overall_risk_level`, `capacity_assessed`, `mental_capacity`, `capacity_notes`, `immediate_actions_required`, `protective_measures`, `multi_agency_required`, `agencies_involved`, `next_review_date`, `assessment_notes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2519` at `app/Http/Controllers/SafeguardingRiskAssessmentController.php:16`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2519` / `store`: fields `risk_factors`, `protective_factors`, `risk_to_self`, `risk_to_others`, `risk_from_others`, `overall_risk_level`, `capacity_assessed`, `mental_capacity`, `capacity_notes`, `immediate_actions_required`, `protective_measures`, `multi_agency_required`, `agencies_involved`, `next_review_date`, `assessment_notes`; success app/Http/Controllers/SafeguardingRiskAssessmentController.php:55 `return back()->with('success', 'Risk assessment created successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingRiskAssessmentController.php:53 `SafeguardingRiskAssessment::create($validated);`; responses app/Http/Controllers/SafeguardingRiskAssessmentController.php:55 `return back()->with('success', 'Risk assessment created successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/risk-assessments` — `safeguarding.riskAssessments.store` — `App\Http\Controllers\SafeguardingRiskAssessmentController@store` — `app/Http/Controllers/SafeguardingRiskAssessmentController.php:16` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingRiskAssessmentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
