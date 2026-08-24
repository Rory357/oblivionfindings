# CAP-HR-CONTROLLERS-PERFORMANCE-REVIEW-SETUP-SELF-ASSESSMENT: Performance review setup and self-assessment

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
2. Use `GET|HEAD governance/performance/{review}` (`governance.performance.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:43-54`.
3. Use `GET|HEAD governance/performance/{review}/edit` (`governance.performance.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:141-148`.
4. Use `GET|HEAD governance/performance/create` (`governance.performance.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:18-25`.
5. Invoke only the owning control for `POST governance/performance` (`governance.performance.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:56-81`; `reviewee_id`, `review_cycle`, `review_type`, `period_start`, `period_end`.
6. Invoke only the owning control for `PUT governance/performance/{review}` (`governance.performance.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:83-95`; `overall_rating`, `overall_assessment`, `board_decision`, `decision_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0961` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:27`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0962` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:56`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0963` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:43`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0964` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:83`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0967` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:141`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0971` at `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:18`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Performance/Create.tsx`, `resources/js/pages/Governance/Performance/Edit.tsx`, `resources/js/pages/Governance/Performance/Index.tsx`, `resources/js/pages/Governance/Performance/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0962` / `store`: fields `reviewee_id`, `review_cycle`, `review_type`, `period_start`, `period_end`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:80 `->with('success', 'Performance review created.');`.
- `ROUTE-0964` / `update`: fields `overall_rating`, `overall_assessment`, `board_decision`, `decision_notes`; success app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:94 `return redirect()->back()->with('success', 'Review updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:92 `$review->update($validated);`; responses app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:37 `return Inertia::render('Governance/Performance/Index', [`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:79 `return redirect()->route('governance.performance.show', $review)`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:49 `return Inertia::render('Governance/Performance/Show', [`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:94 `return redirect()->back()->with('success', 'Review updated.');`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:145 `return Inertia::render('Governance/Performance/Edit', [`; app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:22 `return Inertia::render('Governance/Performance/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/performance` — `governance.performance.index` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@index` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:27` — middleware `web, auth, permission:governance.performance.view`
- `POST governance/performance` — `governance.performance.store` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@store` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:56` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`
- `GET|HEAD governance/performance/{review}` — `governance.performance.show` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@show` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:43` — middleware `web, auth, permission:governance.performance.view`
- `PUT governance/performance/{review}` — `governance.performance.update` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@update` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:83` — middleware `web, auth, permission:governance.performance.view, permission:governance.performance.manage`
- `GET|HEAD governance/performance/{review}/edit` — `governance.performance.edit` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@edit` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:141` — middleware `web, auth, permission:governance.performance.view`
- `GET|HEAD governance/performance/create` — `governance.performance.create` — `App\Domain\Governance\Http\Controllers\PerformanceReviewController@create` — `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:18` — middleware `web, auth, permission:governance.performance.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Performance/Create.tsx`, `resources/js/pages/Governance/Performance/Edit.tsx`, `resources/js/pages/Governance/Performance/Index.tsx`, `resources/js/pages/Governance/Performance/Show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
