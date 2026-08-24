# CAP-FLEET-INCIDENT-VEHICLE-RESPONSE: Vehicle off-road back-in-service and incident status

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.incidents.manage|fleet.manage`
- Owning module: Fleet and vehicles
- Legacy family: `FLEET-INCIDENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `fleet-assets/incidents` (`fleet-assets.incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:fleet.incidents.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.incidents.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/incidents` (`fleet-assets.incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/back-in-service` (`fleet-assets.incidents.back-in-service`, action `backInService`). Source category: **mutation outcome source gap (backInService)**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:422-437`; `service_resumed_at`.
3. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/off-road` (`fleet-assets.incidents.off-road`, action `markOffRoad`). Source category: **mutation outcome source gap (markOffRoad)**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:401-420`; `off_road_from`.
4. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/status` (`fleet-assets.incidents.status`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:217-253`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (backInService)** is applicable only to `backInService` / `ROUTE-0756` at `app/Http/Controllers/FleetAssets/IncidentController.php:422`; it is not runtime-observed.
- **mutation outcome source gap (markOffRoad)** is applicable only to `markOffRoad` / `ROUTE-0760` at `app/Http/Controllers/FleetAssets/IncidentController.php:401`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-0762` at `app/Http/Controllers/FleetAssets/IncidentController.php:217`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0756` / `backInService`: fields `service_resumed_at`.
- `ROUTE-0760` / `markOffRoad`: fields `off_road_from`.
- `ROUTE-0762` / `updateStatus`: failure app/Http/Controllers/FleetAssets/IncidentController.php:226 `return back()->withErrors(['resolution_notes' => 'Resolution notes are required before closing.']);`.

## Failure and recovery paths

- `updateStatus`: app/Http/Controllers/FleetAssets/IncidentController.php:226 `return back()->withErrors(['resolution_notes' => 'Resolution notes are required before closing.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/IncidentController.php:428 `$incident->update([`; app/Http/Controllers/FleetAssets/IncidentController.php:408 `$incident->update([`; app/Http/Controllers/FleetAssets/IncidentController.php:238 `$incident->update($updates);`; responses app/Http/Controllers/FleetAssets/IncidentController.php:434 `return $this->inertiaOrJson($request, 'Vehicle returned to service.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:417 `return $this->inertiaOrJson($request, 'Vehicle marked off-road (VOR).', [`; app/Http/Controllers/FleetAssets/IncidentController.php:226 `return back()->withErrors(['resolution_notes' => 'Resolution notes are required before closing.']);`; app/Http/Controllers/FleetAssets/IncidentController.php:249 `return $this->inertiaOrJson($request, 'Status updated to '.$data['status'].'.', [`; audit calls app/Http/Controllers/FleetAssets/IncidentController.php:432 `AuditLogger::log('fleet.incident.back_in_service', $incident, []);`; app/Http/Controllers/FleetAssets/IncidentController.php:415 `AuditLogger::log('fleet.incident.off_road', $incident, []);`; app/Http/Controllers/FleetAssets/IncidentController.php:240 `AuditLogger::log('fleet.incident.status', $incident, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST fleet-assets/incidents/{incident}/back-in-service` — `fleet-assets.incidents.back-in-service` — `App\Http\Controllers\FleetAssets\IncidentController@backInService` — `app/Http/Controllers/FleetAssets/IncidentController.php:422` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/off-road` — `fleet-assets.incidents.off-road` — `App\Http\Controllers\FleetAssets\IncidentController@markOffRoad` — `app/Http/Controllers/FleetAssets/IncidentController.php:401` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/status` — `fleet-assets.incidents.status` — `App\Http\Controllers\FleetAssets\IncidentController@updateStatus` — `app/Http/Controllers/FleetAssets/IncidentController.php:217` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/IncidentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
