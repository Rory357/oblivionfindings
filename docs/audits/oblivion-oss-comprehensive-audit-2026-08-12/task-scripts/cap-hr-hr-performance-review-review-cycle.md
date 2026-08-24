# CAP-HR-HR-PERFORMANCE-REVIEW-REVIEW-CYCLE: Employee performance review cycle

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
2. Use `GET|HEAD hr/performance/reviews/{review}` (`hr.performance.reviews.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PerformanceReviewController.php:148-183`.
3. Use `GET|HEAD hr/performance/reviews/{review}/edit` (`hr.performance.reviews.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PerformanceReviewController.php:261-267`.
4. Use `GET|HEAD hr/performance/reviews/{review}/evidence` (`hr.performance.reviews.evidence.show`, action `downloadEvidence`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/PerformanceReviewController.php:444-458`.
5. Use `GET|HEAD hr/performance/reviews/create` (`hr.performance.reviews.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/PerformanceReviewController.php:137-143`.
6. Invoke only the owning control for `POST hr/performance/reviews` (`hr.performance.reviews.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:272-292`; FormRequest `app/Http/Requests/Hr/StorePerformanceReviewRequest.php:14`; `employee_user_id`, `review_type`, `review_period_start`, `review_period_end`, `overall_rating`, `strengths`, `development_areas`, `goals`, `training_recommendations`, `next_review_date`.
7. Invoke only the owning control for `PUT hr/performance/reviews/{review}` (`hr.performance.reviews.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:297-342`; `review_type`.
8. Invoke only the owning control for `POST hr/performance/reviews/{review}/acknowledge` (`hr.performance.reviews.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:400-418`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/performance/reviews/{review}/evidence` (`hr.performance.reviews.evidence.store`, action `uploadEvidence`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:423-439`; `file`.
10. Invoke only the owning control for `POST hr/performance/reviews/{review}/sign-off` (`hr.performance.reviews.sign-off`, action `signOff`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:364-395`; `decision`.
11. Invoke only the owning control for `POST hr/performance/reviews/{review}/submit` (`hr.performance.reviews.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/PerformanceReviewController.php:347-359`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1639` at `app/Http/Controllers/Hr/PerformanceReviewController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1640` at `app/Http/Controllers/Hr/PerformanceReviewController.php:272`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1641` at `app/Http/Controllers/Hr/PerformanceReviewController.php:148`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1642` at `app/Http/Controllers/Hr/PerformanceReviewController.php:297`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1643` at `app/Http/Controllers/Hr/PerformanceReviewController.php:400`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1644` at `app/Http/Controllers/Hr/PerformanceReviewController.php:261`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadEvidence` / `ROUTE-1645` at `app/Http/Controllers/Hr/PerformanceReviewController.php:444`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadEvidence` / `ROUTE-1646` at `app/Http/Controllers/Hr/PerformanceReviewController.php:423`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `signOff` / `ROUTE-1647` at `app/Http/Controllers/Hr/PerformanceReviewController.php:364`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-1648` at `app/Http/Controllers/Hr/PerformanceReviewController.php:347`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1649` at `app/Http/Controllers/Hr/PerformanceReviewController.php:137`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/performance/reviews.tsx`, `resources/js/pages/hr/performance/show-review.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1640` / `store`: FormRequest `app/Http/Requests/Hr/StorePerformanceReviewRequest.php:14`; fields `employee_user_id`, `review_type`, `review_period_start`, `review_period_end`, `overall_rating`, `strengths`, `development_areas`, `goals`, `training_recommendations`, `next_review_date`; success app/Http/Controllers/Hr/PerformanceReviewController.php:291 `return redirect()->back()->with('success', 'Performance review created.');`.
- `ROUTE-1642` / `update`: fields `review_type`; success app/Http/Controllers/Hr/PerformanceReviewController.php:341 `return redirect()->back()->with('success', 'Performance review updated.');`.
- `ROUTE-1643` / `acknowledge`: success app/Http/Controllers/Hr/PerformanceReviewController.php:417 `return redirect()->back()->with('success', 'Review acknowledged.');`.
- `ROUTE-1646` / `uploadEvidence`: fields `file`; success app/Http/Controllers/Hr/PerformanceReviewController.php:438 `return redirect()->back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-1647` / `signOff`: fields `decision`; success app/Http/Controllers/Hr/PerformanceReviewController.php:384 `return redirect()->back()->with('success', 'Review returned for edits.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:394 `return redirect()->back()->with('success', 'Review signed off and locked.');`.
- `ROUTE-1648` / `submit`: success app/Http/Controllers/Hr/PerformanceReviewController.php:358 `return redirect()->back()->with('success', 'Review submitted for sign-off.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/PerformanceReviewController.php:279 `$review = HrPerformanceReview::create([`; app/Http/Controllers/Hr/PerformanceReviewController.php:335 `$review->update($data);`; app/Http/Controllers/Hr/PerformanceReviewController.php:411 `$review->update([`; app/Http/Controllers/Hr/PerformanceReviewController.php:433 `Storage::disk('private')->delete($review->evidence_path);`; app/Http/Controllers/Hr/PerformanceReviewController.php:436 `$review->update(['evidence_path' => $path]);`; app/Http/Controllers/Hr/PerformanceReviewController.php:379 `$review->update([`; app/Http/Controllers/Hr/PerformanceReviewController.php:387 `$review->update([`; app/Http/Controllers/Hr/PerformanceReviewController.php:356 `$review->update(['status' => 'in_progress', 'updated_by' => $user->id]);`; responses app/Http/Controllers/Hr/PerformanceReviewController.php:85 `return Inertia::render('hr/performance/reviews', [`; app/Http/Controllers/Hr/PerformanceReviewController.php:291 `return redirect()->back()->with('success', 'Performance review created.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:159 `return Inertia::render('hr/performance/show-review', [`; app/Http/Controllers/Hr/PerformanceReviewController.php:341 `return redirect()->back()->with('success', 'Performance review updated.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:417 `return redirect()->back()->with('success', 'Review acknowledged.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:266 `return redirect()->route('hr.performance.reviews.index');`; app/Http/Controllers/Hr/PerformanceReviewController.php:451 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/PerformanceReviewController.php:438 `return redirect()->back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:384 `return redirect()->back()->with('success', 'Review returned for edits.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:394 `return redirect()->back()->with('success', 'Review signed off and locked.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:358 `return redirect()->back()->with('success', 'Review submitted for sign-off.');`; app/Http/Controllers/Hr/PerformanceReviewController.php:142 `return redirect()->route('hr.performance.reviews.index');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/performance/reviews` — `hr.performance.reviews.index` — `App\Http\Controllers\Hr\PerformanceReviewController@index` — `app/Http/Controllers/Hr/PerformanceReviewController.php:28` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/reviews` — `hr.performance.reviews.store` — `App\Http\Controllers\Hr\PerformanceReviewController@store` — `app/Http/Controllers/Hr/PerformanceReviewController.php:272` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/reviews/{review}` — `hr.performance.reviews.show` — `App\Http\Controllers\Hr\PerformanceReviewController@show` — `app/Http/Controllers/Hr/PerformanceReviewController.php:148` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/performance/reviews/{review}` — `hr.performance.reviews.update` — `App\Http\Controllers\Hr\PerformanceReviewController@update` — `app/Http/Controllers/Hr/PerformanceReviewController.php:297` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/reviews/{review}/acknowledge` — `hr.performance.reviews.acknowledge` — `App\Http\Controllers\Hr\PerformanceReviewController@acknowledge` — `app/Http/Controllers/Hr/PerformanceReviewController.php:400` — middleware `web, auth, permission:hr.performance.view`
- `GET|HEAD hr/performance/reviews/{review}/edit` — `hr.performance.reviews.edit` — `App\Http\Controllers\Hr\PerformanceReviewController@edit` — `app/Http/Controllers/Hr/PerformanceReviewController.php:261` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/reviews/{review}/evidence` — `hr.performance.reviews.evidence.show` — `App\Http\Controllers\Hr\PerformanceReviewController@downloadEvidence` — `app/Http/Controllers/Hr/PerformanceReviewController.php:444` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/reviews/{review}/evidence` — `hr.performance.reviews.evidence.store` — `App\Http\Controllers\Hr\PerformanceReviewController@uploadEvidence` — `app/Http/Controllers/Hr/PerformanceReviewController.php:423` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/reviews/{review}/sign-off` — `hr.performance.reviews.sign-off` — `App\Http\Controllers\Hr\PerformanceReviewController@signOff` — `app/Http/Controllers/Hr/PerformanceReviewController.php:364` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/reviews/{review}/submit` — `hr.performance.reviews.submit` — `App\Http\Controllers\Hr\PerformanceReviewController@submit` — `app/Http/Controllers/Hr/PerformanceReviewController.php:347` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/reviews/create` — `hr.performance.reviews.create` — `App\Http\Controllers\Hr\PerformanceReviewController@create` — `app/Http/Controllers/Hr/PerformanceReviewController.php:137` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/PerformanceReviewController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/performance/reviews.tsx`, `resources/js/pages/hr/performance/show-review.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
