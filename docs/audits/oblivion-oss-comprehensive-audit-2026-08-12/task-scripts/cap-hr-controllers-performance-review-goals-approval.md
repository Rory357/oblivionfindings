# CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-GOALS-APPROVAL: Review goals and approval

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
2. Invoke only the owning control for `POST governance/performance/{review}/approve` (`governance.performance.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:190-201`; `resolution_id`.
3. Invoke only the owning control for `POST governance/performance/{review}/goals` (`governance.performance.goals.add`, action `addGoal`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:97-117`; `pillar`, `goal_description`, `success_criteria`, `weight`, `target_score`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0965` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:190`; it is not runtime-observed.
- **created/recorded** is applicable only to `addGoal` / `ROUTE-0969` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:97`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0965` / `approve`: fields `resolution_id`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:200 `return redirect()->back()->with('success', 'Performance review approved and completed.');`.
- `ROUTE-0969` / `addGoal`: fields `pillar`, `goal_description`, `success_criteria`, `weight`, `target_score`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:116 `return redirect()->back()->with('success', 'Goal added.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:200 `return redirect()->back()->with('success', 'Performance review approved and completed.');`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:116 `return redirect()->back()->with('success', 'Goal added.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/performance/{review}/approve` — `governance.performance.approve` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@approve` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:190` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`
- `POST governance/performance/{review}/goals` — `governance.performance.goals.add` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@addGoal` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:97` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
