# CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-MANAGER-ASSESSMENT: Manager assessment and feedback

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.performance.view`, `permission:governance.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-CONTROLLERS-PERFORMANCE-REVIEW`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/performance` (`governance.performance.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.performance.view`, `permission:governance.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.performance.view`, `permission:governance.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/performance` (`governance.performance.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/performance/{review}/assess` (`governance.performance.assess`, action `submitAssessment`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:119-139`; `goal_assessments`, `overall_rating`, `board_decision`, `decision_notes`.
3. Invoke only the owning control for `POST governance/performance/{review}/feedback` (`governance.performance.feedback`, action `submitFeedback`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:150-168`; `reviewer_role`, `ratings`, `strengths`, `areas_for_improvement`, `comments`, `is_anonymous`.
4. Invoke only the owning control for `POST governance/performance/{review}/self-assessment` (`governance.performance.self-assessment`, action `submitSelfAssessment`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:173-184`; `self_assessment`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `submitAssessment` / `ROUTE-0966` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:119`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitFeedback` / `ROUTE-0968` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:150`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitSelfAssessment` / `ROUTE-0970` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:173`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0966` / `submitAssessment`: fields `goal_assessments`, `overall_rating`, `board_decision`, `decision_notes`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:138 `return redirect()->back()->with('success', 'Assessment submitted.');`.
- `ROUTE-0968` / `submitFeedback`: fields `reviewer_role`, `ratings`, `strengths`, `areas_for_improvement`, `comments`, `is_anonymous`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:167 `return redirect()->back()->with('success', 'Feedback submitted.');`.
- `ROUTE-0970` / `submitSelfAssessment`: fields `self_assessment`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:183 `return redirect()->back()->with('success', 'Self-assessment submitted for board review.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:161 `$review->feedback()->create([`; responses app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:138 `return redirect()->back()->with('success', 'Assessment submitted.');`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:167 `return redirect()->back()->with('success', 'Feedback submitted.');`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:183 `return redirect()->back()->with('success', 'Self-assessment submitted for board review.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/performance/{review}/assess` — `governance.performance.assess` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@submitAssessment` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:119` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`
- `POST governance/performance/{review}/feedback` — `governance.performance.feedback` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@submitFeedback` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:150` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`
- `POST governance/performance/{review}/self-assessment` — `governance.performance.self-assessment` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@submitSelfAssessment` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:173` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
