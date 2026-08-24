# CAP-HR-MY-HR-ONE-REVIEWS: My one-to-one and review actions

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/one` (`hr.my.one`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/one` (`hr.my.one`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/reviews` (`hr.my.reviews`, action `reviews`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:1006-1044`.
3. Invoke only the owning control for `POST hr/my/one/{note}/acknowledge` (`hr.my.one.acknowledge`, action `acknowledgeOne`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/MyHrController.php:1608-1627`; `employee_comments`.
4. Invoke only the owning control for `PUT hr/my/reviews/{review}` (`hr.my.reviews.update`, action `updateReview`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/MyHrController.php:1046-1068`; `employee_comments`.

## Source-applicable states and transitions

- **information presented** is applicable only to `one` / `ROUTE-1530` at `app/Http/Controllers/Hr/MyHrController.php:1543`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeOne` / `ROUTE-1531` at `app/Http/Controllers/Hr/MyHrController.php:1608`; it is not runtime-observed.
- **information presented** is applicable only to `reviews` / `ROUTE-1539` at `app/Http/Controllers/Hr/MyHrController.php:1006`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateReview` / `ROUTE-1540` at `app/Http/Controllers/Hr/MyHrController.php:1046`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/one.tsx`, `resources/js/pages/hr/my/reviews.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1531` / `acknowledgeOne`: fields `employee_comments`; success app/Http/Controllers/Hr/MyHrController.php:1626 `return redirect()->back()->with('success', 'Marked as reviewed.');`.
- `ROUTE-1540` / `updateReview`: fields `employee_comments`; success app/Http/Controllers/Hr/MyHrController.php:1067 `return redirect()->back()->with('success', 'Review updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/MyHrController.php:1620 `$note->update([`; app/Http/Controllers/Hr/MyHrController.php:1065 `$review->update($data);`; responses app/Http/Controllers/Hr/MyHrController.php:1600 `return Inertia::render('hr/my/one', [`; app/Http/Controllers/Hr/MyHrController.php:1626 `return redirect()->back()->with('success', 'Marked as reviewed.');`; app/Http/Controllers/Hr/MyHrController.php:1040 `return Inertia::render('hr/my/reviews', [`; app/Http/Controllers/Hr/MyHrController.php:1067 `return redirect()->back()->with('success', 'Review updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/one` — `hr.my.one` — `App\Http\Controllers\Hr\MyHrController@one` — `app/Http/Controllers/Hr/MyHrController.php:1543` — middleware `web, auth`
- `POST hr/my/one/{note}/acknowledge` — `hr.my.one.acknowledge` — `App\Http\Controllers\Hr\MyHrController@acknowledgeOne` — `app/Http/Controllers/Hr/MyHrController.php:1608` — middleware `web, auth`
- `GET|HEAD hr/my/reviews` — `hr.my.reviews` — `App\Http\Controllers\Hr\MyHrController@reviews` — `app/Http/Controllers/Hr/MyHrController.php:1006` — middleware `web, auth`
- `PUT hr/my/reviews/{review}` — `hr.my.reviews.update` — `App\Http\Controllers\Hr\MyHrController@updateReview` — `app/Http/Controllers/Hr/MyHrController.php:1046` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/one.tsx`, `resources/js/pages/hr/my/reviews.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
