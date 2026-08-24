# CAP-FLEET-INCIDENT-RECORD-EVIDENCE: Fleet incident record and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.incidents.manage|fleet.manage`
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

- Actor satisfying exact route middleware `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.incidents.manage|fleet.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:fleet.viewAny|assets.viewAny`, `permission:fleet.incidents.manage|fleet.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD fleet-assets/incidents` (`fleet-assets.incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD fleet-assets/incidents/{incident}` (`fleet-assets.incidents.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/IncidentController.php:188-198`.
3. Use `GET|HEAD fleet-assets/incidents/{incident}/attachments/{attachment}/download` (`fleet-assets.incidents.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/FleetAssets/IncidentController.php:322-333`.
4. Use `GET|HEAD fleet-assets/incidents/create` (`fleet-assets.incidents.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/FleetAssets/IncidentController.php:127-138`.
5. Invoke only the owning control for `POST fleet-assets/incidents` (`fleet-assets.incidents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:140-186`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT fleet-assets/incidents/{incident}` (`fleet-assets.incidents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:200-214`; `fields`.
7. Invoke only the owning control for `POST fleet-assets/incidents/{incident}/attachments` (`fleet-assets.incidents.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:290-320`; `file`.
8. Invoke only the owning control for `DELETE fleet-assets/incidents/{incident}/attachments/{attachment}` (`fleet-assets.incidents.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/FleetAssets/IncidentController.php:335-350`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0749` at `app/Http/Controllers/FleetAssets/IncidentController.php:44`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0750` at `app/Http/Controllers/FleetAssets/IncidentController.php:140`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0751` at `app/Http/Controllers/FleetAssets/IncidentController.php:188`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0752` at `app/Http/Controllers/FleetAssets/IncidentController.php:200`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-0753` at `app/Http/Controllers/FleetAssets/IncidentController.php:290`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-0754` at `app/Http/Controllers/FleetAssets/IncidentController.php:335`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-0755` at `app/Http/Controllers/FleetAssets/IncidentController.php:322`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0763` at `app/Http/Controllers/FleetAssets/IncidentController.php:127`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/fleet-assets/incidents/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0750` / `store`: success app/Http/Controllers/FleetAssets/IncidentController.php:183 `->with('success', 'Incident '.$incident->reference().' reported.')`.
- `ROUTE-0752` / `update`: fields `fields`.
- `ROUTE-0753` / `uploadAttachment`: fields `file`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FleetAssets/IncidentController.php:157 `$incident = FleetIncident::create($attributes);`; app/Http/Controllers/FleetAssets/IncidentController.php:207 `$incident->update($attributes);`; app/Http/Controllers/FleetAssets/IncidentController.php:303 `$incident->attachments()->create([`; app/Http/Controllers/FleetAssets/IncidentController.php:341 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/FleetAssets/IncidentController.php:343 `$attachment->delete();`; responses app/Http/Controllers/FleetAssets/IncidentController.php:49 `return Inertia::render('fleet-assets/incidents/index', [`; app/Http/Controllers/FleetAssets/IncidentController.php:68 `return $this->exportCsv($base);`; app/Http/Controllers/FleetAssets/IncidentController.php:76 `return Inertia::render('fleet-assets/incidents/index', [`; app/Http/Controllers/FleetAssets/IncidentController.php:86 `return null;`; app/Http/Controllers/FleetAssets/IncidentController.php:90 `return $found ? $this->buildDetailPayload($found) : null;`; app/Http/Controllers/FleetAssets/IncidentController.php:176 `return response()->json([`; app/Http/Controllers/FleetAssets/IncidentController.php:182 `return back()`; app/Http/Controllers/FleetAssets/IncidentController.php:194 `return response()->json(['incident' => $this->buildDetailPayload($incident)]);`; app/Http/Controllers/FleetAssets/IncidentController.php:197 `return redirect()->route('fleet-assets.incidents.index', ['incident' => $incident->id]);`; app/Http/Controllers/FleetAssets/IncidentController.php:211 `return $this->inertiaOrJson($request, 'Incident updated.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:317 `return $this->inertiaOrJson($request, 'Evidence uploaded.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:347 `return $this->inertiaOrJson($request, 'Evidence removed.', [`; app/Http/Controllers/FleetAssets/IncidentController.php:327 `return $this->streamPrivateAttachment(`; app/Http/Controllers/FleetAssets/IncidentController.php:134 `return redirect()->route('fleet-assets.incidents.index', array_filter([`; audit calls app/Http/Controllers/FleetAssets/IncidentController.php:159 `AuditLogger::log('fleet.incident.create', $incident, [`; app/Http/Controllers/FleetAssets/IncidentController.php:209 `AuditLogger::log('fleet.incident.update', $incident, ['fields' => array_keys($attributes)]);`; app/Http/Controllers/FleetAssets/IncidentController.php:315 `AuditLogger::log('fleet.incident.attachment.add', $incident, ['original_name' => $file->getClientOriginalName()]);`; app/Http/Controllers/FleetAssets/IncidentController.php:345 `AuditLogger::log('fleet.incident.attachment.remove', $incident, ['attachment_id' => $attachment->id]);`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/FleetAssets/IncidentController.php:171 `})->get()->each->notify(new FleetIncidentReportedNotification($incident));`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD fleet-assets/incidents` — `fleet-assets.incidents.index` — `App\Http\Controllers\FleetAssets\IncidentController@index` — `app/Http/Controllers/FleetAssets/IncidentController.php:44` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `POST fleet-assets/incidents` — `fleet-assets.incidents.store` — `App\Http\Controllers\FleetAssets\IncidentController@store` — `app/Http/Controllers/FleetAssets/IncidentController.php:140` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/incidents/{incident}` — `fleet-assets.incidents.show` — `App\Http\Controllers\FleetAssets\IncidentController@show` — `app/Http/Controllers/FleetAssets/IncidentController.php:188` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `PUT fleet-assets/incidents/{incident}` — `fleet-assets.incidents.update` — `App\Http\Controllers\FleetAssets\IncidentController@update` — `app/Http/Controllers/FleetAssets/IncidentController.php:200` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `POST fleet-assets/incidents/{incident}/attachments` — `fleet-assets.incidents.attachments.store` — `App\Http\Controllers\FleetAssets\IncidentController@uploadAttachment` — `app/Http/Controllers/FleetAssets/IncidentController.php:290` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `DELETE fleet-assets/incidents/{incident}/attachments/{attachment}` — `fleet-assets.incidents.attachments.destroy` — `App\Http\Controllers\FleetAssets\IncidentController@destroyAttachment` — `app/Http/Controllers/FleetAssets/IncidentController.php:335` — middleware `web, auth, permission:fleet.incidents.manage|fleet.manage`
- `GET|HEAD fleet-assets/incidents/{incident}/attachments/{attachment}/download` — `fleet-assets.incidents.attachments.download` — `App\Http\Controllers\FleetAssets\IncidentController@downloadAttachment` — `app/Http/Controllers/FleetAssets/IncidentController.php:322` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`
- `GET|HEAD fleet-assets/incidents/create` — `fleet-assets.incidents.create` — `App\Http\Controllers\FleetAssets\IncidentController@create` — `app/Http/Controllers/FleetAssets/IncidentController.php:127` — middleware `web, auth, permission:fleet.viewAny|assets.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FleetAssets/IncidentController.php`.
- Exact render/action page relationships: `resources/js/pages/fleet-assets/incidents/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
