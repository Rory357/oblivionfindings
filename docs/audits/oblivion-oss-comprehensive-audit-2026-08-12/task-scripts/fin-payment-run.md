# FIN-PAYMENT-RUN: Payment Run

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PAYMENT-RUN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/payment-runs` (`finance.payment-runs.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/payment-runs` (`finance.payment-runs.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/payment-runs/{paymentRun}` (`finance.payment-runs.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PaymentRunController.php:108-167`.
3. Use `GET|HEAD finance/payment-runs/{paymentRun}/download` (`finance.payment-runs.download`, action `download`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Finance/Http/Controllers/PaymentRunController.php:200-215`.
4. Use `GET|HEAD finance/payment-runs/create` (`finance.payment-runs.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/PaymentRunController.php:55-83`.
5. Invoke only the owning control for `POST finance/payment-runs` (`finance.payment-runs.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PaymentRunController.php:85-106`; `bank_account_id`, `payment_date`, `bill_ids`, `notes`.
6. Invoke only the owning control for `POST finance/payment-runs/{paymentRun}/process` (`finance.payment-runs.process`, action `process`). Source category: **mutation outcome source gap (process)**; controller `app/Domain/Finance/Http/Controllers/PaymentRunController.php:185-198`; no exact validation fields extracted.
7. Invoke only the owning control for `POST finance/payment-runs/{paymentRunId}/approve` (`finance.payment-runs.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Finance/Http/Controllers/PaymentRunController.php:169-183`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0632` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:18`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0633` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:85`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0634` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:108`; it is not runtime-observed.
- **file/report delivered** is applicable only to `download` / `ROUTE-0635` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:200`; it is not runtime-observed.
- **mutation outcome source gap (process)** is applicable only to `process` / `ROUTE-0636` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:185`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0637` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:169`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0638` at `app/Domain/Finance/Http/Controllers/PaymentRunController.php:55`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/payment-runs/Create.tsx`, `resources/js/pages/finance/payment-runs/Index.tsx`, `resources/js/pages/finance/payment-runs/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0633` / `store`: fields `bank_account_id`, `payment_date`, `bill_ids`, `notes`; success app/Domain/Finance/Http/Controllers/PaymentRunController.php:105 `->with('success', 'Payment run created successfully.');`; failure app/Domain/Finance/Http/Controllers/PaymentRunController.php:101 `return back()->withErrors(['bill_ids' => $e->getMessage()]);`.
- `ROUTE-0635` / `download`: failure app/Domain/Finance/Http/Controllers/PaymentRunController.php:203 `return back()->withErrors(['payment_run' => 'No bank file available for this payment run.']);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:209 `return back()->withErrors(['payment_run' => 'Bank file not found on disk.']);`.
- `ROUTE-0636` / `process`: success app/Domain/Finance/Http/Controllers/PaymentRunController.php:197 `return back()->with('success', 'Payment run processed successfully.');`; failure app/Domain/Finance/Http/Controllers/PaymentRunController.php:192 `return back()->withErrors(['payment_run' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:194 `return back()->withErrors(['payment_run' => 'An error occurred while processing the payment run. Please try again.']);`.
- `ROUTE-0637` / `approve`: success app/Domain/Finance/Http/Controllers/PaymentRunController.php:182 `return back()->with('success', 'Payment run approved successfully.');`; failure app/Domain/Finance/Http/Controllers/PaymentRunController.php:179 `return back()->withErrors(['payment_run' => $e->getMessage()]);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/PaymentRunController.php:101 `return back()->withErrors(['bill_ids' => $e->getMessage()]);`.
- `download`: app/Domain/Finance/Http/Controllers/PaymentRunController.php:203 `return back()->withErrors(['payment_run' => 'No bank file available for this payment run.']);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:209 `return back()->withErrors(['payment_run' => 'Bank file not found on disk.']);`.
- `process`: app/Domain/Finance/Http/Controllers/PaymentRunController.php:192 `return back()->withErrors(['payment_run' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:194 `return back()->withErrors(['payment_run' => 'An error occurred while processing the payment run. Please try again.']);`.
- `approve`: app/Domain/Finance/Http/Controllers/PaymentRunController.php:179 `return back()->withErrors(['payment_run' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/PaymentRunController.php:47 `return Inertia::render('finance/payment-runs/Index', [`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:101 `return back()->withErrors(['bill_ids' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:104 `return redirect()->route('finance.payment-runs.show', $run->id)`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:119 `return Inertia::render('finance/payment-runs/Show', [`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:203 `return back()->withErrors(['payment_run' => 'No bank file available for this payment run.']);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:209 `return back()->withErrors(['payment_run' => 'Bank file not found on disk.']);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:212 `return response()->download($fullPath, $paymentRun->run_number.'.csv', [`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:192 `return back()->withErrors(['payment_run' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:194 `return back()->withErrors(['payment_run' => 'An error occurred while processing the payment run. Please try again.']);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:197 `return back()->with('success', 'Payment run processed successfully.');`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:179 `return back()->withErrors(['payment_run' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:182 `return back()->with('success', 'Payment run approved successfully.');`; app/Domain/Finance/Http/Controllers/PaymentRunController.php:79 `return Inertia::render('finance/payment-runs/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/payment-runs` — `finance.payment-runs.index` — `App\Domain\Finance\Http\Controllers\PaymentRunController@index` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:18` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/payment-runs` — `finance.payment-runs.store` — `App\Domain\Finance\Http\Controllers\PaymentRunController@store` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:85` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/payment-runs/{paymentRun}` — `finance.payment-runs.show` — `App\Domain\Finance\Http\Controllers\PaymentRunController@show` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:108` — middleware `web, auth, permission:finance.ap.view`
- `GET|HEAD finance/payment-runs/{paymentRun}/download` — `finance.payment-runs.download` — `App\Domain\Finance\Http\Controllers\PaymentRunController@download` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:200` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/payment-runs/{paymentRun}/process` — `finance.payment-runs.process` — `App\Domain\Finance\Http\Controllers\PaymentRunController@process` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:185` — middleware `web, auth, permission:finance.ap.manage`
- `POST finance/payment-runs/{paymentRunId}/approve` — `finance.payment-runs.approve` — `App\Domain\Finance\Http\Controllers\PaymentRunController@approve` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:169` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/payment-runs/create` — `finance.payment-runs.create` — `App\Domain\Finance\Http\Controllers\PaymentRunController@create` — `app/Domain/Finance/Http/Controllers/PaymentRunController.php:55` — middleware `web, auth, permission:finance.ap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PaymentRunController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/payment-runs/Create.tsx`, `resources/js/pages/finance/payment-runs/Index.tsx`, `resources/js/pages/finance/payment-runs/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
