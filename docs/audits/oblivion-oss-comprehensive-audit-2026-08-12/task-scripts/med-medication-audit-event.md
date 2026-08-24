# MED-MEDICATION-AUDIT-EVENT: Medication Audit Event

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.reports.export`, `permission:medications.administer.record|clients.update`, `permission:medications.audit.view`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATION-AUDIT-EVENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/audit/event/{id}/export` (`emar.audit.event.export`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.reports.export`, `permission:medications.administer.record|clients.update`, `permission:medications.audit.view`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.reports.export`, `permission:medications.administer.record|clients.update`, `permission:medications.audit.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/audit/event/{id}/export` (`emar.audit.event.export`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/audit/event/{id}/integrity` (`emar.audit.event.integrity`, action `integrity`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/MedicationAuditEventController.php:50-56`.
3. Invoke only the owning control for `POST emar/audit/event/{id}/flag` (`emar.audit.event.flag`, action `flag`). Source category: **escalated/flagged**; controller `app/Http/Controllers/Emar/MedicationAuditEventController.php:104-156`; `note`.

## Source-applicable states and transitions

- **file/report delivered** is applicable only to `export` / `ROUTE-0332` at `app/Http/Controllers/Emar/MedicationAuditEventController.php:58`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `flag` / `ROUTE-0333` at `app/Http/Controllers/Emar/MedicationAuditEventController.php:104`; it is not runtime-observed.
- **information presented** is applicable only to `integrity` / `ROUTE-0334` at `app/Http/Controllers/Emar/MedicationAuditEventController.php:50`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0333` / `flag`: fields `note`; success app/Http/Controllers/Emar/MedicationAuditEventController.php:155 `return back()->with('success', 'Flagged for investigation — error '.($error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT)).' opened.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/MedicationAuditEventController.php:142 `$error = MedicationError::create([`; responses app/Http/Controllers/Emar/MedicationAuditEventController.php:75 `return response()->streamDownload(function () use ($model, $logs) {`; app/Http/Controllers/Emar/MedicationAuditEventController.php:155 `return back()->with('success', 'Flagged for investigation — error '.($error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT)).' opened.');`; app/Http/Controllers/Emar/MedicationAuditEventController.php:55 `return response()->json($this->integrity->forModel($this->resolveModel($id)));`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/audit/event/{id}/export` — `emar.audit.event.export` — `App\Http\Controllers\Emar\MedicationAuditEventController@export` — `app/Http/Controllers/Emar/MedicationAuditEventController.php:58` — middleware `web, auth, permission:medications.reports.export`
- `POST emar/audit/event/{id}/flag` — `emar.audit.event.flag` — `App\Http\Controllers\Emar\MedicationAuditEventController@flag` — `app/Http/Controllers/Emar/MedicationAuditEventController.php:104` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `GET|HEAD emar/audit/event/{id}/integrity` — `emar.audit.event.integrity` — `App\Http\Controllers\Emar\MedicationAuditEventController@integrity` — `app/Http/Controllers/Emar/MedicationAuditEventController.php:50` — middleware `web, auth, permission:medications.audit.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/MedicationAuditEventController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
