# PRIV-PRIVACY-REPORT: Privacy Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.viewRequests`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-PRIVACY-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/reports` (`privacy.reports.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.viewRequests`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.viewRequests`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/reports` (`privacy.reports.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/reports/compliance` (`privacy.reports.compliance`, action `compliance`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/PrivacyReportController.php:21-70`.
3. Use `GET|HEAD privacy/reports/export` (`privacy.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/PrivacyReportController.php:76-95`.

## Source-applicable states and transitions

- **information presented** is applicable only to `compliance` / `ROUTE-2322` at `app/Http/Controllers/PrivacyReportController.php:21`; it is not runtime-observed.
- **information presented** is applicable only to `compliance` / `ROUTE-2323` at `app/Http/Controllers/PrivacyReportController.php:21`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2324` at `app/Http/Controllers/PrivacyReportController.php:76`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/reports/compliance.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/reports` — `privacy.reports.index` — `App\Http\Controllers\PrivacyReportController@compliance` — `app/Http/Controllers/PrivacyReportController.php:21` — middleware `web, auth, permission:privacy.viewRequests`
- `GET|HEAD privacy/reports/compliance` — `privacy.reports.compliance` — `App\Http\Controllers\PrivacyReportController@compliance` — `app/Http/Controllers/PrivacyReportController.php:21` — middleware `web, auth, permission:privacy.viewRequests`
- `GET|HEAD privacy/reports/export` — `privacy.reports.export` — `App\Http\Controllers\PrivacyReportController@export` — `app/Http/Controllers/PrivacyReportController.php:76` — middleware `web, auth, permission:privacy.viewRequests`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/PrivacyReportController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/reports/compliance.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
