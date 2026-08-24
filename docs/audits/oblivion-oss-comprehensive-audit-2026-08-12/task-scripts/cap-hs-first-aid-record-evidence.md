# CAP-HS-FIRST-AID-RECORD-EVIDENCE: First-aid record attachments incident link and export

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-FIRST-AID`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/first-aid` (`health-safety.first-aid.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage|hazards.create`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/first-aid` (`health-safety.first-aid.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/first-aid/{record}` (`health-safety.first-aid.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/FirstAidController.php:157-166`.
3. Use `GET|HEAD health-safety/first-aid/{record}/attachments/{attachment}/download` (`health-safety.first-aid.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/FirstAidController.php:333-345`.
4. Use `GET|HEAD health-safety/first-aid/export` (`health-safety.first-aid.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/FirstAidController.php:87-123`.
5. Invoke only the owning control for `POST health-safety/first-aid` (`health-safety.first-aid.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:137-154`; FormRequest `app/Http/Requests/HealthSafety/StoreFirstAidRecordRequest.php:25`; `site_id`, `treated_person_id`, `client_id`, `treated_person_name`, `treated_person_type`, `treatment_date`, `injury_illness_type`, `injury_illness_description`, `body_part`, `treatment_given`, `treatment_outcome`, `ambulance_called`, `first_aider_id`, `first_aider_notes`, `incident_reported`, `related_incident_id`.
6. Invoke only the owning control for `DELETE health-safety/first-aid/{record}` (`health-safety.first-aid.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:183-195`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT health-safety/first-aid/{record}` (`health-safety.first-aid.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:168-181`; FormRequest `app/Http/Requests/HealthSafety/UpdateFirstAidRecordRequest.php:22`; no exact validation fields extracted.
8. Invoke only the owning control for `POST health-safety/first-aid/{record}/attachments` (`health-safety.first-aid.attachments.upload`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:301-331`; `file`.
9. Invoke only the owning control for `DELETE health-safety/first-aid/{record}/attachments/{attachment}` (`health-safety.first-aid.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:347-361`; no exact validation fields extracted.
10. Invoke only the owning control for `POST health-safety/first-aid/{record}/link-incident` (`health-safety.first-aid.link-incident`, action `linkIncident`). Source category: **mutation outcome source gap (linkIncident)**; controller `app/Http/Controllers/HealthSafety/FirstAidController.php:206-260`; `related_incident_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1115` at `app/Http/Controllers/HealthSafety/FirstAidController.php:50`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1116` at `app/Http/Controllers/HealthSafety/FirstAidController.php:137`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-1117` at `app/Http/Controllers/HealthSafety/FirstAidController.php:183`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1118` at `app/Http/Controllers/HealthSafety/FirstAidController.php:157`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1119` at `app/Http/Controllers/HealthSafety/FirstAidController.php:168`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1120` at `app/Http/Controllers/HealthSafety/FirstAidController.php:301`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1121` at `app/Http/Controllers/HealthSafety/FirstAidController.php:347`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1122` at `app/Http/Controllers/HealthSafety/FirstAidController.php:333`; it is not runtime-observed.
- **mutation outcome source gap (linkIncident)** is applicable only to `linkIncident` / `ROUTE-1125` at `app/Http/Controllers/HealthSafety/FirstAidController.php:206`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1126` at `app/Http/Controllers/HealthSafety/FirstAidController.php:87`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/first-aid/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1116` / `store`: FormRequest `app/Http/Requests/HealthSafety/StoreFirstAidRecordRequest.php:25`; fields `site_id`, `treated_person_id`, `client_id`, `treated_person_name`, `treated_person_type`, `treatment_date`, `injury_illness_type`, `injury_illness_description`, `body_part`, `treatment_given`, `treatment_outcome`, `ambulance_called`, `first_aider_id`, `first_aider_notes`, `incident_reported`, `related_incident_id`; success app/Http/Controllers/HealthSafety/FirstAidController.php:152 `->with('success', 'First aid record created.')`.
- `ROUTE-1117` / `destroy`: success app/Http/Controllers/HealthSafety/FirstAidController.php:194 `return back()->with('success', 'First aid record archived.');`.
- `ROUTE-1119` / `update`: FormRequest `app/Http/Requests/HealthSafety/UpdateFirstAidRecordRequest.php:22`, `FormRequest` unresolved.
- `ROUTE-1120` / `uploadAttachment`: fields `file`.
- `ROUTE-1125` / `linkIncident`: fields `related_incident_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/FirstAidController.php:142 `$record = FirstAidRecord::create($data);`; app/Http/Controllers/HealthSafety/FirstAidController.php:187 `$record->delete();`; app/Http/Controllers/HealthSafety/FirstAidController.php:178 `$record->update($data);`; app/Http/Controllers/HealthSafety/FirstAidController.php:316 `$record->attachments()->create([`; app/Http/Controllers/HealthSafety/FirstAidController.php:354 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/FirstAidController.php:358 `$attachment->delete();`; app/Http/Controllers/HealthSafety/FirstAidController.php:219 `$record->update([`; app/Http/Controllers/HealthSafety/FirstAidController.php:234 `$incident = ClientIncident::create([`; app/Http/Controllers/HealthSafety/FirstAidController.php:250 `$record->update([`; responses app/Http/Controllers/HealthSafety/FirstAidController.php:55 `return Inertia::render('health-safety/first-aid/index', $this->emptyPayload($request));`; app/Http/Controllers/HealthSafety/FirstAidController.php:63 `return Inertia::render('health-safety/first-aid/index', [`; app/Http/Controllers/HealthSafety/FirstAidController.php:145 `return response()->json([`; app/Http/Controllers/HealthSafety/FirstAidController.php:151 `return back()`; app/Http/Controllers/HealthSafety/FirstAidController.php:190 `return response()->json(['status' => 'First aid record archived.']);`; app/Http/Controllers/HealthSafety/FirstAidController.php:194 `return back()->with('success', 'First aid record archived.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:162 `return response()->json(['record' => $this->buildDetailPayload($record->id, $request)]);`; app/Http/Controllers/HealthSafety/FirstAidController.php:165 `return redirect()->route('health-safety.first-aid.index', ['record' => $record->id]);`; app/Http/Controllers/HealthSafety/FirstAidController.php:180 `return $this->inertiaOrJson($request, 'First aid record updated.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:330 `return $this->inertiaOrJson($request, 'Evidence uploaded.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:360 `return $this->inertiaOrJson($request, 'Evidence removed.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:339 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/FirstAidController.php:215 `return $this->inertiaOrJson($request, 'Already linked to an incident.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:224 `return $this->inertiaOrJson($request, 'Treatment linked to incident.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:231 `return back()->with('error', 'Only client treatments can create a new incident. Link an existing incident instead.');`; app/Http/Controllers/HealthSafety/FirstAidController.php:246 `// escalation paths return the same verdict for the same treatment.`; app/Http/Controllers/HealthSafety/FirstAidController.php:257 `return $this->inertiaOrJson($request, 'Incident created from treatment.', [`; app/Http/Controllers/HealthSafety/FirstAidController.php:100 `return response()->streamDownload(function () use ($query) {`; audit calls app/Http/Controllers/HealthSafety/FirstAidController.php:328 `AuditLogger::log('firstaidrecord.attachment.add', $record, ['original_name' => $file->getClientOriginalName()]);`; app/Http/Controllers/HealthSafety/FirstAidController.php:357 `AuditLogger::log('firstaidrecord.attachment.remove', $record, ['attachment_id' => $attachment->id]);`; app/Http/Controllers/HealthSafety/FirstAidController.php:255 `AuditLogger::log('firstaidrecord.escalated', $record, ['incident_id' => $incident->id]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/first-aid` — `health-safety.first-aid.index` — `App\Http\Controllers\HealthSafety\FirstAidController@index` — `app/Http/Controllers/HealthSafety/FirstAidController.php:50` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/first-aid` — `health-safety.first-aid.store` — `App\Http\Controllers\HealthSafety\FirstAidController@store` — `app/Http/Controllers/HealthSafety/FirstAidController.php:137` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `DELETE health-safety/first-aid/{record}` — `health-safety.first-aid.destroy` — `App\Http\Controllers\HealthSafety\FirstAidController@destroy` — `app/Http/Controllers/HealthSafety/FirstAidController.php:183` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/first-aid/{record}` — `health-safety.first-aid.show` — `App\Http\Controllers\HealthSafety\FirstAidController@show` — `app/Http/Controllers/HealthSafety/FirstAidController.php:157` — middleware `web, auth, permission:hazards.view`
- `PUT health-safety/first-aid/{record}` — `health-safety.first-aid.update` — `App\Http\Controllers\HealthSafety\FirstAidController@update` — `app/Http/Controllers/HealthSafety/FirstAidController.php:168` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `POST health-safety/first-aid/{record}/attachments` — `health-safety.first-aid.attachments.upload` — `App\Http\Controllers\HealthSafety\FirstAidController@uploadAttachment` — `app/Http/Controllers/HealthSafety/FirstAidController.php:301` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `DELETE health-safety/first-aid/{record}/attachments/{attachment}` — `health-safety.first-aid.attachments.destroy` — `App\Http\Controllers\HealthSafety\FirstAidController@destroyAttachment` — `app/Http/Controllers/HealthSafety/FirstAidController.php:347` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/first-aid/{record}/attachments/{attachment}/download` — `health-safety.first-aid.attachments.download` — `App\Http\Controllers\HealthSafety\FirstAidController@downloadAttachment` — `app/Http/Controllers/HealthSafety/FirstAidController.php:333` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/first-aid/{record}/link-incident` — `health-safety.first-aid.link-incident` — `App\Http\Controllers\HealthSafety\FirstAidController@linkIncident` — `app/Http/Controllers/HealthSafety/FirstAidController.php:206` — middleware `web, auth, permission:hazards.manage|hazards.create`
- `GET|HEAD health-safety/first-aid/export` — `health-safety.first-aid.export` — `App\Http\Controllers\HealthSafety\FirstAidController@export` — `app/Http/Controllers/HealthSafety/FirstAidController.php:87` — middleware `web, auth, permission:hazards.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/FirstAidController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/first-aid/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
