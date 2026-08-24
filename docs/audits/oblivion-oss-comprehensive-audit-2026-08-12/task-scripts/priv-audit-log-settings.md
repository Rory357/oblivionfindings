# PRIV-AUDIT-LOG-SETTINGS: Audit Log Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:audit.viewAny|settings.access.manage`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-AUDIT-LOG-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/audit-logs` (`settings.audit_logs`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:audit.viewAny|settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:audit.viewAny|settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/audit-logs` (`settings.audit_logs`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD settings/audit-logs/export` (`settings.audit_logs.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Settings/AuditLogSettingsController.php:41-71`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2626` at `app/Http/Controllers/Settings/AuditLogSettingsController.php:16`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2627` at `app/Http/Controllers/Settings/AuditLogSettingsController.php:41`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/settings/audit-logs.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to the requested file/report being returned or the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD settings/audit-logs` — `settings.audit_logs` — `App\Http\Controllers\Settings\AuditLogSettingsController@index` — `app/Http/Controllers/Settings/AuditLogSettingsController.php:16` — middleware `web, auth, permission:audit.viewAny|settings.access.manage`
- `GET|HEAD settings/audit-logs/export` — `settings.audit_logs.export` — `App\Http\Controllers\Settings\AuditLogSettingsController@export` — `app/Http/Controllers/Settings/AuditLogSettingsController.php:41` — middleware `web, auth, permission:audit.viewAny|settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/AuditLogSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/settings/audit-logs.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
