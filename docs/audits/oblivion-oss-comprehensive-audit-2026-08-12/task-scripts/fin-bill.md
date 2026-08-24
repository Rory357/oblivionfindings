# FIN-BILL: Bill

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-BILL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/bills` (`finance.bills.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/bills` (`finance.bills.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/bills/{bill}` (`finance.bills.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BillController.php:154-174`.
3. Use `GET|HEAD finance/bills/{bill}/edit` (`finance.bills.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BillController.php:176-230`.
4. Use `GET|HEAD finance/bills/create` (`finance.bills.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BillController.php:96-142`.
5. Invoke only the owning control for `POST finance/bills` (`finance.bills.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/BillController.php:144-152`; FormRequest `app/Domain/Finance/Http/Requests/StoreBillRequest.php:15`; `vendor_id`, `bill_number`, `vendor_reference`, `bill_date`, `due_date`, `notes`, `purchase_order_id`, `lines`.
6. Invoke only the owning control for `PUT finance/bills/{bill}` (`finance.bills.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/BillController.php:232-249`; FormRequest `app/Domain/Finance/Http/Requests/UpdateBillRequest.php:15`; `vendor_id`, `vendor_reference`, `bill_date`, `due_date`, `notes`, `purchase_order_id`, `lines`.
7. Invoke only the owning control for `POST finance/bills/{bill}/approve` (`finance.bills.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Finance/Http/Controllers/BillController.php:251-265`; no exact validation fields extracted.
8. Invoke only the owning control for `POST finance/bills/{bill}/cancel` (`finance.bills.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/BillController.php:267-279`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0500` at `app/Domain/Finance/Http/Controllers/BillController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0501` at `app/Domain/Finance/Http/Controllers/BillController.php:144`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0502` at `app/Domain/Finance/Http/Controllers/BillController.php:154`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0503` at `app/Domain/Finance/Http/Controllers/BillController.php:232`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0504` at `app/Domain/Finance/Http/Controllers/BillController.php:251`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-0505` at `app/Domain/Finance/Http/Controllers/BillController.php:267`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0506` at `app/Domain/Finance/Http/Controllers/BillController.php:176`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0507` at `app/Domain/Finance/Http/Controllers/BillController.php:96`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/bills/Create.tsx`, `resources/js/pages/finance/bills/Edit.tsx`, `resources/js/pages/finance/bills/Index.tsx`, `resources/js/pages/finance/bills/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0501` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreBillRequest.php:15`; fields `vendor_id`, `bill_number`, `vendor_reference`, `bill_date`, `due_date`, `notes`, `purchase_order_id`, `lines`; success app/Domain/Finance/Http/Controllers/BillController.php:151 `->with('success', 'Bill created successfully.');`.
- `ROUTE-0503` / `update`: FormRequest `app/Domain/Finance/Http/Requests/UpdateBillRequest.php:15`; fields `vendor_id`, `vendor_reference`, `bill_date`, `due_date`, `notes`, `purchase_order_id`, `lines`; success app/Domain/Finance/Http/Controllers/BillController.php:248 `->with('success', 'Bill updated successfully.');`; failure app/Domain/Finance/Http/Controllers/BillController.php:244 `return back()->withErrors(['bill' => $e->getMessage()]);`.
- `ROUTE-0504` / `approve`: success app/Domain/Finance/Http/Controllers/BillController.php:264 `->with('success', 'Bill approved and journal posted successfully.');`; failure app/Domain/Finance/Http/Controllers/BillController.php:258 `return back()->withErrors(['bill' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:260 `return back()->withErrors(['bill' => 'Failed to approve bill: '.$e->getMessage()]);`.
- `ROUTE-0505` / `cancel`: success app/Domain/Finance/Http/Controllers/BillController.php:278 `->with('success', 'Bill cancelled.');`; failure app/Domain/Finance/Http/Controllers/BillController.php:274 `return back()->withErrors(['bill' => $e->getMessage()]);`.

## Failure and recovery paths

- `update`: app/Domain/Finance/Http/Controllers/BillController.php:244 `return back()->withErrors(['bill' => $e->getMessage()]);`.
- `approve`: app/Domain/Finance/Http/Controllers/BillController.php:258 `return back()->withErrors(['bill' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:260 `return back()->withErrors(['bill' => 'Failed to approve bill: '.$e->getMessage()]);`.
- `cancel`: app/Domain/Finance/Http/Controllers/BillController.php:274 `return back()->withErrors(['bill' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/BillController.php:79 `return Inertia::render('finance/bills/Index', [`; app/Domain/Finance/Http/Controllers/BillController.php:150 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:171 `return Inertia::render('finance/bills/Show', [`; app/Domain/Finance/Http/Controllers/BillController.php:235 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:244 `return back()->withErrors(['bill' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:247 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:258 `return back()->withErrors(['bill' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:260 `return back()->withErrors(['bill' => 'Failed to approve bill: '.$e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:263 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:274 `return back()->withErrors(['bill' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/BillController.php:277 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:181 `return redirect()->route('finance.bills.show', $bill)`; app/Domain/Finance/Http/Controllers/BillController.php:221 `return Inertia::render('finance/bills/Edit', [`; app/Domain/Finance/Http/Controllers/BillController.php:134 `return Inertia::render('finance/bills/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/bills` — `finance.bills.index` — `App\Domain\Finance\Http\Controllers\BillController@index` — `app/Domain/Finance/Http/Controllers/BillController.php:25` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/bills` — `finance.bills.store` — `App\Domain\Finance\Http\Controllers\BillController@store` — `app/Domain/Finance/Http/Controllers/BillController.php:144` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/bills/{bill}` — `finance.bills.show` — `App\Domain\Finance\Http\Controllers\BillController@show` — `app/Domain/Finance/Http/Controllers/BillController.php:154` — middleware `web, auth, permission:finance.ap.view`
- `PUT finance/bills/{bill}` — `finance.bills.update` — `App\Domain\Finance\Http\Controllers\BillController@update` — `app/Domain/Finance/Http/Controllers/BillController.php:232` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/bills/{bill}/approve` — `finance.bills.approve` — `App\Domain\Finance\Http\Controllers\BillController@approve` — `app/Domain/Finance/Http/Controllers/BillController.php:251` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/bills/{bill}/cancel` — `finance.bills.cancel` — `App\Domain\Finance\Http\Controllers\BillController@cancel` — `app/Domain/Finance/Http/Controllers/BillController.php:267` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/bills/{bill}/edit` — `finance.bills.edit` — `App\Domain\Finance\Http\Controllers\BillController@edit` — `app/Domain/Finance/Http/Controllers/BillController.php:176` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/bills/create` — `finance.bills.create` — `App\Domain\Finance\Http\Controllers\BillController@create` — `app/Domain/Finance/Http/Controllers/BillController.php:96` — middleware `web, auth, permission:finance.ap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BillController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/bills/Create.tsx`, `resources/js/pages/finance/bills/Edit.tsx`, `resources/js/pages/finance/bills/Index.tsx`, `resources/js/pages/finance/bills/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
