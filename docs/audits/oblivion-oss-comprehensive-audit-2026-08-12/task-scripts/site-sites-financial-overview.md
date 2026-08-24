# SITE-SITES-FINANCIAL-OVERVIEW: Sites Financial Overview

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITES-FINANCIAL-OVERVIEW`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/sites` (`finance.sites.overview`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.dashboard`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.dashboard`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/sites` (`finance.sites.overview`); the route is exact, but menu visibility and runtime access were not executed.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0692` at `app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:22`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/sites-overview/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0692` / `index`: fields `from`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/sites` — `finance.sites.overview` — `App\Domain\Finance\Http\Controllers\SitesFinancialOverviewController@index` — `app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php:22` — middleware `web, auth, permission:finance.dashboard`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/SitesFinancialOverviewController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/sites-overview/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
