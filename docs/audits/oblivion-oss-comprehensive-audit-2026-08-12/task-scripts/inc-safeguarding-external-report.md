# INC-SAFEGUARDING-EXTERNAL-REPORT: Safeguarding External Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:safeguarding.report.external`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-EXTERNAL-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:safeguarding.report.external`.
- Exact middleware atoms: `web`, `auth`, `permission:safeguarding.report.external`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST safeguarding/{concern}/external-reports` (`safeguarding.externalReports.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingExternalReportController.php:17-51`; `authority_type`, `authority_name`, `authority_contact`, `reported_at`, `report_method`, `report_summary`.
3. Invoke only the owning control for `PUT safeguarding/{concern}/external-reports/{report}` (`safeguarding.externalReports.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingExternalReportController.php:56-86`; `authority_reference`, `acknowledgement_received`, `acknowledgment_received`, `acknowledged_at`, `acknowledgment_date`, `acknowledgement_reference`, `acknowledgment_reference`, `authority_action`, `authority_feedback`, `authority_feedback_at`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2515` at `app/Http/Controllers/SafeguardingExternalReportController.php:17`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2516` at `app/Http/Controllers/SafeguardingExternalReportController.php:56`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2515` / `store`: fields `authority_type`, `authority_name`, `authority_contact`, `reported_at`, `report_method`, `report_summary`; success app/Http/Controllers/SafeguardingExternalReportController.php:50 `return back()->with('success', 'External report created successfully.');`.
- `ROUTE-2516` / `update`: fields `authority_reference`, `acknowledgement_received`, `acknowledgment_received`, `acknowledged_at`, `acknowledgment_date`, `acknowledgement_reference`, `acknowledgment_reference`, `authority_action`, `authority_feedback`, `authority_feedback_at`; success app/Http/Controllers/SafeguardingExternalReportController.php:85 `return back()->with('success', 'External report updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingExternalReportController.php:37 `SafeguardingExternalReport::create($validated);`; app/Http/Controllers/SafeguardingExternalReportController.php:46 `$concern->update(['status' => 'referred_external']);`; app/Http/Controllers/SafeguardingExternalReportController.php:83 `$report->update($validated);`; responses app/Http/Controllers/SafeguardingExternalReportController.php:50 `return back()->with('success', 'External report created successfully.');`; app/Http/Controllers/SafeguardingExternalReportController.php:85 `return back()->with('success', 'External report updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/external-reports` — `safeguarding.externalReports.store` — `App\Http\Controllers\SafeguardingExternalReportController@store` — `app/Http/Controllers/SafeguardingExternalReportController.php:17` — middleware `web, auth, permission:safeguarding.report.external`
- `PUT safeguarding/{concern}/external-reports/{report}` — `safeguarding.externalReports.update` — `App\Http\Controllers\SafeguardingExternalReportController@update` — `app/Http/Controllers/SafeguardingExternalReportController.php:56` — middleware `web, auth, permission:safeguarding.report.external`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingExternalReportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
