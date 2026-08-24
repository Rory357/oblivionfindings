# REP-SUMMARY: Summary

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:summaries.generate`
- Owning module: Reporting and summaries
- Legacy family: `REP-SUMMARY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/summaries` (`operations.summaries`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:summaries.generate`.
- Exact middleware atoms: `web`, `auth`, `throttle:ai-queries`, `permission:summaries.generate`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/summaries` (`operations.summaries`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD summaries/clients/{client}` (`summaries.client`, action `client`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SummaryController.php:50-71`.
3. Use `GET|HEAD summaries/me` (`summaries.me`, action `my`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SummaryController.php:15-21`.
4. Use `GET|HEAD summaries/staff/{user}` (`summaries.staff`, action `staff`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SummaryController.php:23-48`.
5. Invoke only the owning control for `POST portal/summaries/generate` (`portal.summaries.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Http/Controllers/SummaryController.php:73-102`; `scope_type`.
6. Invoke only the owning control for `POST summaries/generate` (`summaries.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Http/Controllers/SummaryController.php:73-102`; `scope_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `my` / `ROUTE-2219` at `app/Http/Controllers/SummaryController.php:15`; it is not runtime-observed.
- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-2290` at `app/Http/Controllers/SummaryController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `client` / `ROUTE-2946` at `app/Http/Controllers/SummaryController.php:50`; it is not runtime-observed.
- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-2947` at `app/Http/Controllers/SummaryController.php:73`; it is not runtime-observed.
- **information presented** is applicable only to `my` / `ROUTE-2948` at `app/Http/Controllers/SummaryController.php:15`; it is not runtime-observed.
- **information presented** is applicable only to `staff` / `ROUTE-2949` at `app/Http/Controllers/SummaryController.php:23`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/summaries/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2290` / `generate`: fields `scope_type`.
- `ROUTE-2947` / `generate`: fields `scope_type`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/SummaryController.php:20 `return $this->staff($request, $user);`; app/Http/Controllers/SummaryController.php:101 `return back()->with('status', 'Summary generation queued.');`; app/Http/Controllers/SummaryController.php:66 `return inertia('summaries/index', [`; app/Http/Controllers/SummaryController.php:43 `return inertia('summaries/index', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/SummaryController.php:93 `GenerateSummaryJob::dispatch(`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD operations/summaries` — `operations.summaries` — `App\Http\Controllers\SummaryController@my` — `app/Http/Controllers/SummaryController.php:15` — middleware `web, auth`
- `POST portal/summaries/generate` — `portal.summaries.generate` — `App\Http\Controllers\SummaryController@generate` — `app/Http/Controllers/SummaryController.php:73` — middleware `web, auth, throttle:ai-queries`
- `GET|HEAD summaries/clients/{client}` — `summaries.client` — `App\Http\Controllers\SummaryController@client` — `app/Http/Controllers/SummaryController.php:50` — middleware `web, auth`
- `POST summaries/generate` — `summaries.generate` — `App\Http\Controllers\SummaryController@generate` — `app/Http/Controllers/SummaryController.php:73` — middleware `web, auth, permission:summaries.generate, throttle:ai-queries`
- `GET|HEAD summaries/me` — `summaries.me` — `App\Http\Controllers\SummaryController@my` — `app/Http/Controllers/SummaryController.php:15` — middleware `web, auth`
- `GET|HEAD summaries/staff/{user}` — `summaries.staff` — `App\Http\Controllers\SummaryController@staff` — `app/Http/Controllers/SummaryController.php:23` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SummaryController.php`.
- Exact render/action page relationships: `resources/js/pages/summaries/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
