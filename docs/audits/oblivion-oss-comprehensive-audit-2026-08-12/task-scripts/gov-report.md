# GOV-REPORT: Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.view`
- Owning module: Governance
- Legacy family: `GOV-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/reports/board-monthly` (`governance.reports.board-monthly`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.view`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/reports/board-monthly` (`governance.reports.board-monthly`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/reports/committee/{committee}` (`governance.reports.committee`, action `committeeReport`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ReportController.php:56-103`.
3. Use `GET|HEAD governance/reports/compliance-status` (`governance.reports.compliance-status`, action `complianceStatus`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ReportController.php:105-122`.
4. Use `GET|HEAD governance/reports/export/{type}` (`governance.reports.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Governance/Http/Controllers/ReportController.php:183-193`.
5. Use `GET|HEAD governance/reports/risk-narrative` (`governance.reports.risk-narrative`, action `riskNarrative`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/ReportController.php:124-159`.
6. Invoke only the owning control for `POST governance/reports/evidence-pack` (`governance.reports.evidence-pack`, action `evidencePack`). Source category: **mutation outcome source gap (evidencePack)**; controller `app/Domain/Governance/Http/Controllers/ReportController.php:161-181`; `type`, `period_start`, `period_end`, `framework`.

## Source-applicable states and transitions

- **information presented** is applicable only to `boardMonthly` / `ROUTE-0982` at `app/Domain/Governance/Http/Controllers/ReportController.php:27`; it is not runtime-observed.
- **information presented** is applicable only to `committeeReport` / `ROUTE-0983` at `app/Domain/Governance/Http/Controllers/ReportController.php:56`; it is not runtime-observed.
- **information presented** is applicable only to `complianceStatus` / `ROUTE-0984` at `app/Domain/Governance/Http/Controllers/ReportController.php:105`; it is not runtime-observed.
- **mutation outcome source gap (evidencePack)** is applicable only to `evidencePack` / `ROUTE-0985` at `app/Domain/Governance/Http/Controllers/ReportController.php:161`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-0986` at `app/Domain/Governance/Http/Controllers/ReportController.php:183`; it is not runtime-observed.
- **information presented** is applicable only to `riskNarrative` / `ROUTE-0987` at `app/Domain/Governance/Http/Controllers/ReportController.php:124`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Reports/BoardMonthly.tsx`, `resources/js/pages/Governance/Reports/Committee.tsx`, `resources/js/pages/Governance/Reports/ComplianceStatus.tsx`, `resources/js/pages/Governance/Reports/RiskNarrative.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0985` / `evidencePack`: fields `type`, `period_start`, `period_end`, `framework`.
- `ROUTE-0986` / `export`: failure app/Domain/Governance/Http/Controllers/ReportController.php:191 `default => abort(404),`.

## Failure and recovery paths

- `export`: app/Domain/Governance/Http/Controllers/ReportController.php:191 `default => abort(404),`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Governance/Http/Controllers/ReportController.php:50 `return Inertia::render('Governance/Reports/BoardMonthly', [`; app/Domain/Governance/Http/Controllers/ReportController.php:99 `return Inertia::render('Governance/Reports/Committee', [`; app/Domain/Governance/Http/Controllers/ReportController.php:119 `return Inertia::render('Governance/Reports/ComplianceStatus', [`; app/Domain/Governance/Http/Controllers/ReportController.php:177 `return response()->json([`; app/Domain/Governance/Http/Controllers/ReportController.php:187 `return match(true) {`; app/Domain/Governance/Http/Controllers/ReportController.php:150 `return Inertia::render('Governance/Reports/RiskNarrative', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/reports/board-monthly` — `governance.reports.board-monthly` — `App\Domain\Governance\Http\Controllers\ReportController@boardMonthly` — `app/Domain/Governance/Http/Controllers/ReportController.php:27` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/reports/committee/{committee}` — `governance.reports.committee` — `App\Domain\Governance\Http\Controllers\ReportController@committeeReport` — `app/Domain/Governance/Http/Controllers/ReportController.php:56` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/reports/compliance-status` — `governance.reports.compliance-status` — `App\Domain\Governance\Http\Controllers\ReportController@complianceStatus` — `app/Domain/Governance/Http/Controllers/ReportController.php:105` — middleware `web, auth, permission:governance.view`
- `POST governance/reports/evidence-pack` — `governance.reports.evidence-pack` — `App\Domain\Governance\Http\Controllers\ReportController@evidencePack` — `app/Domain/Governance/Http/Controllers/ReportController.php:161` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/reports/export/{type}` — `governance.reports.export` — `App\Domain\Governance\Http\Controllers\ReportController@export` — `app/Domain/Governance/Http/Controllers/ReportController.php:183` — middleware `web, auth, permission:governance.view`
- `GET|HEAD governance/reports/risk-narrative` — `governance.reports.risk-narrative` — `App\Domain\Governance\Http\Controllers\ReportController@riskNarrative` — `app/Domain/Governance/Http/Controllers/ReportController.php:124` — middleware `web, auth, permission:governance.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/ReportController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Reports/BoardMonthly.tsx`, `resources/js/pages/Governance/Reports/Committee.tsx`, `resources/js/pages/Governance/Reports/ComplianceStatus.tsx`, `resources/js/pages/Governance/Reports/RiskNarrative.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
