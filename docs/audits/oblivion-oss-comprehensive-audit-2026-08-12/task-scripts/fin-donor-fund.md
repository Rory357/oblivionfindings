# FIN-DONOR-FUND: Donor Fund

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-DONOR-FUND`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/donor-funds` (`finance.donor-funds.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.reports.view`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.reports.view`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/donor-funds` (`finance.donor-funds.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/donor-funds/{fund}` (`finance.donor-funds.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/DonorFundController.php:124-210`.
3. Use `GET|HEAD finance/donor-funds/{fund}/reports` (`finance.donor-funds.reports`, action `reports`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Finance/Http/Controllers/DonorFundController.php:311-314`.
4. Use `GET|HEAD finance/donor-funds/{fund}/reports/{report}/download` (`finance.donor-funds.reports.download`, action `downloadReport`) only from its authorised surface; inspect the returned file/report. Source: `app/Domain/Finance/Http/Controllers/DonorFundController.php:316-326`.
5. Invoke only the owning control for `POST finance/donor-funds` (`finance.donor-funds.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/DonorFundController.php:89-119`; `fund_code`, `fund_name`, `donor_name`, `donor_contact`, `fund_type`, `gl_account_id`, `funding_stream_id`, `budget_amount`, `start_date`, `end_date`, `restrictions`, `reporting_requirements`, `next_report_due`, `is_restricted`.
6. Invoke only the owning control for `PUT finance/donor-funds/{fund}` (`finance.donor-funds.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/DonorFundController.php:215-238`; `fund_name`, `donor_name`, `donor_contact`, `fund_type`, `gl_account_id`, `funding_stream_id`, `budget_amount`, `start_date`, `end_date`, `restrictions`, `reporting_requirements`, `next_report_due`, `is_restricted`.
7. Invoke only the owning control for `POST finance/donor-funds/{fund}/expenditure` (`finance.donor-funds.expenditure`, action `expenditure`). Source category: **mutation outcome source gap (expenditure)**; controller `app/Domain/Finance/Http/Controllers/DonorFundController.php:266-285`; `transaction_date`, `description`, `amount`, `reference`, `expense_account_id`, `bill_id`.
8. Invoke only the owning control for `POST finance/donor-funds/{fund}/receipt` (`finance.donor-funds.receipt`, action `receipt`). Source category: **mutation outcome source gap (receipt)**; controller `app/Domain/Finance/Http/Controllers/DonorFundController.php:243-261`; `transaction_date`, `description`, `amount`, `reference`, `bank_account_id`.
9. Invoke only the owning control for `POST finance/donor-funds/{fund}/report` (`finance.donor-funds.report`, action `report`). Source category: **mutation outcome source gap (report)**; controller `app/Domain/Finance/Http/Controllers/DonorFundController.php:290-306`; `period_from`, `period_to`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0542` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0543` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:89`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0544` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:124`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0545` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:215`; it is not runtime-observed.
- **mutation outcome source gap (expenditure)** is applicable only to `expenditure` / `ROUTE-0546` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:266`; it is not runtime-observed.
- **mutation outcome source gap (receipt)** is applicable only to `receipt` / `ROUTE-0547` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:243`; it is not runtime-observed.
- **mutation outcome source gap (report)** is applicable only to `report` / `ROUTE-0548` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:290`; it is not runtime-observed.
- **file/report delivered** is applicable only to `reports` / `ROUTE-0549` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:311`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadReport` / `ROUTE-0550` at `app/Domain/Finance/Http/Controllers/DonorFundController.php:316`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/donor-funds/Index.tsx`, `resources/js/pages/finance/donor-funds/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0543` / `store`: fields `fund_code`, `fund_name`, `donor_name`, `donor_contact`, `fund_type`, `gl_account_id`, `funding_stream_id`, `budget_amount`, `start_date`, `end_date`, `restrictions`, `reporting_requirements`, `next_report_due`, `is_restricted`; success app/Domain/Finance/Http/Controllers/DonorFundController.php:118 `->with('success', 'Donor fund created successfully.');`.
- `ROUTE-0545` / `update`: fields `fund_name`, `donor_name`, `donor_contact`, `fund_type`, `gl_account_id`, `funding_stream_id`, `budget_amount`, `start_date`, `end_date`, `restrictions`, `reporting_requirements`, `next_report_due`, `is_restricted`; success app/Domain/Finance/Http/Controllers/DonorFundController.php:237 `->with('success', 'Fund updated successfully.');`.
- `ROUTE-0546` / `expenditure`: fields `transaction_date`, `description`, `amount`, `reference`, `expense_account_id`, `bill_id`; success app/Domain/Finance/Http/Controllers/DonorFundController.php:284 `->with('success', 'Expenditure recorded successfully.');`; failure app/Domain/Finance/Http/Controllers/DonorFundController.php:280 `return back()->withErrors(['expenditure' => $e->getMessage()]);`.
- `ROUTE-0547` / `receipt`: fields `transaction_date`, `description`, `amount`, `reference`, `bank_account_id`; success app/Domain/Finance/Http/Controllers/DonorFundController.php:260 `->with('success', 'Receipt recorded successfully.');`; failure app/Domain/Finance/Http/Controllers/DonorFundController.php:256 `return back()->withErrors(['receipt' => $e->getMessage()]);`.
- `ROUTE-0548` / `report`: fields `period_from`, `period_to`; success app/Domain/Finance/Http/Controllers/DonorFundController.php:305 `->with('success', 'Report generated successfully.');`; failure app/Domain/Finance/Http/Controllers/DonorFundController.php:301 `return back()->withErrors(['report' => $e->getMessage()]);`.

## Failure and recovery paths

- `expenditure`: app/Domain/Finance/Http/Controllers/DonorFundController.php:280 `return back()->withErrors(['expenditure' => $e->getMessage()]);`.
- `receipt`: app/Domain/Finance/Http/Controllers/DonorFundController.php:256 `return back()->withErrors(['receipt' => $e->getMessage()]);`.
- `report`: app/Domain/Finance/Http/Controllers/DonorFundController.php:301 `return back()->withErrors(['report' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/DonorFundController.php:110 `$fund = FinDonorFund::create([`; app/Domain/Finance/Http/Controllers/DonorFundController.php:234 `$fund->update($validated);`; responses app/Domain/Finance/Http/Controllers/DonorFundController.php:58 `return Inertia::render('finance/donor-funds/Index', [`; app/Domain/Finance/Http/Controllers/DonorFundController.php:117 `return redirect()->route('finance.donor-funds.show', $fund)`; app/Domain/Finance/Http/Controllers/DonorFundController.php:177 `return Inertia::render('finance/donor-funds/Show', [`; app/Domain/Finance/Http/Controllers/DonorFundController.php:236 `return redirect()->route('finance.donor-funds.show', $fund)`; app/Domain/Finance/Http/Controllers/DonorFundController.php:280 `return back()->withErrors(['expenditure' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/DonorFundController.php:283 `return redirect()->route('finance.donor-funds.show', $fund)`; app/Domain/Finance/Http/Controllers/DonorFundController.php:256 `return back()->withErrors(['receipt' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/DonorFundController.php:259 `return redirect()->route('finance.donor-funds.show', $fund)`; app/Domain/Finance/Http/Controllers/DonorFundController.php:301 `return back()->withErrors(['report' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/DonorFundController.php:304 `return redirect()->route('finance.donor-funds.show', $fund)`; app/Domain/Finance/Http/Controllers/DonorFundController.php:313 `return $this->show($request, $fund);`; app/Domain/Finance/Http/Controllers/DonorFundController.php:322 `return Storage::disk('local')->download(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/donor-funds` — `finance.donor-funds.index` — `App\Domain\Finance\Http\Controllers\DonorFundController@index` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:25` — middleware `web, auth, permission:finance.reports.view`
- `POST finance/donor-funds` — `finance.donor-funds.store` — `App\Domain\Finance\Http\Controllers\DonorFundController@store` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:89` — middleware `web, auth, permission:finance.admin`
- `GET|HEAD finance/donor-funds/{fund}` — `finance.donor-funds.show` — `App\Domain\Finance\Http\Controllers\DonorFundController@show` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:124` — middleware `web, auth, permission:finance.reports.view`
- `PUT finance/donor-funds/{fund}` — `finance.donor-funds.update` — `App\Domain\Finance\Http\Controllers\DonorFundController@update` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:215` — middleware `web, auth, permission:finance.admin`
- `POST finance/donor-funds/{fund}/expenditure` — `finance.donor-funds.expenditure` — `App\Domain\Finance\Http\Controllers\DonorFundController@expenditure` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:266` — middleware `web, auth, permission:finance.admin`
- `POST finance/donor-funds/{fund}/receipt` — `finance.donor-funds.receipt` — `App\Domain\Finance\Http\Controllers\DonorFundController@receipt` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:243` — middleware `web, auth, permission:finance.admin`
- `POST finance/donor-funds/{fund}/report` — `finance.donor-funds.report` — `App\Domain\Finance\Http\Controllers\DonorFundController@report` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:290` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/donor-funds/{fund}/reports` — `finance.donor-funds.reports` — `App\Domain\Finance\Http\Controllers\DonorFundController@reports` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:311` — middleware `web, auth, permission:finance.reports.view`
- `GET|HEAD finance/donor-funds/{fund}/reports/{report}/download` — `finance.donor-funds.reports.download` — `App\Domain\Finance\Http\Controllers\DonorFundController@downloadReport` — `app/Domain/Finance/Http/Controllers/DonorFundController.php:316` — middleware `web, auth, permission:finance.reports.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/DonorFundController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/donor-funds/Index.tsx`, `resources/js/pages/finance/donor-funds/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
