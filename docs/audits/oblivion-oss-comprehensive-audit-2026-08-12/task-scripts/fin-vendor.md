# FIN-VENDOR: Vendor

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-VENDOR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/vendors` (`finance.vendors.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/vendors` (`finance.vendors.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/vendors/{vendor}` (`finance.vendors.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/VendorController.php:118-155`.
3. Use `GET|HEAD finance/vendors/{vendor}/edit` (`finance.vendors.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/VendorController.php:157-176`.
4. Use `GET|HEAD finance/vendors/create` (`finance.vendors.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/VendorController.php:59-75`.
5. Invoke only the owning control for `POST finance/vendors` (`finance.vendors.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/VendorController.php:77-116`; FormRequest `app/Domain/Finance/Http/Requests/StoreVendorRequest.php:15`; `name`, `trading_name`, `vendor_type`, `gst_number`, `bank_account_number`, `email`, `phone`, `address_line_1`, `address_line_2`, `city`, `region`, `postal_code`, `payment_terms_days`, `default_expense_account_id`, `notes`, `contacts`.
6. Invoke only the owning control for `PUT finance/vendors/{vendor}` (`finance.vendors.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/VendorController.php:178-232`; FormRequest `app/Domain/Finance/Http/Requests/UpdateVendorRequest.php:15`; `name`, `trading_name`, `vendor_type`, `gst_number`, `bank_account_number`, `email`, `phone`, `address_line_1`, `address_line_2`, `city`, `region`, `postal_code`, `payment_terms_days`, `default_expense_account_id`, `is_active`, `notes`, `contacts`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0695` at `app/Domain/Finance/Http/Controllers/VendorController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0696` at `app/Domain/Finance/Http/Controllers/VendorController.php:77`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0697` at `app/Domain/Finance/Http/Controllers/VendorController.php:118`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0698` at `app/Domain/Finance/Http/Controllers/VendorController.php:178`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0699` at `app/Domain/Finance/Http/Controllers/VendorController.php:157`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0700` at `app/Domain/Finance/Http/Controllers/VendorController.php:59`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/vendors/Create.tsx`, `resources/js/pages/finance/vendors/Edit.tsx`, `resources/js/pages/finance/vendors/Index.tsx`, `resources/js/pages/finance/vendors/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0696` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreVendorRequest.php:15`; fields `name`, `trading_name`, `vendor_type`, `gst_number`, `bank_account_number`, `email`, `phone`, `address_line_1`, `address_line_2`, `city`, `region`, `postal_code`, `payment_terms_days`, `default_expense_account_id`, `notes`, `contacts`; success app/Domain/Finance/Http/Controllers/VendorController.php:115 `->with('success', 'Vendor created successfully.');`.
- `ROUTE-0698` / `update`: FormRequest `app/Domain/Finance/Http/Requests/UpdateVendorRequest.php:15`; fields `name`, `trading_name`, `vendor_type`, `gst_number`, `bank_account_number`, `email`, `phone`, `address_line_1`, `address_line_2`, `city`, `region`, `postal_code`, `payment_terms_days`, `default_expense_account_id`, `is_active`, `notes`, `contacts`; success app/Domain/Finance/Http/Controllers/VendorController.php:231 `->with('success', 'Vendor updated successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/VendorController.php:81 `$vendor = FinVendor::create([`; app/Domain/Finance/Http/Controllers/VendorController.php:104 `$vendor->contacts()->create([`; app/Domain/Finance/Http/Controllers/VendorController.php:182 `$vendor->update([`; app/Domain/Finance/Http/Controllers/VendorController.php:206 `$vendor->contacts()->whereNotIn('id', $incomingIds)->delete();`; app/Domain/Finance/Http/Controllers/VendorController.php:211 `$vendor->contacts()->where('id', $contactData['id'])->update([`; app/Domain/Finance/Http/Controllers/VendorController.php:220 `$vendor->contacts()->create([`; responses app/Domain/Finance/Http/Controllers/VendorController.php:43 `return Inertia::render('finance/vendors/Index', [`; app/Domain/Finance/Http/Controllers/VendorController.php:114 `return redirect()->route('finance.vendors.show', $vendor)`; app/Domain/Finance/Http/Controllers/VendorController.php:148 `return Inertia::render('finance/vendors/Show', [`; app/Domain/Finance/Http/Controllers/VendorController.php:230 `return redirect()->route('finance.vendors.show', $vendor)`; app/Domain/Finance/Http/Controllers/VendorController.php:172 `return Inertia::render('finance/vendors/Edit', [`; app/Domain/Finance/Http/Controllers/VendorController.php:72 `return Inertia::render('finance/vendors/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/vendors` — `finance.vendors.index` — `App\Domain\Finance\Http\Controllers\VendorController@index` — `app/Domain/Finance/Http/Controllers/VendorController.php:16` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/vendors` — `finance.vendors.store` — `App\Domain\Finance\Http\Controllers\VendorController@store` — `app/Domain/Finance/Http/Controllers/VendorController.php:77` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/vendors/{vendor}` — `finance.vendors.show` — `App\Domain\Finance\Http\Controllers\VendorController@show` — `app/Domain/Finance/Http/Controllers/VendorController.php:118` — middleware `web, auth, permission:finance.ap.view`
- `PUT finance/vendors/{vendor}` — `finance.vendors.update` — `App\Domain\Finance\Http\Controllers\VendorController@update` — `app/Domain/Finance/Http/Controllers/VendorController.php:178` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/vendors/{vendor}/edit` — `finance.vendors.edit` — `App\Domain\Finance\Http\Controllers\VendorController@edit` — `app/Domain/Finance/Http/Controllers/VendorController.php:157` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/vendors/create` — `finance.vendors.create` — `App\Domain\Finance\Http\Controllers\VendorController@create` — `app/Domain/Finance/Http/Controllers/VendorController.php:59` — middleware `web, auth, permission:finance.ap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/VendorController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/vendors/Create.tsx`, `resources/js/pages/finance/vendors/Edit.tsx`, `resources/js/pages/finance/vendors/Index.tsx`, `resources/js/pages/finance/vendors/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
