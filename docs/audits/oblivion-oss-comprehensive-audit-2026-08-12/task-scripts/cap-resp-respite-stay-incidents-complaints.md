# CAP-RESP-RESPITE-STAY-INCIDENTS-COMPLAINTS: Respite stay incident and complaint recording

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
2. Invoke only the owning control for `POST respite/stays/{stay}/complaints` (`respite.stays.complaints.store`, action `recordComplaint`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:437-468`; `source`, `received_at`, `nature`, `details`, `acknowledged_at`, `resolution`, `escalated_to_hdc`.
3. Invoke only the owning control for `POST respite/stays/{stay}/incidents` (`respite.stays.incidents.store`, action `recordIncident`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:351-435`; `type`, `severity`, `title`, `description`, `occurred_at`, `immediate_action_taken`, `witnesses`, `is_notifiable`, `notification_authority`, `incident_type`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `recordComplaint` / `ROUTE-2448` at `app/Http/Controllers/Respite/RespiteStayController.php:437`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordIncident` / `ROUTE-2454` at `app/Http/Controllers/Respite/RespiteStayController.php:351`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2448` / `recordComplaint`: fields `source`, `received_at`, `nature`, `details`, `acknowledged_at`, `resolution`, `escalated_to_hdc`; success app/Http/Controllers/Respite/RespiteStayController.php:467 `return back()->with('success', 'Complaint recorded.');`.
- `ROUTE-2454` / `recordIncident`: fields `type`, `severity`, `title`, `description`, `occurred_at`, `immediate_action_taken`, `witnesses`, `is_notifiable`, `notification_authority`, `incident_type`; success app/Http/Controllers/Respite/RespiteStayController.php:434 `return back()->with('success', 'Incident recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteStayController.php:453 `$complaint = RespiteComplaint::create([`; app/Http/Controllers/Respite/RespiteStayController.php:369 `$incident = ClientIncident::create([`; app/Http/Controllers/Respite/RespiteStayController.php:391 `NotifiableIncident::create([`; app/Http/Controllers/Respite/RespiteStayController.php:410 `DataBreachLog::create([`; responses app/Http/Controllers/Respite/RespiteStayController.php:467 `return back()->with('success', 'Complaint recorded.');`; app/Http/Controllers/Respite/RespiteStayController.php:434 `return back()->with('success', 'Incident recorded.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteStayController.php:461 `event(new RespiteEvent('respite.stay.complaint_recorded', [`; app/Http/Controllers/Respite/RespiteStayController.php:428 `event(new RespiteEvent('respite.stay.incident_recorded', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/stays/{stay}/complaints` — `respite.stays.complaints.store` — `App\Http\Controllers\Respite\RespiteStayController@recordComplaint` — `app/Http/Controllers/Respite/RespiteStayController.php:437` — middleware `web, auth, permission:respite.stays.manage`
- `POST respite/stays/{stay}/incidents` — `respite.stays.incidents.store` — `App\Http\Controllers\Respite\RespiteStayController@recordIncident` — `app/Http/Controllers/Respite/RespiteStayController.php:351` — middleware `web, auth, permission:respite.stays.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteStayController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
