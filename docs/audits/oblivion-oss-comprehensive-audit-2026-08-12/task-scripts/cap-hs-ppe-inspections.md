# CAP-HS-PPE-INSPECTIONS: PPE inspection evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`
- Owning module: Health and safety
- Legacy family: `HS-PPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/ppe/inspections/{inspection}/attachments/{attachment}/download` (`health-safety.ppe.inspections.attachments.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/ppe/inspections/{inspection}/attachments/{attachment}/download` (`health-safety.ppe.inspections.attachments.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/ppe/inspections/{inspection}/attachments` (`health-safety.ppe.inspections.attachments.store`, action `uploadInspectionAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:870-875`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE health-safety/ppe/inspections/{inspection}/attachments/{attachment}` (`health-safety.ppe.inspections.attachments.destroy`, action `destroyInspectionAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/PpeController.php:882-885`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `uploadInspectionAttachment` / `ROUTE-1161` at `app/Http/Controllers/HealthSafety/PpeController.php:870`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyInspectionAttachment` / `ROUTE-1162` at `app/Http/Controllers/HealthSafety/PpeController.php:882`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadInspectionAttachment` / `ROUTE-1163` at `app/Http/Controllers/HealthSafety/PpeController.php:877`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1161` / `uploadInspectionAttachment`: success app/Http/Controllers/HealthSafety/PpeController.php:874 `return redirect()->back()->with('success', 'Document uploaded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/PpeController.php:872 `$inspection->attachments()->create($this->storeUploadedFile($request, 'ppe_inspection_attachments'));`; responses app/Http/Controllers/HealthSafety/PpeController.php:874 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/HealthSafety/PpeController.php:884 `return $this->destroyAttachment($attachment, (int) $attachment->ppe_inspection_id === (int) $inspection->id);`; app/Http/Controllers/HealthSafety/PpeController.php:879 `return $this->downloadAttachment($attachment, (int) $attachment->ppe_inspection_id === (int) $inspection->id);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/ppe/inspections/{inspection}/attachments` — `health-safety.ppe.inspections.attachments.store` — `App\Http\Controllers\HealthSafety\PpeController@uploadInspectionAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:870` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/ppe/inspections/{inspection}/attachments/{attachment}` — `health-safety.ppe.inspections.attachments.destroy` — `App\Http\Controllers\HealthSafety\PpeController@destroyInspectionAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:882` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/ppe/inspections/{inspection}/attachments/{attachment}/download` — `health-safety.ppe.inspections.attachments.download` — `App\Http\Controllers\HealthSafety\PpeController@downloadInspectionAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:877` — middleware `web, auth, permission:hazards.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/PpeController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
