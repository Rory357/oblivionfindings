# CAP-MED-EMAR-STOCK-PHARMACY: Medication stock adjustment receipt and pharmacy orders

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar` (`emar.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar` (`emar.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/stock` (`emar.stock`, action `stock`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarController.php:1934-2099`.
3. Invoke only the owning control for `PATCH emar/stock/{stock}` (`emar.stock.update`, action `updateStockItem`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:4332-4346`; `reorder_level`, `reorder_quantity`, `expiry_date`, `batch_number`, `supplier_name`, `storage_condition`.
4. Invoke only the owning control for `POST emar/stock/adjust` (`emar.stock.adjust`, action `adjustStock`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:4348-4369`; `client_medication_id`, `new_quantity`, `reason`.
5. Invoke only the owning control for `POST emar/stock/pharmacy-orders` (`emar.pharmacy_orders.store`, action `storePharmacyOrder`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:4107-4134`; `client_id`, `client_medication_id`, `pharmacy_name`, `pharmacy_phone`, `pharmacy_email`, `quantity_ordered`, `order_notes`, `order_type`, `batch_number`, `batch_expiry`, `expiry_date`.
6. Invoke only the owning control for `PUT emar/stock/pharmacy-orders/{order}` (`emar.pharmacy_orders.update`, action `updatePharmacyOrder`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:4136-4158`; `order_notes`, `pharmacy_name`, `pharmacy_phone`, `pharmacy_email`, `quantity_ordered`, `delivery_notes`, `batch_number`, `batch_expiry`, `expiry_date`.
7. Invoke only the owning control for `POST emar/stock/pharmacy-orders/{order}/advance` (`emar.pharmacy_orders.advance`, action `advancePharmacyOrder`). Source category: **mutation outcome source gap (advancePharmacyOrder)**; controller `app/Http/Controllers/Emar/EmarController.php:4160-4233`; `batch_number`, `batch_expiry`, `quantity_received`, `delivery_notes`.
8. Invoke only the owning control for `POST emar/stock/receive` (`emar.stock.receive`, action `receiveStock`). Source category: **mutation outcome source gap (receiveStock)**; controller `app/Http/Controllers/Emar/EmarController.php:4235-4330`; `client_medication_id`, `quantity`, `notes`, `batch_number`, `expiry_date`, `scan_code`, `scan_source`, `scan_verified`, `scan_match_source`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`.

## Source-applicable states and transitions

- **information presented** is applicable only to `dashboard` / `ROUTE-0327` at `app/Http/Controllers/Emar/EmarController.php:788`; it is not runtime-observed.
- **information presented** is applicable only to `stock` / `ROUTE-0436` at `app/Http/Controllers/Emar/EmarController.php:1934`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStockItem` / `ROUTE-0437` at `app/Http/Controllers/Emar/EmarController.php:4332`; it is not runtime-observed.
- **updated/revised** is applicable only to `adjustStock` / `ROUTE-0438` at `app/Http/Controllers/Emar/EmarController.php:4348`; it is not runtime-observed.
- **created/recorded** is applicable only to `storePharmacyOrder` / `ROUTE-0439` at `app/Http/Controllers/Emar/EmarController.php:4107`; it is not runtime-observed.
- **updated/revised** is applicable only to `updatePharmacyOrder` / `ROUTE-0440` at `app/Http/Controllers/Emar/EmarController.php:4136`; it is not runtime-observed.
- **mutation outcome source gap (advancePharmacyOrder)** is applicable only to `advancePharmacyOrder` / `ROUTE-0441` at `app/Http/Controllers/Emar/EmarController.php:4160`; it is not runtime-observed.
- **mutation outcome source gap (receiveStock)** is applicable only to `receiveStock` / `ROUTE-0442` at `app/Http/Controllers/Emar/EmarController.php:4235`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Index.tsx`, `resources/js/pages/emar/StockManagement.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0437` / `updateStockItem`: fields `reorder_level`, `reorder_quantity`, `expiry_date`, `batch_number`, `supplier_name`, `storage_condition`.
- `ROUTE-0438` / `adjustStock`: fields `client_medication_id`, `new_quantity`, `reason`.
- `ROUTE-0439` / `storePharmacyOrder`: fields `client_id`, `client_medication_id`, `pharmacy_name`, `pharmacy_phone`, `pharmacy_email`, `quantity_ordered`, `order_notes`, `order_type`, `batch_number`, `batch_expiry`, `expiry_date`.
- `ROUTE-0440` / `updatePharmacyOrder`: fields `order_notes`, `pharmacy_name`, `pharmacy_phone`, `pharmacy_email`, `quantity_ordered`, `delivery_notes`, `batch_number`, `batch_expiry`, `expiry_date`.
- `ROUTE-0441` / `advancePharmacyOrder`: fields `batch_number`, `batch_expiry`, `quantity_received`, `delivery_notes`; failure app/Http/Controllers/Emar/EmarController.php:4172 `return redirect()->back()->withErrors(['status' => 'Order cannot be advanced from its current status.']);`.
- `ROUTE-0442` / `receiveStock`: fields `client_medication_id`, `quantity`, `notes`, `batch_number`, `expiry_date`, `scan_code`, `scan_source`, `scan_verified`, `scan_match_source`, `client_request_uuid`, `captured_offline_at`, `origin_device_id`, `queued_offline`; success app/Http/Controllers/Emar/EmarController.php:4329 `return redirect()->back()->with('success', 'Stock received successfully.');`.

## Failure and recovery paths

- `advancePharmacyOrder`: app/Http/Controllers/Emar/EmarController.php:4172 `return redirect()->back()->withErrors(['status' => 'Order cannot be advanced from its current status.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:4343 `$stock->update($validated);`; app/Http/Controllers/Emar/EmarController.php:4357 `$stock = ClientMedicationStock::firstOrCreate(`; app/Http/Controllers/Emar/EmarController.php:4361 `$stock->update([`; app/Http/Controllers/Emar/EmarController.php:4131 `MedicationPharmacyOrder::create($validated);`; app/Http/Controllers/Emar/EmarController.php:4155 `$order->update($validated);`; app/Http/Controllers/Emar/EmarController.php:4207 `$order->update($updateData);`; app/Http/Controllers/Emar/EmarController.php:4212 `$stock = ClientMedicationStock::firstOrCreate(`; app/Http/Controllers/Emar/EmarController.php:4227 `$stock->save();`; app/Http/Controllers/Emar/EmarController.php:4277 `$stock = ClientMedicationStock::firstOrCreate(`; app/Http/Controllers/Emar/EmarController.php:4283 `$stock->update([`; responses app/Http/Controllers/Emar/EmarController.php:794 `return Inertia::render('emar/Index', array_merge(`; app/Http/Controllers/Emar/EmarController.php:2020 `return [`; app/Http/Controllers/Emar/EmarController.php:2065 `return Inertia::render('emar/StockManagement', [`; app/Http/Controllers/Emar/EmarController.php:4345 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4368 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4133 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4157 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4172 `return redirect()->back()->withErrors(['status' => 'Order cannot be advanced from its current status.']);`; app/Http/Controllers/Emar/EmarController.php:4232 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:4261 `return response()->json($cached);`; app/Http/Controllers/Emar/EmarController.php:4316 `return response()->json(`; app/Http/Controllers/Emar/EmarController.php:4329 `return redirect()->back()->with('success', 'Stock received successfully.');`; audit calls app/Http/Controllers/Emar/EmarController.php:4291 `AuditLogger::log('medications.stock.receive', $stock, array_filter([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar` — `emar.index` — `App\Http\Controllers\Emar\EmarController@dashboard` — `app/Http/Controllers/Emar/EmarController.php:788` — middleware `web, auth, permission:medications.view`
- `GET|HEAD emar/stock` — `emar.stock` — `App\Http\Controllers\Emar\EmarController@stock` — `app/Http/Controllers/Emar/EmarController.php:1934` — middleware `web, auth, permission:medications.view`
- `PATCH emar/stock/{stock}` — `emar.stock.update` — `App\Http\Controllers\Emar\EmarController@updateStockItem` — `app/Http/Controllers/Emar/EmarController.php:4332` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/stock/adjust` — `emar.stock.adjust` — `App\Http\Controllers\Emar\EmarController@adjustStock` — `app/Http/Controllers/Emar/EmarController.php:4348` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/stock/pharmacy-orders` — `emar.pharmacy_orders.store` — `App\Http\Controllers\Emar\EmarController@storePharmacyOrder` — `app/Http/Controllers/Emar/EmarController.php:4107` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/stock/pharmacy-orders/{order}` — `emar.pharmacy_orders.update` — `App\Http\Controllers\Emar\EmarController@updatePharmacyOrder` — `app/Http/Controllers/Emar/EmarController.php:4136` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/stock/pharmacy-orders/{order}/advance` — `emar.pharmacy_orders.advance` — `App\Http\Controllers\Emar\EmarController@advancePharmacyOrder` — `app/Http/Controllers/Emar/EmarController.php:4160` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/stock/receive` — `emar.stock.receive` — `App\Http\Controllers\Emar\EmarController@receiveStock` — `app/Http/Controllers/Emar/EmarController.php:4235` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Index.tsx`, `resources/js/pages/emar/StockManagement.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
