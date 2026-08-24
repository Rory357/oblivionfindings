# CAP-INC-INCIDENT-REPORT-EVIDENCE: Incident report and attachment evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`, `permission:incidents.portal.manage`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`, `permission:incidents.portal.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`, `permission:incidents.portal.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD incidents` (`incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD incidents/{incident}` (`incidents.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/IncidentController.php:693-700`.
3. Use `GET|HEAD incidents/{incident}/attachments/{attachment}/download` (`incidents.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/IncidentController.php:1171-1183`.
4. Use `GET|HEAD incidents/create` (`incidents.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/IncidentController.php:492-517`.
5. Invoke only the owning control for `POST incidents` (`incidents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/IncidentController.php:519-686`; `client_id`.
6. Invoke only the owning control for `PUT incidents/{incident}` (`incidents.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/IncidentController.php:702-789`; `type`.
7. Invoke only the owning control for `POST incidents/{incident}/attachments` (`incidents.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/IncidentController.php:1117-1144`; `file`.
8. Invoke only the owning control for `DELETE incidents/{incident}/attachments/{attachment}` (`incidents.attachments.destroy`, action `removeAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/IncidentController.php:1146-1169`; no exact validation fields extracted.
9. Invoke only the owning control for `PATCH incidents/{incident}/attachments/{attachment}` (`incidents.attachments.update`, action `updateAttachment`). Source category: **updated/revised**; controller `app/Http/Controllers/IncidentController.php:791-807`; `portal_visible`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1838` at `app/Http/Controllers/IncidentController.php:33`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1839` at `app/Http/Controllers/IncidentController.php:519`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1840` at `app/Http/Controllers/IncidentController.php:693`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1841` at `app/Http/Controllers/IncidentController.php:702`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1842` at `app/Http/Controllers/IncidentController.php:1117`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeAttachment` / `ROUTE-1843` at `app/Http/Controllers/IncidentController.php:1146`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAttachment` / `ROUTE-1844` at `app/Http/Controllers/IncidentController.php:791`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1845` at `app/Http/Controllers/IncidentController.php:1171`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1854` at `app/Http/Controllers/IncidentController.php:492`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/incidents/index.tsx`, `resources/js/pages/incidents/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1839` / `store`: fields `client_id`; success app/Http/Controllers/IncidentController.php:675 `->with('success', 'Incident recorded.')`; app/Http/Controllers/IncidentController.php:682 `->with('success', 'Incident saved. Add any extra detail below.');`; app/Http/Controllers/IncidentController.php:685 `return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');`.
- `ROUTE-1841` / `update`: fields `type`; success app/Http/Controllers/IncidentController.php:788 `return back()->with('success', 'Incident updated.');`.
- `ROUTE-1842` / `uploadAttachment`: fields `file`; success app/Http/Controllers/IncidentController.php:1143 `return back()->with('success', 'Attachment uploaded.');`.
- `ROUTE-1843` / `removeAttachment`: success app/Http/Controllers/IncidentController.php:1168 `return back()->with('success', 'Attachment removed.');`.
- `ROUTE-1844` / `updateAttachment`: fields `portal_visible`; success app/Http/Controllers/IncidentController.php:806 `return back()->with('success', 'Attachment sharing updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/IncidentController.php:586 `$incident = ClientIncident::create([`; app/Http/Controllers/IncidentController.php:625 `SafeguardingConcern::create([`; app/Http/Controllers/IncidentController.php:663 `$incident->followups()->create([`; app/Http/Controllers/IncidentController.php:783 `$incident->update([`; app/Http/Controllers/IncidentController.php:1132 `ClientIncidentAttachment::create([`; app/Http/Controllers/IncidentController.php:1163 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/IncidentController.php:1166 `$attachment->delete();`; app/Http/Controllers/IncidentController.php:802 `$attachment->update([`; responses app/Http/Controllers/IncidentController.php:55 `return $query`; app/Http/Controllers/IncidentController.php:240 `return inertia('incidents/index', [`; app/Http/Controllers/IncidentController.php:674 `return back()`; app/Http/Controllers/IncidentController.php:680 `return redirect()`; app/Http/Controllers/IncidentController.php:685 `return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');`; app/Http/Controllers/IncidentController.php:697 `return inertia('incidents/show', [`; app/Http/Controllers/IncidentController.php:788 `return back()->with('success', 'Incident updated.');`; app/Http/Controllers/IncidentController.php:1143 `return back()->with('success', 'Attachment uploaded.');`; app/Http/Controllers/IncidentController.php:1168 `return back()->with('success', 'Attachment removed.');`; app/Http/Controllers/IncidentController.php:806 `return back()->with('success', 'Attachment sharing updated.');`; app/Http/Controllers/IncidentController.php:1177 `return $this->streamPrivateAttachment(`; app/Http/Controllers/IncidentController.php:498 `return redirect()->route('incidents.index', ['incident' => (int) $request->query('incident')]);`; app/Http/Controllers/IncidentController.php:516 `return redirect()->route('incidents.index', $params);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD incidents` — `incidents.index` — `App\Http\Controllers\IncidentController@index` — `app/Http/Controllers/IncidentController.php:33` — middleware `web, auth, verified, permission:incidents.viewAny|incidents.viewAssigned`
- `POST incidents` — `incidents.store` — `App\Http\Controllers\IncidentController@store` — `app/Http/Controllers/IncidentController.php:519` — middleware `web, auth, verified, permission:incidents.create`
- `GET|HEAD incidents/{incident}` — `incidents.show` — `App\Http\Controllers\IncidentController@show` — `app/Http/Controllers/IncidentController.php:693` — middleware `web, auth, verified, permission:incidents.viewAny|incidents.viewAssigned`
- `PUT incidents/{incident}` — `incidents.update` — `App\Http\Controllers\IncidentController@update` — `app/Http/Controllers/IncidentController.php:702` — middleware `web, auth, verified, permission:incidents.update`
- `POST incidents/{incident}/attachments` — `incidents.attachments.store` — `App\Http\Controllers\IncidentController@uploadAttachment` — `app/Http/Controllers/IncidentController.php:1117` — middleware `web, auth, verified, permission:incidents.update`
- `DELETE incidents/{incident}/attachments/{attachment}` — `incidents.attachments.destroy` — `App\Http\Controllers\IncidentController@removeAttachment` — `app/Http/Controllers/IncidentController.php:1146` — middleware `web, auth, verified, permission:incidents.update`
- `PATCH incidents/{incident}/attachments/{attachment}` — `incidents.attachments.update` — `App\Http\Controllers\IncidentController@updateAttachment` — `app/Http/Controllers/IncidentController.php:791` — middleware `web, auth, verified, permission:incidents.portal.manage`
- `GET|HEAD incidents/{incident}/attachments/{attachment}/download` — `incidents.attachments.download` — `App\Http\Controllers\IncidentController@downloadAttachment` — `app/Http/Controllers/IncidentController.php:1171` — middleware `web, auth, verified, permission:incidents.viewAny|incidents.viewAssigned`
- `GET|HEAD incidents/create` — `incidents.create` — `App\Http\Controllers\IncidentController@create` — `app/Http/Controllers/IncidentController.php:492` — middleware `web, auth, verified, permission:incidents.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentController.php`.
- Exact render/action page relationships: `resources/js/pages/incidents/index.tsx`, `resources/js/pages/incidents/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
