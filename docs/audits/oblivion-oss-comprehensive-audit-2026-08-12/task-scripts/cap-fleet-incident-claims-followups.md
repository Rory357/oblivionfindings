# CAP-FLEET-INCIDENT-CLAIMS-FOLLOWUPS: Fleet incident claims police reports and follow-ups

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
2. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/claim` (`fleet-assets.incidents.claim`, action `logClaim`). Source category: **mutation outcome source gap (logClaim)**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:379-399`; `insurer_name`.
3. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/followups` (`fleet-assets.incidents.followups.add`, action `addFollowup`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:255-275`; `notes`.
4. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/followups/{followup}/complete` (`fleet-assets.incidents.followups.complete`, action `completeFollowup`). Source category: **completed/closed/released**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:277-288`; no exact validation fields extracted.
5. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/police-report` (`fleet-assets.incidents.police-report`, action `logPoliceReport`). Source category: **mutation outcome source gap (logPoliceReport)**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:353-377`; `traffic_crash_report_reference`.

## Source-applicable states and transitions

- **mutation outcome source gap (logClaim)** is applicable only to `logClaim` / `ROUTE-0757` at `app/Http/Controllers/FleetAssets/IncidentController.php:379`; it is not runtime-observed.
- **created/recorded** is applicable only to `addFollowup` / `ROUTE-0758` at `app/Http/Controllers/FleetAssets/IncidentController.php:255`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeFollowup` / `ROUTE-0759` at `app/Http/Controllers/FleetAssets/IncidentController.php:277`; it is not runtime-observed.
- **mutation outcome source gap (logPoliceReport)** is applicable only to `logPoliceReport` / `ROUTE-0761` at `app/Http/Controllers/FleetAssets/IncidentController.php:353`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0757` / `logClaim`: fields `insurer_name`.
- `ROUTE-0758` / `addFollowup`: fields `notes`.
- `ROUTE-0761` / `logPoliceReport`: fields `traffic_crash_report_reference`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/IncidentController.php:389 `$incident->update(array_merge(`; app/Http/Controllers/FleetAssets/IncidentController.php:263 `$followup = $incident->followups()->create([`; app/Http/Controllers/FleetAssets/IncidentController.php:281 `$followup->update(['completed_at' => now()]);`; app/Http/Controllers/FleetAssets/IncidentController.php:362 `$incident->update([`; responses app/Http/Controllers/FleetAssets/IncidentController.php:396 `return $this->inertiaOrJson($request, 'Insurance claim logged.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:272 `return $this->inertiaOrJson($request, 'Follow-up added.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:285 `return $this->inertiaOrJson($request, 'Follow-up completed.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:374 `return $this->inertiaOrJson($request, 'Police report (TCR) logged.', [`; audit calls app/Http/Controllers/FleetAssets/IncidentController.php:394 `AuditLogger::log('fleet.incident.claim', $incident, ['ref' => $incident->insurance_reference]);`; app/Http/Controllers/FleetAssets/IncidentController.php:270 `AuditLogger::log('fleet.incident.followup.add', $followup, ['fleet_incident_id' => $incident->id]);`; app/Http/Controllers/FleetAssets/IncidentController.php:283 `AuditLogger::log('fleet.incident.followup.complete', $followup, ['fleet_incident_id' => $incident->id]);`; app/Http/Controllers/FleetAssets/IncidentController.php:370 `AuditLogger::log('fleet.incident.police_report', $incident, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST fleet-assets/incidents/{incident}/claim` — `fleet-assets.incidents.claim` — `App\Http\Controllers\FleetAssets\IncidentController@logClaim` — `app/Http/Controllers/FleetAssets/IncidentController.php:379` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/followups` — `fleet-assets.incidents.followups.add` — `App\Http\Controllers\FleetAssets\IncidentController@addFollowup` — `app/Http/Controllers/FleetAssets/IncidentController.php:255` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/followups/{followup}/complete` — `fleet-assets.incidents.followups.complete` — `App\Http\Controllers\FleetAssets\IncidentController@completeFollowup` — `app/Http/Controllers/FleetAssets/IncidentController.php:277` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/police-report` — `fleet-assets.incidents.police-report` — `App\Http\Controllers\FleetAssets\IncidentController@logPoliceReport` — `app/Http/Controllers/FleetAssets/IncidentController.php:353` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/IncidentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
