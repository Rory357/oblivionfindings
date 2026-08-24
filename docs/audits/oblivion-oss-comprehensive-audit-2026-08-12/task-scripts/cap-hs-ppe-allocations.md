# CAP-HS-PPE-ALLOCATIONS: PPE allocation acknowledgement and return

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`
- Owning module: Health and safety
- Legacy family: `HS-PPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/ppe/allocations/{allocation}/attachments/{attachment}/download` (`health-safety.ppe.allocations.attachments.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.manage`, `permission:hazards.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/ppe/allocations/{allocation}/attachments/{attachment}/download` (`health-safety.ppe.allocations.attachments.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST health-safety/ppe/allocations/{allocation}/acknowledge` (`health-safety.ppe.allocations.acknowledge`, action `acknowledge`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/PpeController.php:706-716`; no exact validation fields extracted.
3. Invoke only the owning control for `POST health-safety/ppe/allocations/{allocation}/acknowledge-own` (`health-safety.ppe.allocations.acknowledge-own`, action `acknowledgeOwn`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/HealthSafety/PpeController.php:724-740`; no exact validation fields extracted.
4. Invoke only the owning control for `POST health-safety/ppe/allocations/{allocation}/attachments` (`health-safety.ppe.allocations.attachments.store`, action `uploadAllocationAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:853-858`; no exact validation fields extracted.
5. Invoke only the owning control for `DELETE health-safety/ppe/allocations/{allocation}/attachments/{attachment}` (`health-safety.ppe.allocations.attachments.destroy`, action `destroyAllocationAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/PpeController.php:865-868`; no exact validation fields extracted.
6. Invoke only the owning control for `POST health-safety/ppe/allocations/{allocation}/return` (`health-safety.ppe.allocations.return`, action `returnPpe`). Source category: **rejected/returned**; controller `app/Http/Controllers/HealthSafety/PpeController.php:742-778`; `notes`.

## Source-applicable states and transitions

- **approved/acknowledged/verified** is applicable only to `acknowledge` / `ROUTE-1154` at `app/Http/Controllers/HealthSafety/PpeController.php:706`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeOwn` / `ROUTE-1155` at `app/Http/Controllers/HealthSafety/PpeController.php:724`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAllocationAttachment` / `ROUTE-1156` at `app/Http/Controllers/HealthSafety/PpeController.php:853`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAllocationAttachment` / `ROUTE-1157` at `app/Http/Controllers/HealthSafety/PpeController.php:865`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAllocationAttachment` / `ROUTE-1158` at `app/Http/Controllers/HealthSafety/PpeController.php:860`; it is not runtime-observed.
- **rejected/returned** is applicable only to `returnPpe` / `ROUTE-1159` at `app/Http/Controllers/HealthSafety/PpeController.php:742`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1154` / `acknowledge`: success app/Http/Controllers/HealthSafety/PpeController.php:715 `return redirect()->back()->with('success', 'Allocation acknowledged.');`.
- `ROUTE-1155` / `acknowledgeOwn`: success app/Http/Controllers/HealthSafety/PpeController.php:739 `return redirect()->back()->with('success', 'PPE acknowledged.');`.
- `ROUTE-1156` / `uploadAllocationAttachment`: success app/Http/Controllers/HealthSafety/PpeController.php:857 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-1159` / `returnPpe`: fields `notes`; success app/Http/Controllers/HealthSafety/PpeController.php:777 `return redirect()->back()->with('success', 'PPE returned.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/PpeController.php:708 `$allocation->update([`; app/Http/Controllers/HealthSafety/PpeController.php:732 `$allocation->update([`; app/Http/Controllers/HealthSafety/PpeController.php:855 `$allocation->attachments()->create($this->storeUploadedFile($request, 'ppe_allocation_attachments'));`; app/Http/Controllers/HealthSafety/PpeController.php:749 `$allocation->update([`; app/Http/Controllers/HealthSafety/PpeController.php:775 `$item->update($inventoryUpdate);`; responses app/Http/Controllers/HealthSafety/PpeController.php:715 `return redirect()->back()->with('success', 'Allocation acknowledged.');`; app/Http/Controllers/HealthSafety/PpeController.php:729 `return redirect()->back()->with('error', 'This PPE has already been returned.');`; app/Http/Controllers/HealthSafety/PpeController.php:739 `return redirect()->back()->with('success', 'PPE acknowledged.');`; app/Http/Controllers/HealthSafety/PpeController.php:857 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/HealthSafety/PpeController.php:867 `return $this->destroyAttachment($attachment, (int) $attachment->ppe_allocation_id === (int) $allocation->id);`; app/Http/Controllers/HealthSafety/PpeController.php:862 `return $this->downloadAttachment($attachment, (int) $attachment->ppe_allocation_id === (int) $allocation->id);`; app/Http/Controllers/HealthSafety/PpeController.php:766 `$inventoryUpdate['condemned_reason'] = 'Condemned on return: '.($validated['notes'] ?? 'failed return check');`; app/Http/Controllers/HealthSafety/PpeController.php:777 `return redirect()->back()->with('success', 'PPE returned.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST health-safety/ppe/allocations/{allocation}/acknowledge` — `health-safety.ppe.allocations.acknowledge` — `App\Http\Controllers\HealthSafety\PpeController@acknowledge` — `app/Http/Controllers/HealthSafety/PpeController.php:706` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/ppe/allocations/{allocation}/acknowledge-own` — `health-safety.ppe.allocations.acknowledge-own` — `App\Http\Controllers\HealthSafety\PpeController@acknowledgeOwn` — `app/Http/Controllers/HealthSafety/PpeController.php:724` — middleware `web, auth`
- `POST health-safety/ppe/allocations/{allocation}/attachments` — `health-safety.ppe.allocations.attachments.store` — `App\Http\Controllers\HealthSafety\PpeController@uploadAllocationAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:853` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/ppe/allocations/{allocation}/attachments/{attachment}` — `health-safety.ppe.allocations.attachments.destroy` — `App\Http\Controllers\HealthSafety\PpeController@destroyAllocationAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:865` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/ppe/allocations/{allocation}/attachments/{attachment}/download` — `health-safety.ppe.allocations.attachments.download` — `App\Http\Controllers\HealthSafety\PpeController@downloadAllocationAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:860` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/ppe/allocations/{allocation}/return` — `health-safety.ppe.allocations.return` — `App\Http\Controllers\HealthSafety\PpeController@returnPpe` — `app/Http/Controllers/HealthSafety/PpeController.php:742` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/PpeController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
