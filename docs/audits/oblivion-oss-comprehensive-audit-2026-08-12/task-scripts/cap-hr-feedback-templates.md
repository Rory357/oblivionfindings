# CAP-HR-FEEDBACK-TEMPLATES: Feedback template administration

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
2. Invoke only the owning control for `POST hr/feedback/templates` (`hr.feedback.templates.store`, action `storeTemplate`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/FeedbackController.php:335-361`; `name`.
3. Invoke only the owning control for `DELETE hr/feedback/templates/{template}` (`hr.feedback.templates.destroy`, action `deleteTemplate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/FeedbackController.php:381-390`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT hr/feedback/templates/{template}` (`hr.feedback.templates.update`, action `updateTemplate`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/FeedbackController.php:363-379`; `name`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeTemplate` / `ROUTE-1452` at `app/Http/Controllers/Hr/FeedbackController.php:335`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteTemplate` / `ROUTE-1453` at `app/Http/Controllers/Hr/FeedbackController.php:381`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTemplate` / `ROUTE-1454` at `app/Http/Controllers/Hr/FeedbackController.php:363`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1452` / `storeTemplate`: fields `name`; success app/Http/Controllers/Hr/FeedbackController.php:360 `return redirect()->back()->with('success', 'Template created.');`.
- `ROUTE-1453` / `deleteTemplate`: success app/Http/Controllers/Hr/FeedbackController.php:389 `return redirect()->back()->with('success', 'Template deleted.');`.
- `ROUTE-1454` / `updateTemplate`: fields `name`; success app/Http/Controllers/Hr/FeedbackController.php:378 `return redirect()->back()->with('success', 'Template updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/FeedbackController.php:350 `HrFeedbackTemplate::create([`; app/Http/Controllers/Hr/FeedbackController.php:387 `$template->delete();`; app/Http/Controllers/Hr/FeedbackController.php:376 `$template->update($validated);`; responses app/Http/Controllers/Hr/FeedbackController.php:360 `return redirect()->back()->with('success', 'Template created.');`; app/Http/Controllers/Hr/FeedbackController.php:389 `return redirect()->back()->with('success', 'Template deleted.');`; app/Http/Controllers/Hr/FeedbackController.php:378 `return redirect()->back()->with('success', 'Template updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/feedback/templates` — `hr.feedback.templates.store` — `App\Http\Controllers\Hr\FeedbackController@storeTemplate` — `app/Http/Controllers/Hr/FeedbackController.php:335` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `DELETE hr/feedback/templates/{template}` — `hr.feedback.templates.destroy` — `App\Http\Controllers\Hr\FeedbackController@deleteTemplate` — `app/Http/Controllers/Hr/FeedbackController.php:381` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/feedback/templates/{template}` — `hr.feedback.templates.update` — `App\Http\Controllers\Hr\FeedbackController@updateTemplate` — `app/Http/Controllers/Hr/FeedbackController.php:363` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/FeedbackController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
