# CLI-CLIENT-INCIDENT: Client Incident

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-INCIDENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `clients/{client}/incidents` (`clients.incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`.
- Exact middleware atoms: `web`, `auth`, `permission:incidents.viewAny|incidents.viewAssigned`, `permission:incidents.create`, `permission:incidents.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD clients/{client}/incidents` (`clients.incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD clients/{client}/incidents/{incident}/attachments/{attachment}/download` (`clients.incidents.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientIncidentController.php:187-203`.
3. Use `GET|HEAD operations/clients/{client}/incidents` (`operations.clients.incidents.index`, action `index`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/ClientIncidentController.php:20-54`.
4. Use `GET|HEAD operations/clients/{client}/incidents/{incident}/attachments/{attachment}/download` (`operations.clients.incidents.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/ClientIncidentController.php:187-203`.
5. Invoke only the owning control for `POST clients/{client}/incidents` (`clients.incidents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientIncidentController.php:56-150`; `template_id`.
6. Invoke only the owning control for `POST clients/{client}/incidents/{incident}/attachments` (`clients.incidents.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientIncidentController.php:152-185`; `file`.
7. Invoke only the owning control for `POST operations/clients/{client}/incidents` (`operations.clients.incidents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientIncidentController.php:56-150`; `template_id`.
8. Invoke only the owning control for `POST operations/clients/{client}/incidents/{incident}/attachments` (`operations.clients.incidents.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientIncidentController.php:152-185`; `file`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0156` at `app/Http/Controllers/ClientIncidentController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0157` at `app/Http/Controllers/ClientIncidentController.php:56`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-0158` at `app/Http/Controllers/ClientIncidentController.php:152`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-0159` at `app/Http/Controllers/ClientIncidentController.php:187`; it is not runtime-observed.
- **information presented** is applicable only to `index` / `ROUTE-1995` at `app/Http/Controllers/ClientIncidentController.php:20`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1996` at `app/Http/Controllers/ClientIncidentController.php:56`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1997` at `app/Http/Controllers/ClientIncidentController.php:152`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1998` at `app/Http/Controllers/ClientIncidentController.php:187`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/incidents.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0157` / `store`: fields `template_id`; success app/Http/Controllers/ClientIncidentController.php:149 `return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');`.
- `ROUTE-0158` / `uploadAttachment`: fields `file`; success app/Http/Controllers/ClientIncidentController.php:184 `return back()->with('success', 'Attachment uploaded.');`.
- `ROUTE-1996` / `store`: fields `template_id`; success app/Http/Controllers/ClientIncidentController.php:149 `return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');`.
- `ROUTE-1997` / `uploadAttachment`: fields `file`; success app/Http/Controllers/ClientIncidentController.php:184 `return back()->with('success', 'Attachment uploaded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientIncidentController.php:88 `$incident = ClientIncident::create([`; app/Http/Controllers/ClientIncidentController.php:173 `ClientIncidentAttachment::create([`; responses app/Http/Controllers/ClientIncidentController.php:45 `return inertia('operations/clients/incidents', [`; app/Http/Controllers/ClientIncidentController.php:149 `return redirect()->route('incidents.show', $incident)->with('success', 'Incident draft created.');`; app/Http/Controllers/ClientIncidentController.php:184 `return back()->with('success', 'Attachment uploaded.');`; app/Http/Controllers/ClientIncidentController.php:197 `return $this->streamPrivateAttachment(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD clients/{client}/incidents` — `clients.incidents.index` — `App\Http\Controllers\ClientIncidentController@index` — `app/Http/Controllers/ClientIncidentController.php:20` — middleware `web, auth, permission:incidents.viewAny|incidents.viewAssigned`
- `POST clients/{client}/incidents` — `clients.incidents.store` — `App\Http\Controllers\ClientIncidentController@store` — `app/Http/Controllers/ClientIncidentController.php:56` — middleware `web, auth, permission:incidents.create`
- `POST clients/{client}/incidents/{incident}/attachments` — `clients.incidents.attachments.store` — `App\Http\Controllers\ClientIncidentController@uploadAttachment` — `app/Http/Controllers/ClientIncidentController.php:152` — middleware `web, auth, permission:incidents.update`
- `GET|HEAD clients/{client}/incidents/{incident}/attachments/{attachment}/download` — `clients.incidents.attachments.download` — `App\Http\Controllers\ClientIncidentController@downloadAttachment` — `app/Http/Controllers/ClientIncidentController.php:187` — middleware `web, auth, permission:incidents.viewAny|incidents.viewAssigned`
- `GET|HEAD operations/clients/{client}/incidents` — `operations.clients.incidents.index` — `App\Http\Controllers\ClientIncidentController@index` — `app/Http/Controllers/ClientIncidentController.php:20` — middleware `web, auth, permission:incidents.viewAny|incidents.viewAssigned`
- `POST operations/clients/{client}/incidents` — `operations.clients.incidents.store` — `App\Http\Controllers\ClientIncidentController@store` — `app/Http/Controllers/ClientIncidentController.php:56` — middleware `web, auth, permission:incidents.create`
- `POST operations/clients/{client}/incidents/{incident}/attachments` — `operations.clients.incidents.attachments.store` — `App\Http\Controllers\ClientIncidentController@uploadAttachment` — `app/Http/Controllers/ClientIncidentController.php:152` — middleware `web, auth, permission:incidents.update`
- `GET|HEAD operations/clients/{client}/incidents/{incident}/attachments/{attachment}/download` — `operations.clients.incidents.attachments.download` — `App\Http\Controllers\ClientIncidentController@downloadAttachment` — `app/Http/Controllers/ClientIncidentController.php:187` — middleware `web, auth, permission:incidents.viewAny|incidents.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientIncidentController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/incidents.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
