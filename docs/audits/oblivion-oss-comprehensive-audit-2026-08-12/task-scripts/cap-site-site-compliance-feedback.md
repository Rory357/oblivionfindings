# CAP-SITE-SITE-COMPLIANCE-FEEDBACK: Site compliance feedback and response

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-COMPLIANCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/feedback` (`sites.feedback`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/feedback` (`sites.feedback`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/feedback` (`sites.feedback.store`, action `storeFeedback`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:424-446`; `feedback_type`, `submitted_by_name`, `submitted_by_relationship`, `content`, `rating`, `category`, `is_anonymous`.
3. Invoke only the owning control for `POST sites/{site}/feedback/{feedback}/respond` (`sites.feedback.respond`, action `respondFeedback`). Source category: **mutation outcome source gap (respondFeedback)**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:448-466`; `response`.
4. Invoke only the owning control for `PUT sites/{site}/feedback/{feedback}/status` (`sites.feedback.update_status`, action `updateFeedbackStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteComplianceController.php:468-481`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `feedback` / `ROUTE-2788` at `app/Http/Controllers/Sites/SiteComplianceController.php:368`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeFeedback` / `ROUTE-2789` at `app/Http/Controllers/Sites/SiteComplianceController.php:424`; it is not runtime-observed.
- **mutation outcome source gap (respondFeedback)** is applicable only to `respondFeedback` / `ROUTE-2790` at `app/Http/Controllers/Sites/SiteComplianceController.php:448`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateFeedbackStatus` / `ROUTE-2791` at `app/Http/Controllers/Sites/SiteComplianceController.php:468`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/feedback/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2789` / `storeFeedback`: fields `feedback_type`, `submitted_by_name`, `submitted_by_relationship`, `content`, `rating`, `category`, `is_anonymous`; success app/Http/Controllers/Sites/SiteComplianceController.php:445 `return redirect()->back()->with('success', 'Feedback submitted successfully.');`.
- `ROUTE-2790` / `respondFeedback`: fields `response`; success app/Http/Controllers/Sites/SiteComplianceController.php:465 `return redirect()->back()->with('success', 'Response recorded successfully.');`.
- `ROUTE-2791` / `updateFeedbackStatus`: success app/Http/Controllers/Sites/SiteComplianceController.php:480 `return redirect()->back()->with('success', 'Feedback status updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteComplianceController.php:438 `SiteFeedback::create([`; app/Http/Controllers/Sites/SiteComplianceController.php:458 `$feedback->update([`; app/Http/Controllers/Sites/SiteComplianceController.php:478 `$feedback->update($validated);`; responses app/Http/Controllers/Sites/SiteComplianceController.php:408 `return inertia('sites/feedback/Index', [`; app/Http/Controllers/Sites/SiteComplianceController.php:445 `return redirect()->back()->with('success', 'Feedback submitted successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:465 `return redirect()->back()->with('success', 'Response recorded successfully.');`; app/Http/Controllers/Sites/SiteComplianceController.php:480 `return redirect()->back()->with('success', 'Feedback status updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/feedback` — `sites.feedback` — `App\Http\Controllers\Sites\SiteComplianceController@feedback` — `app/Http/Controllers/Sites/SiteComplianceController.php:368` — middleware `web, auth, verified, permission:sites.viewAny`
- `POST sites/{site}/feedback` — `sites.feedback.store` — `App\Http\Controllers\Sites\SiteComplianceController@storeFeedback` — `app/Http/Controllers/Sites/SiteComplianceController.php:424` — middleware `web, auth, verified, permission:sites.update`
- `POST sites/{site}/feedback/{feedback}/respond` — `sites.feedback.respond` — `App\Http\Controllers\Sites\SiteComplianceController@respondFeedback` — `app/Http/Controllers/Sites/SiteComplianceController.php:448` — middleware `web, auth, verified, permission:sites.update`
- `PUT sites/{site}/feedback/{feedback}/status` — `sites.feedback.update_status` — `App\Http\Controllers\Sites\SiteComplianceController@updateFeedbackStatus` — `app/Http/Controllers/Sites/SiteComplianceController.php:468` — middleware `web, auth, verified, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteComplianceController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/feedback/Index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
