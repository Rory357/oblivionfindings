# CAP-RESP-RESPITE-STAY-CLINICAL-RECONCILIATION: Respite medication reconciliation and restraint recording

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-STAY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/stays/{stay}` (`respite.stays.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.stays.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/stays/{stay}` (`respite.stays.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST respite/stays/{stay}/medication-reconciliation` (`respite.stays.medication-reconciliation.store`, action `storeMedicationReconciliation`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:243-293`; `type`, `source`, `count_received`, `discrepancies`, `first_dose_due_at`, `override_reason`.
3. Invoke only the owning control for `POST respite/stays/{stay}/restraints` (`respite.stays.restraints.store`, action `recordRestraint`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:295-349`; `behaviour_support_plan_id`, `started_at`, `ended_at`, `duration_minutes`, `restraint_type`, `severity`, `trigger_description`, `de_escalation_attempted`, `restraint_description`, `staff_involved`, `person_response`, `post_incident_support`, `injury_occurred`, `injury_details`, `within_support_plan`, `deviation_reason`, `authorised_by`, `related_incident_id`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeMedicationReconciliation` / `ROUTE-2455` at `app/Http/Controllers/Respite/RespiteStayController.php:243`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordRestraint` / `ROUTE-2456` at `app/Http/Controllers/Respite/RespiteStayController.php:295`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2455` / `storeMedicationReconciliation`: fields `type`, `source`, `count_received`, `discrepancies`, `first_dose_due_at`, `override_reason`; success app/Http/Controllers/Respite/RespiteStayController.php:292 `return back()->with('success', 'Medication reconciliation recorded.');`.
- `ROUTE-2456` / `recordRestraint`: fields `behaviour_support_plan_id`, `started_at`, `ended_at`, `duration_minutes`, `restraint_type`, `severity`, `trigger_description`, `de_escalation_attempted`, `restraint_description`, `staff_involved`, `person_response`, `post_incident_support`, `injury_occurred`, `injury_details`, `within_support_plan`, `deviation_reason`, `authorised_by`, `related_incident_id`; success app/Http/Controllers/Respite/RespiteStayController.php:348 `return back()->with('success', 'Restraint event recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteStayController.php:260 `$reconciliation = RespiteMedicationReconciliation::updateOrCreate(`; app/Http/Controllers/Respite/RespiteStayController.php:277 `$stay->booking?->update([`; app/Http/Controllers/Respite/RespiteStayController.php:330 `$event = RestraintEvent::create([`; responses app/Http/Controllers/Respite/RespiteStayController.php:292 `return back()->with('success', 'Medication reconciliation recorded.');`; app/Http/Controllers/Respite/RespiteStayController.php:348 `return back()->with('success', 'Restraint event recorded.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteStayController.php:284 `event(new RespiteEvent('respite.stay.medication_reconciliation_recorded', [`; app/Http/Controllers/Respite/RespiteStayController.php:342 `event(new RespiteEvent('respite.stay.restraint_recorded', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/stays/{stay}/medication-reconciliation` — `respite.stays.medication-reconciliation.store` — `App\Http\Controllers\Respite\RespiteStayController@storeMedicationReconciliation` — `app/Http/Controllers/Respite/RespiteStayController.php:243` — middleware `web, auth, permission:respite.stays.manage`
- `POST respite/stays/{stay}/restraints` — `respite.stays.restraints.store` — `App\Http\Controllers\Respite\RespiteStayController@recordRestraint` — `app/Http/Controllers/Respite/RespiteStayController.php:295` — middleware `web, auth, permission:respite.stays.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteStayController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
