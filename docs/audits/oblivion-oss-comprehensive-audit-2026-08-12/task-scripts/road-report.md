# ROAD-REPORT: Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roadmap.reports.export`, `permission:roadmap.view|roadmap.reports.export`
- Owning module: Roadmap
- Legacy family: `ROAD-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `roadmap/reports/snapshots/{snapshot}` (`roadmap.reports.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roadmap.reports.export`, `permission:roadmap.view|roadmap.reports.export`.
- Exact middleware atoms: `web`, `auth`, `permission:roadmap.reports.export`, `permission:roadmap.view|roadmap.reports.export`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD roadmap/reports/snapshots/{snapshot}` (`roadmap.reports.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST roadmap/reports/{type}` (`roadmap.reports.generate`, action `generate`). Source category: **mutation outcome source gap (generate)**; controller `app/Domain/Roadmap/Http/Controllers/ReportController.php:17-36`; `plan_id`.

## Source-applicable states and transitions

- **mutation outcome source gap (generate)** is applicable only to `generate` / `ROUTE-2491` at `app/Domain/Roadmap/Http/Controllers/ReportController.php:17`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2492` at `app/Domain/Roadmap/Http/Controllers/ReportController.php:38`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2491` / `generate`: fields `plan_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Roadmap/Http/Controllers/ReportController.php:33 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/ReportController.php:42 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST roadmap/reports/{type}` — `roadmap.reports.generate` — `App\Domain\Roadmap\Http\Controllers\ReportController@generate` — `app/Domain/Roadmap/Http/Controllers/ReportController.php:17` — middleware `web, auth, permission:roadmap.reports.export`
- `GET|HEAD roadmap/reports/snapshots/{snapshot}` — `roadmap.reports.show` — `App\Domain\Roadmap\Http\Controllers\ReportController@show` — `app/Domain/Roadmap/Http/Controllers/ReportController.php:38` — middleware `web, auth, permission:roadmap.view|roadmap.reports.export`

## Source anchors and limits

- Backend anchor: `app/Domain/Roadmap/Http/Controllers/ReportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
