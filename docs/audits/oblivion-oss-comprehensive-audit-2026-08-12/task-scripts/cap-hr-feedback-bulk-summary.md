# CAP-HR-FEEDBACK-BULK-SUMMARY: Bulk feedback requests and summary

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-FEEDBACK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/feedback/summary/{user}` (`hr.feedback.summary`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/feedback/summary/{user}` (`hr.feedback.summary`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/feedback/bulk-request` (`hr.feedback.bulk-request`, action `bulkRequest`). Source category: **mutation outcome source gap (bulkRequest)**; controller `app/Http/Controllers/Hr/FeedbackController.php:300-329`; `subject_user_id`.

## Source-applicable states and transitions

- **mutation outcome source gap (bulkRequest)** is applicable only to `bulkRequest` / `ROUTE-1448` at `app/Http/Controllers/Hr/FeedbackController.php:300`; it is not runtime-observed.
- **information presented** is applicable only to `summary` / `ROUTE-1451` at `app/Http/Controllers/Hr/FeedbackController.php:235`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/feedback/summary.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1448` / `bulkRequest`: fields `subject_user_id`; success app/Http/Controllers/Hr/FeedbackController.php:328 `return redirect()->back()->with('success', '360 feedback requests sent to '.count($validated['reviewer_user_ids']).' reviewers.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/FeedbackController.php:325 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/FeedbackController.php:328 `return redirect()->back()->with('success', '360 feedback requests sent to '.count($validated['reviewer_user_ids']).' reviewers.');`; app/Http/Controllers/Hr/FeedbackController.php:249 `return Inertia::render('hr/feedback/summary', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/feedback/bulk-request` — `hr.feedback.bulk-request` — `App\Http\Controllers\Hr\FeedbackController@bulkRequest` — `app/Http/Controllers/Hr/FeedbackController.php:300` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/feedback/summary/{user}` — `hr.feedback.summary` — `App\Http\Controllers\Hr\FeedbackController@summary` — `app/Http/Controllers/Hr/FeedbackController.php:235` — middleware `web, auth, permission:hr.performance.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/FeedbackController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/feedback/summary.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
