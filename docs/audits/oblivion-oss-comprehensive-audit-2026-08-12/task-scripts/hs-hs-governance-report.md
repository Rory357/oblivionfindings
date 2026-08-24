# HS-HS-GOVERNANCE-REPORT: Hs Governance Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.view`
- Owning module: Health and safety
- Legacy family: `HS-HS-GOVERNANCE-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/reports/board-summary` (`health-safety.reports.board-summary`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/reports/board-summary` (`health-safety.reports.board-summary`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/reports/corrective-action-traceability` (`health-safety.reports.corrective-action-traceability`, action `correctiveActionTraceability`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:72-84`.
3. Use `GET|HEAD health-safety/reports/investigation-outcomes` (`health-safety.reports.investigation-outcomes`, action `investigationOutcomes`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:59-67`.
4. Use `GET|HEAD health-safety/reports/risk-assessment-register` (`health-safety.reports.risk-assessment-register`, action `riskAssessmentRegister`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:89-94`.
5. Use `GET|HEAD health-safety/reports/worksafe-register` (`health-safety.reports.worksafe-register`, action `worksafeRegister`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:46-54`.

## Source-applicable states and transitions

- **information presented** is applicable only to `boardSummary` / `ROUTE-1194` at `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:33`; it is not runtime-observed.
- **information presented** is applicable only to `correctiveActionTraceability` / `ROUTE-1195` at `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:72`; it is not runtime-observed.
- **information presented** is applicable only to `investigationOutcomes` / `ROUTE-1196` at `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:59`; it is not runtime-observed.
- **information presented** is applicable only to `riskAssessmentRegister` / `ROUTE-1197` at `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:89`; it is not runtime-observed.
- **information presented** is applicable only to `worksafeRegister` / `ROUTE-1198` at `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:46`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/reports/board-summary` — `health-safety.reports.board-summary` — `App\Http\Controllers\HealthSafety\HsGovernanceReportController@boardSummary` — `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:33` — middleware `web, auth, permission:governance.view`
- `GET|HEAD health-safety/reports/corrective-action-traceability` — `health-safety.reports.corrective-action-traceability` — `App\Http\Controllers\HealthSafety\HsGovernanceReportController@correctiveActionTraceability` — `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:72` — middleware `web, auth, permission:governance.view`
- `GET|HEAD health-safety/reports/investigation-outcomes` — `health-safety.reports.investigation-outcomes` — `App\Http\Controllers\HealthSafety\HsGovernanceReportController@investigationOutcomes` — `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:59` — middleware `web, auth, permission:governance.view`
- `GET|HEAD health-safety/reports/risk-assessment-register` — `health-safety.reports.risk-assessment-register` — `App\Http\Controllers\HealthSafety\HsGovernanceReportController@riskAssessmentRegister` — `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:89` — middleware `web, auth, permission:governance.view`
- `GET|HEAD health-safety/reports/worksafe-register` — `health-safety.reports.worksafe-register` — `App\Http\Controllers\HealthSafety\HsGovernanceReportController@worksafeRegister` — `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php:46` — middleware `web, auth, permission:governance.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsGovernanceReportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
