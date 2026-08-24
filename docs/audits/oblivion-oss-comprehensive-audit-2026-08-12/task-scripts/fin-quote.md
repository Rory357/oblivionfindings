# FIN-QUOTE: Quote

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-QUOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/quotes` (`finance.quotes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/quotes` (`finance.quotes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/quotes/{quote}` (`finance.quotes.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/QuoteController.php:111-124`.
3. Invoke only the owning control for `POST finance/quotes` (`finance.quotes.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:126-184`; `client_id`.
4. Invoke only the owning control for `PUT finance/quotes/{quote}` (`finance.quotes.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:186-206`; `client_id`.
5. Invoke only the owning control for `POST finance/quotes/{quote}/accept` (`finance.quotes.accept`, action `accept`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:225-240`; no exact validation fields extracted.
6. Invoke only the owning control for `POST finance/quotes/{quote}/convert` (`finance.quotes.convert`, action `convertToAgreement`). Source category: **mutation outcome source gap (convertToAgreement)**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:242-275`; no exact validation fields extracted.
7. Invoke only the owning control for `POST finance/quotes/{quote}/convert-to-invoice` (`finance.quotes.convert-to-invoice`, action `convertToInvoice`). Source category: **mutation outcome source gap (convertToInvoice)**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:282-355`; no exact validation fields extracted.
8. Invoke only the owning control for `POST finance/quotes/{quote}/send` (`finance.quotes.send`, action `send`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/QuoteController.php:208-223`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0661` at `app/Domain/Finance/Http/Controllers/QuoteController.php:16`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0662` at `app/Domain/Finance/Http/Controllers/QuoteController.php:126`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0663` at `app/Domain/Finance/Http/Controllers/QuoteController.php:111`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0664` at `app/Domain/Finance/Http/Controllers/QuoteController.php:186`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `accept` / `ROUTE-0665` at `app/Domain/Finance/Http/Controllers/QuoteController.php:225`; it is not runtime-observed.
- **mutation outcome source gap (convertToAgreement)** is applicable only to `convertToAgreement` / `ROUTE-0666` at `app/Domain/Finance/Http/Controllers/QuoteController.php:242`; it is not runtime-observed.
- **mutation outcome source gap (convertToInvoice)** is applicable only to `convertToInvoice` / `ROUTE-0667` at `app/Domain/Finance/Http/Controllers/QuoteController.php:282`; it is not runtime-observed.
- **created/recorded** is applicable only to `send` / `ROUTE-0669` at `app/Domain/Finance/Http/Controllers/QuoteController.php:208`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/quotes/Index.tsx`, `resources/js/pages/finance/quotes/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0662` / `store`: fields `client_id`; success app/Domain/Finance/Http/Controllers/QuoteController.php:183 `return redirect()->back()->with('success', 'Quote created.');`.
- `ROUTE-0664` / `update`: fields `client_id`; success app/Domain/Finance/Http/Controllers/QuoteController.php:205 `return redirect()->back()->with('success', 'Quote updated.');`.
- `ROUTE-0665` / `accept`: success app/Domain/Finance/Http/Controllers/QuoteController.php:239 `return redirect()->back()->with('success', 'Quote accepted.');`.
- `ROUTE-0666` / `convertToAgreement`: success app/Domain/Finance/Http/Controllers/QuoteController.php:274 `return redirect()->back()->with('success', 'Quote converted to service agreement.');`.
- `ROUTE-0667` / `convertToInvoice`: success app/Domain/Finance/Http/Controllers/QuoteController.php:294 `->with('success', 'Quote already converted to an invoice.');`; app/Domain/Finance/Http/Controllers/QuoteController.php:354 `->with('success', "Quote converted to invoice {$invoice->invoice_number}.");`.
- `ROUTE-0669` / `send`: success app/Domain/Finance/Http/Controllers/QuoteController.php:222 `return redirect()->back()->with('success', 'Quote sent.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/QuoteController.php:149 `$quote = Quote::create([`; app/Domain/Finance/Http/Controllers/QuoteController.php:165 `$quote->lineItems()->create([`; app/Domain/Finance/Http/Controllers/QuoteController.php:177 `$quote->update([`; app/Domain/Finance/Http/Controllers/QuoteController.php:203 `$quote->update($data);`; app/Domain/Finance/Http/Controllers/QuoteController.php:234 `$quote->update([`; app/Domain/Finance/Http/Controllers/QuoteController.php:252 `$agreement = ServiceAgreement::create([`; app/Domain/Finance/Http/Controllers/QuoteController.php:261 `$agreement->lineItems()->create([`; app/Domain/Finance/Http/Controllers/QuoteController.php:269 `$quote->update([`; app/Domain/Finance/Http/Controllers/QuoteController.php:319 `$invoice = FinInvoice::create([`; app/Domain/Finance/Http/Controllers/QuoteController.php:342 `$invoice->lines()->create($line);`; app/Domain/Finance/Http/Controllers/QuoteController.php:345 `$quote->update([`; app/Domain/Finance/Http/Controllers/QuoteController.php:217 `$quote->update([`; responses app/Domain/Finance/Http/Controllers/QuoteController.php:69 `return inertia('finance/quotes/Index', [`; app/Domain/Finance/Http/Controllers/QuoteController.php:183 `return redirect()->back()->with('success', 'Quote created.');`; app/Domain/Finance/Http/Controllers/QuoteController.php:121 `return inertia('finance/quotes/Show', [`; app/Domain/Finance/Http/Controllers/QuoteController.php:205 `return redirect()->back()->with('success', 'Quote updated.');`; app/Domain/Finance/Http/Controllers/QuoteController.php:239 `return redirect()->back()->with('success', 'Quote accepted.');`; app/Domain/Finance/Http/Controllers/QuoteController.php:274 `return redirect()->back()->with('success', 'Quote converted to service agreement.');`; app/Domain/Finance/Http/Controllers/QuoteController.php:293 `return redirect()->route('finance.invoices.show', $quote->converted_to_invoice_id)`; app/Domain/Finance/Http/Controllers/QuoteController.php:350 `return $invoice;`; app/Domain/Finance/Http/Controllers/QuoteController.php:353 `return redirect()->route('finance.invoices.show', $invoice)`; app/Domain/Finance/Http/Controllers/QuoteController.php:222 `return redirect()->back()->with('success', 'Quote sent.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/quotes` — `finance.quotes.index` — `App\Domain\Finance\Http\Controllers\QuoteController@index` — `app/Domain/Finance/Http/Controllers/QuoteController.php:16` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/quotes` — `finance.quotes.store` — `App\Domain\Finance\Http\Controllers\QuoteController@store` — `app/Domain/Finance/Http/Controllers/QuoteController.php:126` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/quotes/{quote}` — `finance.quotes.show` — `App\Domain\Finance\Http\Controllers\QuoteController@show` — `app/Domain/Finance/Http/Controllers/QuoteController.php:111` — middleware `web, auth, permission:finance.ar.view`
- `PUT finance/quotes/{quote}` — `finance.quotes.update` — `App\Domain\Finance\Http\Controllers\QuoteController@update` — `app/Domain/Finance/Http/Controllers/QuoteController.php:186` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/quotes/{quote}/accept` — `finance.quotes.accept` — `App\Domain\Finance\Http\Controllers\QuoteController@accept` — `app/Domain/Finance/Http/Controllers/QuoteController.php:225` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/quotes/{quote}/convert` — `finance.quotes.convert` — `App\Domain\Finance\Http\Controllers\QuoteController@convertToAgreement` — `app/Domain/Finance/Http/Controllers/QuoteController.php:242` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/quotes/{quote}/convert-to-invoice` — `finance.quotes.convert-to-invoice` — `App\Domain\Finance\Http\Controllers\QuoteController@convertToInvoice` — `app/Domain/Finance/Http/Controllers/QuoteController.php:282` — middleware `web, auth, permission:finance.ar.manage`
- `POST finance/quotes/{quote}/send` — `finance.quotes.send` — `App\Domain\Finance\Http\Controllers\QuoteController@send` — `app/Domain/Finance/Http/Controllers/QuoteController.php:208` — middleware `web, auth, permission:finance.ar.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/QuoteController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/quotes/Index.tsx`, `resources/js/pages/finance/quotes/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
