# FIN-PURCHASE-ORDER: Purchase Order

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PURCHASE-ORDER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/purchase-orders` (`finance.purchase-orders.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/purchase-orders` (`finance.purchase-orders.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/purchase-orders/{purchaseOrder}` (`finance.purchase-orders.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:144-160`.
3. Use `GET|HEAD finance/purchase-orders/{purchaseOrder}/edit` (`finance.purchase-orders.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:162-182`.
4. Use `GET|HEAD finance/purchase-orders/create` (`finance.purchase-orders.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:69-81`.
5. Invoke only the owning control for `POST finance/purchase-orders` (`finance.purchase-orders.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:83-142`; FormRequest `app/Domain/Finance/Http/Requests/StorePurchaseOrderRequest.php:15`; `vendor_id`, `order_date`, `expected_date`, `notes`, `cost_centre_id`, `funding_stream_id`, `lines`.
6. Invoke only the owning control for `PUT finance/purchase-orders/{purchaseOrder}` (`finance.purchase-orders.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:184-236`; FormRequest `app/Domain/Finance/Http/Requests/UpdatePurchaseOrderRequest.php:15`; `vendor_id`, `order_date`, `expected_date`, `notes`, `cost_centre_id`, `funding_stream_id`, `lines`.
7. Invoke only the owning control for `POST finance/purchase-orders/{purchaseOrder}/approve` (`finance.purchase-orders.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:238-255`; no exact validation fields extracted.
8. Invoke only the owning control for `POST finance/purchase-orders/{purchaseOrder}/convert-to-bill` (`finance.purchase-orders.convert-to-bill`, action `convertToBill`). Source category: **mutation outcome source gap (convertToBill)**; controller `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:257-310`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0653` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0654` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:83`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0655` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:144`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0656` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:184`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0657` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:238`; it is not runtime-observed.
- **mutation outcome source gap (convertToBill)** is applicable only to `convertToBill` / `ROUTE-0658` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:257`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0659` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:162`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0660` at `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:69`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/purchase-orders/Create.tsx`, `resources/js/pages/finance/purchase-orders/Edit.tsx`, `resources/js/pages/finance/purchase-orders/Index.tsx`, `resources/js/pages/finance/purchase-orders/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0654` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StorePurchaseOrderRequest.php:15`; fields `vendor_id`, `order_date`, `expected_date`, `notes`, `cost_centre_id`, `funding_stream_id`, `lines`; success app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:141 `->with('success', 'Purchase order created successfully.');`.
- `ROUTE-0656` / `update`: FormRequest `app/Domain/Finance/Http/Requests/UpdatePurchaseOrderRequest.php:15`; fields `vendor_id`, `order_date`, `expected_date`, `notes`, `cost_centre_id`, `funding_stream_id`, `lines`; success app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:235 `->with('success', 'Purchase order updated successfully.');`.
- `ROUTE-0657` / `approve`: success app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:254 `->with('success', 'Purchase order approved.');`.
- `ROUTE-0658` / `convertToBill`: success app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:309 `->with('success', 'Bill created from purchase order.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:117 `$po = FinPurchaseOrder::create([`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:134 `$po->lines()->create($ld);`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:194 `$purchaseOrder->lines()->delete();`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:210 `$purchaseOrder->lines()->create([`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:221 `$purchaseOrder->update([`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:247 `$purchaseOrder->update([`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:272 `$bill = FinBill::create([`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:291 `FinBillLine::create([`; responses app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:52 `return Inertia::render('finance/purchase-orders/Index', [`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:137 `return $po;`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:140 `return redirect()->route('finance.purchase-orders.show', $po)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:157 `return Inertia::render('finance/purchase-orders/Show', [`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:187 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:234 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:243 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:253 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:262 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:305 `return $bill;`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:308 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:167 `return redirect()->route('finance.purchase-orders.show', $purchaseOrder)`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:175 `return Inertia::render('finance/purchase-orders/Edit', [`; app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:75 `return Inertia::render('finance/purchase-orders/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/purchase-orders` — `finance.purchase-orders.index` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@index` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:21` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/purchase-orders` — `finance.purchase-orders.store` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@store` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:83` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/purchase-orders/{purchaseOrder}` — `finance.purchase-orders.show` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@show` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:144` — middleware `web, auth, permission:finance.ap.view`
- `PUT finance/purchase-orders/{purchaseOrder}` — `finance.purchase-orders.update` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@update` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:184` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/purchase-orders/{purchaseOrder}/approve` — `finance.purchase-orders.approve` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@approve` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:238` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/purchase-orders/{purchaseOrder}/convert-to-bill` — `finance.purchase-orders.convert-to-bill` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@convertToBill` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:257` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/purchase-orders/{purchaseOrder}/edit` — `finance.purchase-orders.edit` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@edit` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:162` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/purchase-orders/create` — `finance.purchase-orders.create` — `App\Domain\Finance\Http\Controllers\PurchaseOrderController@create` — `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php:69` — middleware `web, auth, permission:finance.ap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PurchaseOrderController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/purchase-orders/Create.tsx`, `resources/js/pages/finance/purchase-orders/Edit.tsx`, `resources/js/pages/finance/purchase-orders/Index.tsx`, `resources/js/pages/finance/purchase-orders/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
