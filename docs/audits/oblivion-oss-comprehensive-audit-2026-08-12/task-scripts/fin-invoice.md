# FIN-INVOICE: Invoice

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-INVOICE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/invoices` (`finance.invoices.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/invoices` (`finance.invoices.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/invoices/{invoice}` (`finance.invoices.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/InvoiceController.php:285-298`.
3. Use `GET|HEAD finance/invoices/{invoice}/edit` (`finance.invoices.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/InvoiceController.php:300-334`.
4. Use `GET|HEAD finance/invoices/{invoice}/pdf` (`finance.invoices.pdf`, action `downloadPdf`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Finance/Http/Controllers/InvoiceController.php:467-482`.
5. Use `GET|HEAD finance/invoices/create` (`finance.invoices.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/InvoiceController.php:119-157`.
6. Invoke only the owning control for `POST finance/invoices` (`finance.invoices.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/InvoiceController.php:159-283`; FormRequest `app/Domain/Finance/Http/Requests/StoreInvoiceRequest.php:50`; `invoice_number`, `client_id`, `funding_body`, `invoice_date`, `due_date`, `client_name`, `client_email`, `client_address`, `bill_id`, `currency_code`, `notes`, `terms`, `email_subject`, `email_body`, `lines`.
7. Invoke only the owning control for `PUT finance/invoices/{invoice}` (`finance.invoices.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/InvoiceController.php:336-426`; FormRequest `app/Domain/Finance/Http/Requests/UpdateInvoiceRequest.php:14`; `invoice_date`, `due_date`, `client_id`, `funding_body`, `client_name`, `client_email`, `client_address`, `bill_id`, `currency_code`, `notes`, `terms`, `email_subject`, `email_body`, `lines`.
8. Invoke only the owning control for `POST finance/invoices/{invoice}/cancel` (`finance.invoices.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/InvoiceController.php:530-556`; no exact validation fields extracted.
9. Invoke only the owning control for `POST finance/invoices/{invoice}/send` (`finance.invoices.send`, action `send`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/InvoiceController.php:428-465`; no exact validation fields extracted.
10. Invoke only the owning control for `POST finance/invoices/{invoiceId}/mark-paid` (`finance.invoices.mark-paid`, action `markPaid`). Source category: **mutation outcome source gap (markPaid)**; controller `app/Domain/Finance/Http/Controllers/InvoiceController.php:484-528`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0597` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:29`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0598` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:159`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0599` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:285`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0600` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:336`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-0601` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:530`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-0602` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:300`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadPdf` / `ROUTE-0603` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:467`; it is not runtime-observed.
- **created/recorded** is applicable only to `send` / `ROUTE-0604` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:428`; it is not runtime-observed.
- **mutation outcome source gap (markPaid)** is applicable only to `markPaid` / `ROUTE-0605` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:484`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0606` at `app/Domain/Finance/Http/Controllers/InvoiceController.php:119`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/invoices/Create.tsx`, `resources/js/pages/finance/invoices/Edit.tsx`, `resources/js/pages/finance/invoices/Index.tsx`, `resources/js/pages/finance/invoices/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0598` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreInvoiceRequest.php:50`; fields `invoice_number`, `client_id`, `funding_body`, `invoice_date`, `due_date`, `client_name`, `client_email`, `client_address`, `bill_id`, `currency_code`, `notes`, `terms`, `email_subject`, `email_body`, `lines`; success app/Domain/Finance/Http/Controllers/InvoiceController.php:282 `->with('success', 'Invoice created successfully.');`; failure app/Domain/Finance/Http/Controllers/InvoiceController.php:190 `throw ValidationException::withMessages([`.
- `ROUTE-0600` / `update`: FormRequest `app/Domain/Finance/Http/Requests/UpdateInvoiceRequest.php:14`; fields `invoice_date`, `due_date`, `client_id`, `funding_body`, `client_name`, `client_email`, `client_address`, `bill_id`, `currency_code`, `notes`, `terms`, `email_subject`, `email_body`, `lines`; success app/Domain/Finance/Http/Controllers/InvoiceController.php:425 `->with('success', 'Invoice updated successfully.');`.
- `ROUTE-0601` / `cancel`: success app/Domain/Finance/Http/Controllers/InvoiceController.php:540 `->with('success', 'Invoice already cancelled.');`; app/Domain/Finance/Http/Controllers/InvoiceController.php:555 `->with('success', 'Invoice cancelled.');`; failure app/Domain/Finance/Http/Controllers/InvoiceController.php:535 `return back()->withErrors(['invoice' => 'Cannot cancel a paid invoice.']);`.
- `ROUTE-0604` / `send`: success app/Domain/Finance/Http/Controllers/InvoiceController.php:464 `->with('success', 'Invoice is being sent to '.$invoice->client_email);`; failure app/Domain/Finance/Http/Controllers/InvoiceController.php:433 `return back()->withErrors(['invoice' => 'Invoice has no client email address.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:437 `return back()->withErrors(['invoice' => 'Cannot send a cancelled invoice.']);`.
- `ROUTE-0605` / `markPaid`: success app/Domain/Finance/Http/Controllers/InvoiceController.php:498 `->with('success', 'Invoice already marked as paid.');`; app/Domain/Finance/Http/Controllers/InvoiceController.php:527 `->with('success', 'Invoice marked as paid.');`; failure app/Domain/Finance/Http/Controllers/InvoiceController.php:492 `return back()->withErrors(['invoice' => 'Cannot mark a cancelled invoice as paid.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:523 `return back()->withErrors(['invoice' => 'Could not record the receipt: '.$e->getMessage()]);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/InvoiceController.php:190 `throw ValidationException::withMessages([`.
- `cancel`: app/Domain/Finance/Http/Controllers/InvoiceController.php:535 `return back()->withErrors(['invoice' => 'Cannot cancel a paid invoice.']);`.
- `send`: app/Domain/Finance/Http/Controllers/InvoiceController.php:433 `return back()->withErrors(['invoice' => 'Invoice has no client email address.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:437 `return back()->withErrors(['invoice' => 'Cannot send a cancelled invoice.']);`.
- `markPaid`: app/Domain/Finance/Http/Controllers/InvoiceController.php:492 `return back()->withErrors(['invoice' => 'Cannot mark a cancelled invoice as paid.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:523 `return back()->withErrors(['invoice' => 'Could not record the receipt: '.$e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/InvoiceController.php:243 `$invoice = FinInvoice::create([`; app/Domain/Finance/Http/Controllers/InvoiceController.php:268 `$invoice->lines()->create($line);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:275 `->update(['status' => 'invoiced']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:397 `$invoice->update([`; app/Domain/Finance/Http/Controllers/InvoiceController.php:418 `$invoice->lines()->delete();`; app/Domain/Finance/Http/Controllers/InvoiceController.php:420 `$invoice->lines()->create($line);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:551 `$invoice->update(['status' => 'cancelled']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:446 `$invoice->update([`; app/Domain/Finance/Http/Controllers/InvoiceController.php:520 `$invoice->update(['status' => 'paid', 'paid_at' => now()]);`; responses app/Domain/Finance/Http/Controllers/InvoiceController.php:87 `return $invoice;`; app/Domain/Finance/Http/Controllers/InvoiceController.php:101 `return Inertia::render('finance/invoices/Index', [`; app/Domain/Finance/Http/Controllers/InvoiceController.php:278 `return $invoice;`; app/Domain/Finance/Http/Controllers/InvoiceController.php:281 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:295 `return Inertia::render('finance/invoices/Show', [`; app/Domain/Finance/Http/Controllers/InvoiceController.php:339 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:424 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:535 `return back()->withErrors(['invoice' => 'Cannot cancel a paid invoice.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:539 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:554 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:303 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:328 `return Inertia::render('finance/invoices/Edit', [`; app/Domain/Finance/Http/Controllers/InvoiceController.php:477 `return Storage::disk('local')->download(`; app/Domain/Finance/Http/Controllers/InvoiceController.php:433 `return back()->withErrors(['invoice' => 'Invoice has no client email address.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:437 `return back()->withErrors(['invoice' => 'Cannot send a cancelled invoice.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:463 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:492 `return back()->withErrors(['invoice' => 'Cannot mark a cancelled invoice as paid.']);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:497 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/InvoiceController.php:523 `return back()->withErrors(['invoice' => 'Could not record the receipt: '.$e->getMessage()]);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:526 `return redirect()->route('finance.invoices.show', $invoice->fresh())`; app/Domain/Finance/Http/Controllers/InvoiceController.php:150 `return Inertia::render('finance/invoices/Create', [`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Domain/Finance/Http/Controllers/InvoiceController.php:458 `PostFinInvoiceJournalJob::dispatch($invoice);`; app/Domain/Finance/Http/Controllers/InvoiceController.php:461 `SendInvoiceEmailJob::dispatch($invoice->id);`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `GET|HEAD finance/invoices` — `finance.invoices.index` — `App\Domain\Finance\Http\Controllers\InvoiceController@index` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:29` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/invoices` — `finance.invoices.store` — `App\Domain\Finance\Http\Controllers\InvoiceController@store` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:159` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/invoices/{invoice}` — `finance.invoices.show` — `App\Domain\Finance\Http\Controllers\InvoiceController@show` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:285` — middleware `web, auth, permission:finance.ar.view`
- `PUT finance/invoices/{invoice}` — `finance.invoices.update` — `App\Domain\Finance\Http\Controllers\InvoiceController@update` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:336` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/invoices/{invoice}/cancel` — `finance.invoices.cancel` — `App\Domain\Finance\Http\Controllers\InvoiceController@cancel` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:530` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/invoices/{invoice}/edit` — `finance.invoices.edit` — `App\Domain\Finance\Http\Controllers\InvoiceController@edit` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:300` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/invoices/{invoice}/pdf` — `finance.invoices.pdf` — `App\Domain\Finance\Http\Controllers\InvoiceController@downloadPdf` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:467` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/invoices/{invoice}/send` — `finance.invoices.send` — `App\Domain\Finance\Http\Controllers\InvoiceController@send` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:428` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/invoices/{invoiceId}/mark-paid` — `finance.invoices.mark-paid` — `App\Domain\Finance\Http\Controllers\InvoiceController@markPaid` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:484` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/invoices/create` — `finance.invoices.create` — `App\Domain\Finance\Http\Controllers\InvoiceController@create` — `app/Domain/Finance/Http/Controllers/InvoiceController.php:119` — middleware `web, auth, permission:finance.ar.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/InvoiceController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/invoices/Create.tsx`, `resources/js/pages/finance/invoices/Edit.tsx`, `resources/js/pages/finance/invoices/Index.tsx`, `resources/js/pages/finance/invoices/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
