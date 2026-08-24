# CAP-HR-COMPENSATION-REVIEW-CYCLE: Compensation review and application cycle

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPENSATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compensation/reviews` (`hr.compensation.reviews`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.compensation.view`, `permission:hr.compensation.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compensation/reviews` (`hr.compensation.reviews`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compensation/reviews/{review}` (`hr.compensation.reviews.show`, action `showReview`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompensationController.php:458-488`.
3. Use `GET|HEAD hr/compensation/reviews/create` (`hr.compensation.reviews.create`, action `createReview`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompensationController.php:416-422`.
4. Invoke only the owning control for `POST hr/compensation/reviews` (`hr.compensation.reviews.store`, action `storeReview`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CompensationController.php:427-453`; `title`.
5. Invoke only the owning control for `POST hr/compensation/reviews/{review}/apply` (`hr.compensation.reviews.apply`, action `applyReview`). Source category: **mutation outcome source gap (applyReview)**; controller `app/Http/Controllers/Hr/CompensationController.php:512-526`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/compensation/reviews/{review}/approve` (`hr.compensation.reviews.approve`, action `approveReview`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/CompensationController.php:493-507`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/compensation/reviews/{review}/items/{item}/approve` (`hr.compensation.reviews.items.approve`, action `approveReviewItem`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/CompensationController.php:531-545`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/compensation/reviews/{review}/items/{item}/reject` (`hr.compensation.reviews.items.reject`, action `rejectReviewItem`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/CompensationController.php:550-568`; `reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `reviews` / `ROUTE-1343` at `app/Http/Controllers/Hr/CompensationController.php:367`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeReview` / `ROUTE-1344` at `app/Http/Controllers/Hr/CompensationController.php:427`; it is not runtime-observed.
- **information presented** is applicable only to `showReview` / `ROUTE-1345` at `app/Http/Controllers/Hr/CompensationController.php:458`; it is not runtime-observed.
- **mutation outcome source gap (applyReview)** is applicable only to `applyReview` / `ROUTE-1346` at `app/Http/Controllers/Hr/CompensationController.php:512`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approveReview` / `ROUTE-1347` at `app/Http/Controllers/Hr/CompensationController.php:493`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approveReviewItem` / `ROUTE-1348` at `app/Http/Controllers/Hr/CompensationController.php:531`; it is not runtime-observed.
- **rejected/returned** is applicable only to `rejectReviewItem` / `ROUTE-1349` at `app/Http/Controllers/Hr/CompensationController.php:550`; it is not runtime-observed.
- **information presented** is applicable only to `createReview` / `ROUTE-1350` at `app/Http/Controllers/Hr/CompensationController.php:416`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compensation/review-detail.tsx`, `resources/js/pages/hr/compensation/reviews.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1344` / `storeReview`: fields `title`; success app/Http/Controllers/Hr/CompensationController.php:452 `return redirect()->route('hr.compensation.reviews')->with('success', 'Compensation review created.');`.
- `ROUTE-1346` / `applyReview`: success app/Http/Controllers/Hr/CompensationController.php:525 `return redirect()->back()->with('success', 'Compensation review applied successfully. Employee profiles have been updated.');`.
- `ROUTE-1347` / `approveReview`: success app/Http/Controllers/Hr/CompensationController.php:506 `return redirect()->back()->with('success', 'Compensation review approved. You can now apply it to update employee salaries.');`.
- `ROUTE-1348` / `approveReviewItem`: success app/Http/Controllers/Hr/CompensationController.php:544 `return redirect()->back()->with('success', 'Line approved.');`.
- `ROUTE-1349` / `rejectReviewItem`: fields `reason`; success app/Http/Controllers/Hr/CompensationController.php:567 `return redirect()->back()->with('success', 'Line rejected.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/CompensationController.php:382 `return Inertia::render('hr/compensation/reviews', [`; app/Http/Controllers/Hr/CompensationController.php:452 `return redirect()->route('hr.compensation.reviews')->with('success', 'Compensation review created.');`; app/Http/Controllers/Hr/CompensationController.php:476 `return Inertia::render('hr/compensation/review-detail', [`; app/Http/Controllers/Hr/CompensationController.php:522 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CompensationController.php:525 `return redirect()->back()->with('success', 'Compensation review applied successfully. Employee profiles have been updated.');`; app/Http/Controllers/Hr/CompensationController.php:503 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CompensationController.php:506 `return redirect()->back()->with('success', 'Compensation review approved. You can now apply it to update employee salaries.');`; app/Http/Controllers/Hr/CompensationController.php:541 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CompensationController.php:544 `return redirect()->back()->with('success', 'Line approved.');`; app/Http/Controllers/Hr/CompensationController.php:564 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/CompensationController.php:567 `return redirect()->back()->with('success', 'Line rejected.');`; app/Http/Controllers/Hr/CompensationController.php:421 `return redirect()->route('hr.compensation.reviews');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compensation/reviews` — `hr.compensation.reviews` — `App\Http\Controllers\Hr\CompensationController@reviews` — `app/Http/Controllers/Hr/CompensationController.php:367` — middleware `web, auth, permission:hr.compensation.view`
- `POST hr/compensation/reviews` — `hr.compensation.reviews.store` — `App\Http\Controllers\Hr\CompensationController@storeReview` — `app/Http/Controllers/Hr/CompensationController.php:427` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `GET|HEAD hr/compensation/reviews/{review}` — `hr.compensation.reviews.show` — `App\Http\Controllers\Hr\CompensationController@showReview` — `app/Http/Controllers/Hr/CompensationController.php:458` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `POST hr/compensation/reviews/{review}/apply` — `hr.compensation.reviews.apply` — `App\Http\Controllers\Hr\CompensationController@applyReview` — `app/Http/Controllers/Hr/CompensationController.php:512` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `POST hr/compensation/reviews/{review}/approve` — `hr.compensation.reviews.approve` — `App\Http\Controllers\Hr\CompensationController@approveReview` — `app/Http/Controllers/Hr/CompensationController.php:493` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `POST hr/compensation/reviews/{review}/items/{item}/approve` — `hr.compensation.reviews.items.approve` — `App\Http\Controllers\Hr\CompensationController@approveReviewItem` — `app/Http/Controllers/Hr/CompensationController.php:531` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `POST hr/compensation/reviews/{review}/items/{item}/reject` — `hr.compensation.reviews.items.reject` — `App\Http\Controllers\Hr\CompensationController@rejectReviewItem` — `app/Http/Controllers/Hr/CompensationController.php:550` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`
- `GET|HEAD hr/compensation/reviews/create` — `hr.compensation.reviews.create` — `App\Http\Controllers\Hr\CompensationController@createReview` — `app/Http/Controllers/Hr/CompensationController.php:416` — middleware `web, auth, permission:hr.compensation.view, permission:hr.compensation.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CompensationController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compensation/review-detail.tsx`, `resources/js/pages/hr/compensation/reviews.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
