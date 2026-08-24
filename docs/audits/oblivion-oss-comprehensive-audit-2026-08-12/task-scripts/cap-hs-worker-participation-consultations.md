# CAP-HS-WORKER-PARTICIPATION-CONSULTATIONS: Worker consultations evidence and status

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`
- Owning module: Health and safety
- Legacy family: `HS-WORKER-PARTICIPATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/worker-participation/consultations/{consultation}/documents/{type}` (`health-safety.worker-participation.consultations.documents.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/worker-participation/consultations/{consultation}/documents/{type}` (`health-safety.worker-participation.consultations.documents.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/worker-participation/consultations` (`health-safety.worker-participation.consultations.store`, action `storeConsultation`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:587-609`; FormRequest `app/Http/Requests/HealthSafety/StoreConsultationRequest.php:21`; `title`, `consultation_type`, `description`, `site_id`, `consultation_date`, `workers_consulted`, `document`.
3. Invoke only the owning control for `PUT health-safety/worker-participation/consultations/{consultation}` (`health-safety.worker-participation.consultations.update`, action `updateConsultation`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:611-629`; `title`.
4. Invoke only the owning control for `POST health-safety/worker-participation/consultations/{consultation}/documents` (`health-safety.worker-participation.consultations.documents.upload`, action `uploadConsultationDocument`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:657-672`; `document`.
5. Invoke only the owning control for `PUT health-safety/worker-participation/consultations/{consultation}/status` (`health-safety.worker-participation.consultations.status`, action `updateConsultationStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:631-655`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeConsultation` / `ROUTE-1240` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:587`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateConsultation` / `ROUTE-1241` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:611`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadConsultationDocument` / `ROUTE-1242` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:657`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadConsultationDocument` / `ROUTE-1243` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:674`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateConsultationStatus` / `ROUTE-1244` at `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:631`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1240` / `storeConsultation`: FormRequest `app/Http/Requests/HealthSafety/StoreConsultationRequest.php:21`; fields `title`, `consultation_type`, `description`, `site_id`, `consultation_date`, `workers_consulted`, `document`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:608 `return back()->with('success', 'Consultation created successfully.');`.
- `ROUTE-1241` / `updateConsultation`: fields `title`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:628 `return back()->with('success', 'Consultation updated successfully.');`.
- `ROUTE-1242` / `uploadConsultationDocument`: fields `document`; success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:671 `return back()->with('success', 'Document uploaded successfully.');`.
- `ROUTE-1244` / `updateConsultationStatus`: success app/Http/Controllers/HealthSafety/WorkerParticipationController.php:654 `return back()->with('success', 'Consultation status updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/WorkerParticipationController.php:591 `$consultation = HsConsultation::create(array_merge($data, [`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:602 `$consultation->update([`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:626 `$consultation->update($validated);`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:667 `$consultation->update($request->input('type') === 'document'`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:652 `$consultation->update($validated);`; responses app/Http/Controllers/HealthSafety/WorkerParticipationController.php:608 `return back()->with('success', 'Consultation created successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:628 `return back()->with('success', 'Consultation updated successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:671 `return back()->with('success', 'Document uploaded successfully.');`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:684 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/WorkerParticipationController.php:654 `return back()->with('success', 'Consultation status updated successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/worker-participation/consultations` — `health-safety.worker-participation.consultations.store` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@storeConsultation` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:587` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/worker-participation/consultations/{consultation}` — `health-safety.worker-participation.consultations.update` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@updateConsultation` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:611` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/worker-participation/consultations/{consultation}/documents` — `health-safety.worker-participation.consultations.documents.upload` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@uploadConsultationDocument` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:657` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/worker-participation/consultations/{consultation}/documents/{type}` — `health-safety.worker-participation.consultations.documents.download` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@downloadConsultationDocument` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:674` — middleware `web, auth, permission:hazards.view`
- `PUT health-safety/worker-participation/consultations/{consultation}/status` — `health-safety.worker-participation.consultations.status` — `App\Http\Controllers\HealthSafety\WorkerParticipationController@updateConsultationStatus` — `app/Http/Controllers/HealthSafety/WorkerParticipationController.php:631` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/WorkerParticipationController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
