# CAP-HS-PPE-INVENTORY: PPE inventory allocation condemnation and disposal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-PPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/ppe` (`health-safety.ppe.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/ppe` (`health-safety.ppe.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/ppe/export` (`health-safety.ppe.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/PpeController.php:227-257`.
3. Use `GET|HEAD health-safety/ppe/inventory/{inventory}/attachments/{attachment}/download` (`health-safety.ppe.inventory.attachments.download`, action `downloadInventoryAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/PpeController.php:843-846`.
4. Invoke only the owning control for `POST health-safety/ppe/inventory` (`health-safety.ppe.inventory.store`, action `storeInventory`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:588-601`; FormRequest `app/Http/Requests/HealthSafety/StorePpeInventoryRequest.php:20`; `ppe_type_id`, `site_id`, `brand`, `model`, `serial_number`, `purchase_date`, `expiry_date`, `condition`, `quantity`, `location`, `next_inspection_due`, `documents`.
5. Invoke only the owning control for `PUT health-safety/ppe/inventory/{inventory}` (`health-safety.ppe.inventory.update`, action `updateInventory`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/PpeController.php:603-621`; `brand`.
6. Invoke only the owning control for `POST health-safety/ppe/inventory/{inventory}/allocate` (`health-safety.ppe.inventory.allocate`, action `allocate`). Source category: **mutation outcome source gap (allocate)**; controller `app/Http/Controllers/HealthSafety/PpeController.php:674-704`; `user_id`.
7. Invoke only the owning control for `POST health-safety/ppe/inventory/{inventory}/attachments` (`health-safety.ppe.inventory.attachments.store`, action `uploadInventoryAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:836-841`; no exact validation fields extracted.
8. Invoke only the owning control for `DELETE health-safety/ppe/inventory/{inventory}/attachments/{attachment}` (`health-safety.ppe.inventory.attachments.destroy`, action `destroyInventoryAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/PpeController.php:848-851`; no exact validation fields extracted.
9. Invoke only the owning control for `POST health-safety/ppe/inventory/{inventory}/condemn` (`health-safety.ppe.inventory.condemn`, action `condemn`). Source category: **mutation outcome source gap (condemn)**; controller `app/Http/Controllers/HealthSafety/PpeController.php:623-647`; `reason`.
10. Invoke only the owning control for `POST health-safety/ppe/inventory/{inventory}/dispose` (`health-safety.ppe.inventory.dispose`, action `dispose`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/PpeController.php:649-670`; `disposal_method`.
11. Invoke only the owning control for `POST health-safety/ppe/inventory/{inventory}/inspections` (`health-safety.ppe.inventory.inspections.store`, action `storeInspection`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/PpeController.php:782-832`; `result`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1153` at `app/Http/Controllers/HealthSafety/PpeController.php:37`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-1160` at `app/Http/Controllers/HealthSafety/PpeController.php:227`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeInventory` / `ROUTE-1164` at `app/Http/Controllers/HealthSafety/PpeController.php:588`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateInventory` / `ROUTE-1165` at `app/Http/Controllers/HealthSafety/PpeController.php:603`; it is not runtime-observed.
- **mutation outcome source gap (allocate)** is applicable only to `allocate` / `ROUTE-1166` at `app/Http/Controllers/HealthSafety/PpeController.php:674`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadInventoryAttachment` / `ROUTE-1167` at `app/Http/Controllers/HealthSafety/PpeController.php:836`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyInventoryAttachment` / `ROUTE-1168` at `app/Http/Controllers/HealthSafety/PpeController.php:848`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadInventoryAttachment` / `ROUTE-1169` at `app/Http/Controllers/HealthSafety/PpeController.php:843`; it is not runtime-observed.
- **mutation outcome source gap (condemn)** is applicable only to `condemn` / `ROUTE-1170` at `app/Http/Controllers/HealthSafety/PpeController.php:623`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `dispose` / `ROUTE-1171` at `app/Http/Controllers/HealthSafety/PpeController.php:649`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeInspection` / `ROUTE-1172` at `app/Http/Controllers/HealthSafety/PpeController.php:782`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/ppe/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1164` / `storeInventory`: FormRequest `app/Http/Requests/HealthSafety/StorePpeInventoryRequest.php:20`; fields `ppe_type_id`, `site_id`, `brand`, `model`, `serial_number`, `purchase_date`, `expiry_date`, `condition`, `quantity`, `location`, `next_inspection_due`, `documents`; success app/Http/Controllers/HealthSafety/PpeController.php:600 `return redirect()->back()->with('success', 'PPE inventory item added.');`.
- `ROUTE-1165` / `updateInventory`: fields `brand`; success app/Http/Controllers/HealthSafety/PpeController.php:620 `return redirect()->back()->with('success', 'PPE inventory item updated.');`.
- `ROUTE-1166` / `allocate`: fields `user_id`; success app/Http/Controllers/HealthSafety/PpeController.php:703 `return redirect()->back()->with('success', 'PPE allocated to worker.');`.
- `ROUTE-1167` / `uploadInventoryAttachment`: success app/Http/Controllers/HealthSafety/PpeController.php:840 `return redirect()->back()->with('success', 'Document uploaded.');`.
- `ROUTE-1170` / `condemn`: fields `reason`; success app/Http/Controllers/HealthSafety/PpeController.php:646 `return redirect()->back()->with('success', 'Item condemned and removed from service.');`.
- `ROUTE-1171` / `dispose`: fields `disposal_method`; success app/Http/Controllers/HealthSafety/PpeController.php:669 `return redirect()->back()->with('success', 'Item disposed and archived.');`.
- `ROUTE-1172` / `storeInspection`: fields `result`; success app/Http/Controllers/HealthSafety/PpeController.php:831 `return redirect()->back()->with('success', 'PPE inspection recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/PpeController.php:592 `$inventory = PpeInventory::create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/PpeController.php:618 `$inventory->update(array_merge($validated, ['updated_by' => $request->user()->id]));`; app/Http/Controllers/HealthSafety/PpeController.php:689 `$inventory->allocations()->create(array_merge($validated, [`; app/Http/Controllers/HealthSafety/PpeController.php:698 `$inventory->update([`; app/Http/Controllers/HealthSafety/PpeController.php:838 `$inventory->attachments()->create($this->storeUploadedFile($request, 'ppe_attachments'));`; app/Http/Controllers/HealthSafety/PpeController.php:637 `$inventory->update([`; app/Http/Controllers/HealthSafety/PpeController.php:660 `$inventory->update([`; app/Http/Controllers/HealthSafety/PpeController.php:796 `$inspection = $inventory->inspections()->create([`; app/Http/Controllers/HealthSafety/PpeController.php:827 `$inventory->update($inventoryUpdate);`; responses app/Http/Controllers/HealthSafety/PpeController.php:42 `return Inertia::render('health-safety/ppe/index', [`; app/Http/Controllers/HealthSafety/PpeController.php:235 `return response()->streamDownload(function () use ($rows) {`; app/Http/Controllers/HealthSafety/PpeController.php:600 `return redirect()->back()->with('success', 'PPE inventory item added.');`; app/Http/Controllers/HealthSafety/PpeController.php:620 `return redirect()->back()->with('success', 'PPE inventory item updated.');`; app/Http/Controllers/HealthSafety/PpeController.php:703 `return redirect()->back()->with('success', 'PPE allocated to worker.');`; app/Http/Controllers/HealthSafety/PpeController.php:840 `return redirect()->back()->with('success', 'Document uploaded.');`; app/Http/Controllers/HealthSafety/PpeController.php:850 `return $this->destroyAttachment($attachment, (int) $attachment->ppe_inventory_id === (int) $inventory->id);`; app/Http/Controllers/HealthSafety/PpeController.php:845 `return $this->downloadAttachment($attachment, (int) $attachment->ppe_inventory_id === (int) $inventory->id);`; app/Http/Controllers/HealthSafety/PpeController.php:630 `return redirect()->back()->with('error', 'This item is already out of service.');`; app/Http/Controllers/HealthSafety/PpeController.php:634 `return redirect()->back()->with('error', 'Return the item from the worker before condemning it.');`; app/Http/Controllers/HealthSafety/PpeController.php:646 `return redirect()->back()->with('success', 'Item condemned and removed from service.');`; app/Http/Controllers/HealthSafety/PpeController.php:657 `return redirect()->back()->with('error', 'Condemn the item before disposal.');`; app/Http/Controllers/HealthSafety/PpeController.php:669 `return redirect()->back()->with('success', 'Item disposed and archived.');`; app/Http/Controllers/HealthSafety/PpeController.php:831 `return redirect()->back()->with('success', 'PPE inspection recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/ppe` — `health-safety.ppe.index` — `App\Http\Controllers\HealthSafety\PpeController@index` — `app/Http/Controllers/HealthSafety/PpeController.php:37` — middleware `web, auth, permission:hazards.view`
- `GET|HEAD health-safety/ppe/export` — `health-safety.ppe.export` — `App\Http\Controllers\HealthSafety\PpeController@export` — `app/Http/Controllers/HealthSafety/PpeController.php:227` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/ppe/inventory` — `health-safety.ppe.inventory.store` — `App\Http\Controllers\HealthSafety\PpeController@storeInventory` — `app/Http/Controllers/HealthSafety/PpeController.php:588` — middleware `web, auth, permission:hazards.manage`
- `PUT health-safety/ppe/inventory/{inventory}` — `health-safety.ppe.inventory.update` — `App\Http\Controllers\HealthSafety\PpeController@updateInventory` — `app/Http/Controllers/HealthSafety/PpeController.php:603` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/ppe/inventory/{inventory}/allocate` — `health-safety.ppe.inventory.allocate` — `App\Http\Controllers\HealthSafety\PpeController@allocate` — `app/Http/Controllers/HealthSafety/PpeController.php:674` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/ppe/inventory/{inventory}/attachments` — `health-safety.ppe.inventory.attachments.store` — `App\Http\Controllers\HealthSafety\PpeController@uploadInventoryAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:836` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/ppe/inventory/{inventory}/attachments/{attachment}` — `health-safety.ppe.inventory.attachments.destroy` — `App\Http\Controllers\HealthSafety\PpeController@destroyInventoryAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:848` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/ppe/inventory/{inventory}/attachments/{attachment}/download` — `health-safety.ppe.inventory.attachments.download` — `App\Http\Controllers\HealthSafety\PpeController@downloadInventoryAttachment` — `app/Http/Controllers/HealthSafety/PpeController.php:843` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/ppe/inventory/{inventory}/condemn` — `health-safety.ppe.inventory.condemn` — `App\Http\Controllers\HealthSafety\PpeController@condemn` — `app/Http/Controllers/HealthSafety/PpeController.php:623` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/ppe/inventory/{inventory}/dispose` — `health-safety.ppe.inventory.dispose` — `App\Http\Controllers\HealthSafety\PpeController@dispose` — `app/Http/Controllers/HealthSafety/PpeController.php:649` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/ppe/inventory/{inventory}/inspections` — `health-safety.ppe.inventory.inspections.store` — `App\Http\Controllers\HealthSafety\PpeController@storeInspection` — `app/Http/Controllers/HealthSafety/PpeController.php:782` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/PpeController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/ppe/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
