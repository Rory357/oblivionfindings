# CAP-HR-FEEDBACK-REQUEST-RESPONSE: Feedback request response and follow-up

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-FEEDBACK`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/feedback` (`hr.feedback.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/feedback` (`hr.feedback.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/feedback/{feedbackRequest}/respond` (`hr.feedback.respond`, action `respond`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/FeedbackController.php:173-197`.
3. Use `GET|HEAD hr/feedback/request` (`hr.feedback.request`, action `request`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/FeedbackController.php:120-133`.
4. Invoke only the owning control for `POST hr/feedback/{feedbackRequest}/cancel` (`hr.feedback.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/FeedbackController.php:287-297`; FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/feedback/{feedbackRequest}/decline` (`hr.feedback.decline`, action `decline`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/FeedbackController.php:261-271`; FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/feedback/{feedbackRequest}/remind` (`hr.feedback.remind`, action `remind`). Source category: **mutation outcome source gap (remind)**; controller `app/Http/Controllers/Hr/FeedbackController.php:274-284`; FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/feedback/{feedbackRequest}/respond` (`hr.feedback.respond.store`, action `submitResponse`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/FeedbackController.php:203-229`; FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; `responses`.
8. Invoke only the owning control for `POST hr/feedback/request` (`hr.feedback.request.store`, action `storeRequest`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/FeedbackController.php:139-167`; `subject_user_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1442` at `app/Http/Controllers/Hr/FeedbackController.php:27`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1443` at `app/Http/Controllers/Hr/FeedbackController.php:287`; it is not runtime-observed.
- **rejected/returned** is applicable only to `decline` / `ROUTE-1444` at `app/Http/Controllers/Hr/FeedbackController.php:261`; it is not runtime-observed.
- **mutation outcome source gap (remind)** is applicable only to `remind` / `ROUTE-1445` at `app/Http/Controllers/Hr/FeedbackController.php:274`; it is not runtime-observed.
- **information presented** is applicable only to `respond` / `ROUTE-1446` at `app/Http/Controllers/Hr/FeedbackController.php:173`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitResponse` / `ROUTE-1447` at `app/Http/Controllers/Hr/FeedbackController.php:203`; it is not runtime-observed.
- **information presented** is applicable only to `request` / `ROUTE-1449` at `app/Http/Controllers/Hr/FeedbackController.php:120`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeRequest` / `ROUTE-1450` at `app/Http/Controllers/Hr/FeedbackController.php:139`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/feedback/index.tsx`, `resources/js/pages/hr/feedback/respond.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1443` / `cancel`: FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; success app/Http/Controllers/Hr/FeedbackController.php:296 `return redirect()->back()->with('success', 'Feedback request cancelled.');`.
- `ROUTE-1444` / `decline`: FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; success app/Http/Controllers/Hr/FeedbackController.php:270 `return redirect()->back()->with('success', 'Feedback request declined.');`.
- `ROUTE-1445` / `remind`: FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; success app/Http/Controllers/Hr/FeedbackController.php:283 `return redirect()->back()->with('success', 'Reminder sent to the reviewer.');`.
- `ROUTE-1446` / `respond`: FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`.
- `ROUTE-1447` / `submitResponse`: FormRequest `app/Domain/Hr/Models/HrFeedbackRequest.php:line unresolved`; fields `responses`; success app/Http/Controllers/Hr/FeedbackController.php:228 `return redirect('/hr/feedback')->with('success', 'Feedback submitted. Thank you!');`.
- `ROUTE-1450` / `storeRequest`: fields `subject_user_id`; success app/Http/Controllers/Hr/FeedbackController.php:166 `return redirect('/hr/feedback')->with('success', '360-degree feedback requests sent.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/FeedbackController.php:294 `$feedbackRequest->update(['status' => 'expired']);`; app/Http/Controllers/Hr/FeedbackController.php:268 `$feedbackRequest->update(['status' => 'declined']);`; responses app/Http/Controllers/Hr/FeedbackController.php:107 `return Inertia::render('hr/feedback/index', [`; app/Http/Controllers/Hr/FeedbackController.php:296 `return redirect()->back()->with('success', 'Feedback request cancelled.');`; app/Http/Controllers/Hr/FeedbackController.php:270 `return redirect()->back()->with('success', 'Feedback request declined.');`; app/Http/Controllers/Hr/FeedbackController.php:283 `return redirect()->back()->with('success', 'Reminder sent to the reviewer.');`; app/Http/Controllers/Hr/FeedbackController.php:185 `return Inertia::render('hr/feedback/respond', [`; app/Http/Controllers/Hr/FeedbackController.php:225 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/FeedbackController.php:228 `return redirect('/hr/feedback')->with('success', 'Feedback submitted. Thank you!');`; app/Http/Controllers/Hr/FeedbackController.php:129 `return redirect()->route('hr.feedback.index', array_filter([`; app/Http/Controllers/Hr/FeedbackController.php:163 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/FeedbackController.php:166 `return redirect('/hr/feedback')->with('success', '360-degree feedback requests sent.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/feedback` — `hr.feedback.index` — `App\Http\Controllers\Hr\FeedbackController@index` — `app/Http/Controllers/Hr/FeedbackController.php:27` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/feedback/{feedbackRequest}/cancel` — `hr.feedback.cancel` — `App\Http\Controllers\Hr\FeedbackController@cancel` — `app/Http/Controllers/Hr/FeedbackController.php:287` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/feedback/{feedbackRequest}/decline` — `hr.feedback.decline` — `App\Http\Controllers\Hr\FeedbackController@decline` — `app/Http/Controllers/Hr/FeedbackController.php:261` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/feedback/{feedbackRequest}/remind` — `hr.feedback.remind` — `App\Http\Controllers\Hr\FeedbackController@remind` — `app/Http/Controllers/Hr/FeedbackController.php:274` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/feedback/{feedbackRequest}/respond` — `hr.feedback.respond` — `App\Http\Controllers\Hr\FeedbackController@respond` — `app/Http/Controllers/Hr/FeedbackController.php:173` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/feedback/{feedbackRequest}/respond` — `hr.feedback.respond.store` — `App\Http\Controllers\Hr\FeedbackController@submitResponse` — `app/Http/Controllers/Hr/FeedbackController.php:203` — middleware `web, auth, permission:hr.performance.view`
- `GET|HEAD hr/feedback/request` — `hr.feedback.request` — `App\Http\Controllers\Hr\FeedbackController@request` — `app/Http/Controllers/Hr/FeedbackController.php:120` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/feedback/request` — `hr.feedback.request.store` — `App\Http\Controllers\Hr\FeedbackController@storeRequest` — `app/Http/Controllers/Hr/FeedbackController.php:139` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/FeedbackController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/feedback/index.tsx`, `resources/js/pages/hr/feedback/respond.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
