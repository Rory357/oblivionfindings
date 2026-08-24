# CAP-HR-HR-PERFORMANCE-REVIEW-PROBATION: Probation review management

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-HR-PERFORMANCE-REVIEW`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/reviews` (`hr.performance.reviews.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/reviews` (`hr.performance.reviews.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/performance/probation` (`hr.performance.probation.store`, action `storeProbation`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:463-513`; `employee_user_id`.
3. Invoke only the owning control for `PUT hr/performance/probation/{review}` (`hr.performance.probation.update`, action `updateProbation`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:518-544`; `review_date`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeProbation` / `ROUTE-1637` at `app/Http/Controllers/Hr/PerformanceReviewController.php:463`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateProbation` / `ROUTE-1638` at `app/Http/Controllers/Hr/PerformanceReviewController.php:518`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1637` / `storeProbation`: fields `employee_user_id`; success app/Http/Controllers/Hr/PerformanceReviewController.php:512 `return redirect()->back()->with('success', 'Probation review recorded.');`.
- `ROUTE-1638` / `updateProbation`: fields `review_date`; success app/Http/Controllers/Hr/PerformanceReviewController.php:543 `return redirect()->back()->with('success', 'Probation review updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PerformanceReviewController.php:482 `HrProbationReview::create([`; app/Http/Controllers/Hr/PerformanceReviewController.php:508 `])->save();`; app/Http/Controllers/Hr/PerformanceReviewController.php:541 `$review->update($data);`; responses app/Http/Controllers/Hr/PerformanceReviewController.php:512 `return redirect()->back()->with('success', 'Probation review recorded.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:543 `return redirect()->back()->with('success', 'Probation review updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/performance/probation` — `hr.performance.probation.store` — `App\Http\Controllers\Hr\PerformanceReviewController@storeProbation` — `app/Http/Controllers/Hr/PerformanceReviewController.php:463` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/performance/probation/{review}` — `hr.performance.probation.update` — `App\Http\Controllers\Hr\PerformanceReviewController@updateProbation` — `app/Http/Controllers/Hr/PerformanceReviewController.php:518` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PerformanceReviewController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
