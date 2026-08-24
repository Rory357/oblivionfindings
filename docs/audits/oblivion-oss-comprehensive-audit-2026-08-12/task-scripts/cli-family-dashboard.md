# CLI-FAMILY-DASHBOARD: Family Dashboard

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-FAMILY-DASHBOARD`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/dashboard` (`portal.clients.dashboard`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/dashboard` (`portal.clients.dashboard`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/clients/{client}/visit-requests` (`portal.clients.visit-requests.store`, action `storeVisitRequest`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/FamilyDashboardController.php:497-541`; `requested_date`, `preferred_time_start`, `preferred_time_end`, `visit_type`, `notes`.
3. Invoke only the owning control for `POST portal/clients/{client}/visit-requests/{visit}/cancel` (`portal.clients.visit-requests.cancel`, action `cancelVisitRequest`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Portal/FamilyDashboardController.php:543-578`; FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2251` at `app/Http/Controllers/Portal/FamilyDashboardController.php:24`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeVisitRequest` / `ROUTE-2282` at `app/Http/Controllers/Portal/FamilyDashboardController.php:497`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelVisitRequest` / `ROUTE-2283` at `app/Http/Controllers/Portal/FamilyDashboardController.php:543`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/family-dashboard.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2282` / `storeVisitRequest`: fields `requested_date`, `preferred_time_start`, `preferred_time_end`, `visit_type`, `notes`; success app/Http/Controllers/Portal/FamilyDashboardController.php:540 `return redirect()->back()->with('success', 'Visit request submitted successfully.');`.
- `ROUTE-2283` / `cancelVisitRequest`: FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; success app/Http/Controllers/Portal/FamilyDashboardController.php:577 `return redirect()->back()->with('success', 'Visit request cancelled.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/FamilyDashboardController.php:511 `$visit = FamilyVisitRequest::create([`; app/Http/Controllers/Portal/FamilyDashboardController.php:559 `$lockedVisit->update(['status' => 'cancelled']);`; responses app/Http/Controllers/Portal/FamilyDashboardController.php:61 `return 'offline';`; app/Http/Controllers/Portal/FamilyDashboardController.php:64 `return 'online';`; app/Http/Controllers/Portal/FamilyDashboardController.php:67 `return 'away';`; app/Http/Controllers/Portal/FamilyDashboardController.php:70 `return 'offline';`; app/Http/Controllers/Portal/FamilyDashboardController.php:334 `return $counts;`; app/Http/Controllers/Portal/FamilyDashboardController.php:370 `return inertia('portal/family-dashboard', [`; app/Http/Controllers/Portal/FamilyDashboardController.php:412 `return null;`; app/Http/Controllers/Portal/FamilyDashboardController.php:419 `return null;`; app/Http/Controllers/Portal/FamilyDashboardController.php:422 `return [`; app/Http/Controllers/Portal/FamilyDashboardController.php:435 `return null;`; app/Http/Controllers/Portal/FamilyDashboardController.php:444 `return null;`; app/Http/Controllers/Portal/FamilyDashboardController.php:447 `return [`; app/Http/Controllers/Portal/FamilyDashboardController.php:540 `return redirect()->back()->with('success', 'Visit request submitted successfully.');`; app/Http/Controllers/Portal/FamilyDashboardController.php:577 `return redirect()->back()->with('success', 'Visit request cancelled.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/dashboard` — `portal.clients.dashboard` — `App\Http\Controllers\Portal\FamilyDashboardController@show` — `app/Http/Controllers/Portal/FamilyDashboardController.php:24` — middleware `web, auth`
- `POST portal/clients/{client}/visit-requests` — `portal.clients.visit-requests.store` — `App\Http\Controllers\Portal\FamilyDashboardController@storeVisitRequest` — `app/Http/Controllers/Portal/FamilyDashboardController.php:497` — middleware `web, auth`
- `POST portal/clients/{client}/visit-requests/{visit}/cancel` — `portal.clients.visit-requests.cancel` — `App\Http\Controllers\Portal\FamilyDashboardController@cancelVisitRequest` — `app/Http/Controllers/Portal/FamilyDashboardController.php:543` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/FamilyDashboardController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/family-dashboard.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
