# CAP-INC-INCIDENT-CORRECTIVE-HANDOFF: Incident corrective-action handoff

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.viewAny|compliance.view|hazards.view`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-INCIDENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `incidents` (`incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.viewAny|compliance.view|hazards.view`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:incidents.viewAny|compliance.view|hazards.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD incidents` (`incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST incidents/{incident}/corrective-actions` (`incidents.corrective-actions.store`, action `raiseCorrectiveAction`). Source category: **escalated/flagged**; controller `app/Http/Controllers/IncidentController.php:1077-1115`; `title`.

## Source-applicable states and transitions

- **escalated/flagged** is applicable only to `raiseCorrectiveAction` / `ROUTE-1847` at `app/Http/Controllers/IncidentController.php:1077`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1847` / `raiseCorrectiveAction`: fields `title`; success app/Http/Controllers/IncidentController.php:1114 `return back()->with('success', 'Corrective action raised in the Health & Safety register.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/IncidentController.php:1097 `return back()->with('error', 'No Health & Safety event exists for this incident yet.');`; app/Http/Controllers/IncidentController.php:1100 `return back()->with('error', 'The Health & Safety event is closed; corrective actions can no longer be added.');`; app/Http/Controllers/IncidentController.php:1114 `return back()->with('success', 'Corrective action raised in the Health & Safety register.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST incidents/{incident}/corrective-actions` — `incidents.corrective-actions.store` — `App\Http\Controllers\IncidentController@raiseCorrectiveAction` — `app/Http/Controllers/IncidentController.php:1077` — middleware `web, auth, verified, permission:incidents.viewAny|compliance.view|hazards.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
