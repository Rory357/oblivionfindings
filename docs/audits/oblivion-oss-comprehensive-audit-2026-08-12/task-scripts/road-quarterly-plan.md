# ROAD-QUARTERLY-PLAN: Quarterly Plan

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.approve`, `permission:roadmap.manage`
- Owning module: Roadmap
- Legacy family: `ROAD-QUARTERLY-PLAN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `roadmap/quarterly-plans` (`roadmap.plans.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.approve`, `permission:roadmap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:roadmap.view`, `permission:roadmap.approve`, `permission:roadmap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD roadmap/quarterly-plans` (`roadmap.plans.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD roadmap/quarterly-plans/{plan}` (`roadmap.plans.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:71-84`.
3. Invoke only the owning control for `POST roadmap/quarterly-plans/{plan}/approve` (`roadmap.plans.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:112-123`; no exact validation fields extracted.
4. Invoke only the owning control for `POST roadmap/quarterly-plans/{plan}/publish` (`roadmap.plans.publish`, action `publish`). Source category: **mutation outcome source gap (publish)**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:125-136`; no exact validation fields extracted.
5. Invoke only the owning control for `POST roadmap/quarterly-plans/{plan}/revise` (`roadmap.plans.revise`, action `revise`). Source category: **mutation outcome source gap (revise)**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:138-157`; `change_summary`.
6. Invoke only the owning control for `POST roadmap/quarterly-plans/{plan}/submit-executive` (`roadmap.plans.submit_executive`, action `submitExecutiveReview`). Source category: **created/recorded**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:99-110`; no exact validation fields extracted.
7. Invoke only the owning control for `POST roadmap/quarterly-plans/{plan}/submit-manager` (`roadmap.plans.submit_manager`, action `submitManagerReview`). Source category: **created/recorded**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:86-97`; no exact validation fields extracted.
8. Invoke only the owning control for `POST roadmap/quarterly-plans/generate` (`roadmap.plans.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:56-69`; FormRequest `app/Domain/Roadmap/Http/Requests/StoreQuarterlyPlanRequest.php:14`; `fiscal_year`, `quarter`, `preset`, `tenant_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2483` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:23`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2484` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:71`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2485` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:112`; it is not runtime-observed.
- **mutation outcome source gap (publish)** is applicable only to `publish` / `ROUTE-2486` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:125`; it is not runtime-observed.
- **mutation outcome source gap (revise)** is applicable only to `revise` / `ROUTE-2487` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:138`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitExecutiveReview` / `ROUTE-2488` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:99`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitManagerReview` / `ROUTE-2489` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:86`; it is not runtime-observed.
- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-2490` at `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:56`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Roadmap/QuarterlyPlans/Index.tsx`, `resources/js/pages/Roadmap/QuarterlyPlans/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2487` / `revise`: fields `change_summary`.
- `ROUTE-2490` / `generate`: FormRequest `app/Domain/Roadmap/Http/Requests/StoreQuarterlyPlanRequest.php:14`; fields `fiscal_year`, `quarter`, `preset`, `tenant_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:42 `return response()->json(['items' => $items]);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:45 `return Inertia::render('Roadmap/QuarterlyPlans/Index', [`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:77 `return response()->json(['item' => $item]);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:80 `return Inertia::render('Roadmap/QuarterlyPlans/Show', [`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:117 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:121 `return response()->json(['message' => $e->getMessage()], 422);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:130 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:134 `return response()->json(['message' => $e->getMessage()], 422);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:147 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:155 `return response()->json(['message' => $e->getMessage()], 422);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:104 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:108 `return response()->json(['message' => $e->getMessage()], 422);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:91 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:95 `return response()->json(['message' => $e->getMessage()], 422);`; app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:68 `return response()->json(['item' => $plan], 201);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD roadmap/quarterly-plans` — `roadmap.plans.index` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@index` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:23` — middleware `web, auth, permission:roadmap.view`
- `GET|HEAD roadmap/quarterly-plans/{plan}` — `roadmap.plans.show` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@show` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:71` — middleware `web, auth, permission:roadmap.view`
- `POST roadmap/quarterly-plans/{plan}/approve` — `roadmap.plans.approve` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@approve` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:112` — middleware `web, auth, permission:roadmap.approve`
- `POST roadmap/quarterly-plans/{plan}/publish` — `roadmap.plans.publish` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@publish` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:125` — middleware `web, auth, permission:roadmap.approve`
- `POST roadmap/quarterly-plans/{plan}/revise` — `roadmap.plans.revise` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@revise` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:138` — middleware `web, auth, permission:roadmap.approve`
- `POST roadmap/quarterly-plans/{plan}/submit-executive` — `roadmap.plans.submit_executive` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@submitExecutiveReview` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:99` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/quarterly-plans/{plan}/submit-manager` — `roadmap.plans.submit_manager` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@submitManagerReview` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:86` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/quarterly-plans/generate` — `roadmap.plans.generate` — `App\Domain\Roadmap\Http\Controllers\QuarterlyPlanController@generate` — `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php:56` — middleware `web, auth, permission:roadmap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Roadmap/Http/Controllers/QuarterlyPlanController.php`.
- Exact render/action page relationships: `resources/js/pages/Roadmap/QuarterlyPlans/Index.tsx`, `resources/js/pages/Roadmap/QuarterlyPlans/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
