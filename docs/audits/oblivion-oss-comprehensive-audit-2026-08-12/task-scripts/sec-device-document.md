# SEC-DEVICE-DOCUMENT: Device Document

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`, `permission:securityDevices.devices.view`
- Owning module: Security and devices
- Legacy family: `SEC-DEVICE-DOCUMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `security-devices/devices/{device}/documents/{document}` (`security-devices.devices.documents.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`, `permission:securityDevices.devices.view`.
- Exact middleware atoms: `web`, `auth`, `permission:securityDevices.viewAny`, `permission:securityDevices.devices.update`, `permission:securityDevices.devices.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD security-devices/devices/{device}/documents/{document}` (`security-devices.devices.documents.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST security-devices/devices/{device}/documents` (`security-devices.devices.documents.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:33-73`; `file`.
3. Invoke only the owning control for `DELETE security-devices/devices/{device}/documents/{document}` (`security-devices.devices.documents.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:85-102`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2551` at `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:33`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2552` at `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:85`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-2553` at `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:75`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2551` / `store`: fields `file`; success app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:72 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-2552` / `destroy`: success app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:101 `return redirect()->back()->with('success', 'Document removed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:56 `DeviceDocument::create([`; app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:94 `Storage::disk($document->storage_disk)->delete($document->storage_path);`; app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:99 `$document->delete();`; responses app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:53 `return redirect()->back()->with('error', 'Failed to store uploaded file.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:72 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:101 `return redirect()->back()->with('success', 'Document removed.');`; app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:81 `return Storage::disk($document->storage_disk)`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST security-devices/devices/{device}/documents` — `security-devices.devices.documents.store` — `App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController@store` — `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:33` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `DELETE security-devices/devices/{device}/documents/{document}` — `security-devices.devices.documents.destroy` — `App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController@destroy` — `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:85` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.update`
- `GET|HEAD security-devices/devices/{device}/documents/{document}` — `security-devices.devices.documents.download` — `App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController@download` — `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php:75` — middleware `web, auth, permission:securityDevices.viewAny, permission:securityDevices.devices.view`

## Source anchors and limits

- Backend anchor: `app/Domain/SecurityDevices/Http/Controllers/DeviceDocumentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
