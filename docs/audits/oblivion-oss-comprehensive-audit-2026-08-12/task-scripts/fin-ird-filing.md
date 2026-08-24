# FIN-IRD-FILING: Ird Filing

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.tax.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-IRD-FILING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/ird-filings` (`finance.ird-filings.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.tax.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.tax.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/ird-filings` (`finance.ird-filings.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/ird-filings/{filing}` (`finance.ird-filings.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/IrdFilingController.php:124-134`.
3. Invoke only the owning control for `POST finance/ird-filings/{filing}/submit` (`finance.ird-filings.submit`, action `submit`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/IrdFilingController.php:155-176`; no exact validation fields extracted.
4. Invoke only the owning control for `POST finance/ird-filings/{filing}/validate` (`finance.ird-filings.validate`, action `validateFiling`). Source category: **mutation outcome source gap (validateFiling)**; controller `app/Domain/Finance/Http/Controllers/IrdFilingController.php:139-150`; no exact validation fields extracted.
5. Invoke only the owning control for `POST finance/ird-filings/from-gst/{gstReturn}` (`finance.ird-filings.from-gst`, action `createFromGst`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/IrdFilingController.php:76-92`; `ird_number`.
6. Invoke only the owning control for `POST finance/ird-filings/from-payroll/{run}` (`finance.ird-filings.from-payroll`, action `createFromPayrollRun`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/IrdFilingController.php:97-119`; `ird_number`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0607` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:22`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0608` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:124`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-0609` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:155`; it is not runtime-observed.
- **mutation outcome source gap (validateFiling)** is applicable only to `validateFiling` / `ROUTE-0610` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:139`; it is not runtime-observed.
- **created/recorded** is applicable only to `createFromGst` / `ROUTE-0611` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:76`; it is not runtime-observed.
- **created/recorded** is applicable only to `createFromPayrollRun` / `ROUTE-0612` at `app/Domain/Finance/Http/Controllers/IrdFilingController.php:97`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/IrdFilings/Index.tsx`, `resources/js/pages/finance/IrdFilings/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0609` / `submit`: failure app/Domain/Finance/Http/Controllers/IrdFilingController.php:162 `->withErrors(['submission' => $filing->error_message]);`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:174 `->withErrors(['status' => $e->getMessage()]);`.
- `ROUTE-0610` / `validateFiling`: success app/Domain/Finance/Http/Controllers/IrdFilingController.php:149 `->with('success', 'Filing validated successfully. Ready for submission.');`; failure app/Domain/Finance/Http/Controllers/IrdFilingController.php:145 `->withErrors(['validation' => $errors]);`.
- `ROUTE-0611` / `createFromGst`: fields `ird_number`; success app/Domain/Finance/Http/Controllers/IrdFilingController.php:91 `->with('success', 'IRD filing created from GST return.');`.
- `ROUTE-0612` / `createFromPayrollRun`: fields `ird_number`; success app/Domain/Finance/Http/Controllers/IrdFilingController.php:118 `->with('success', 'Payday filing created from payroll run.');`.

## Failure and recovery paths

- `submit`: app/Domain/Finance/Http/Controllers/IrdFilingController.php:162 `->withErrors(['submission' => $filing->error_message]);`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:174 `->withErrors(['status' => $e->getMessage()]);`.
- `validateFiling`: app/Domain/Finance/Http/Controllers/IrdFilingController.php:145 `->withErrors(['validation' => $errors]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/IrdFilingController.php:65 `return Inertia::render('finance/IrdFilings/Index', [`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:131 `return Inertia::render('finance/IrdFilings/Show', [`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:161 `return redirect()->route('finance.ird-filings.show', $filing)`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:170 `return redirect()->route('finance.ird-filings.show', $filing)`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:173 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:144 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:148 `return redirect()->route('finance.ird-filings.show', $filing)`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:90 `return redirect()->route('finance.ird-filings.show', $filing)`; app/Domain/Finance/Http/Controllers/IrdFilingController.php:117 `return redirect()->route('finance.ird-filings.show', $filing)`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/ird-filings` — `finance.ird-filings.index` — `App\Domain\Finance\Http\Controllers\IrdFilingController@index` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:22` — middleware `web, auth, permission:finance.tax.manage`
- `GET|HEAD finance/ird-filings/{filing}` — `finance.ird-filings.show` — `App\Domain\Finance\Http\Controllers\IrdFilingController@show` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:124` — middleware `web, auth, permission:finance.tax.manage`
- `POST finance/ird-filings/{filing}/submit` — `finance.ird-filings.submit` — `App\Domain\Finance\Http\Controllers\IrdFilingController@submit` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:155` — middleware `web, auth, permission:finance.tax.manage`
- `POST finance/ird-filings/{filing}/validate` — `finance.ird-filings.validate` — `App\Domain\Finance\Http\Controllers\IrdFilingController@validateFiling` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:139` — middleware `web, auth, permission:finance.tax.manage`
- `POST finance/ird-filings/from-gst/{gstReturn}` — `finance.ird-filings.from-gst` — `App\Domain\Finance\Http\Controllers\IrdFilingController@createFromGst` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:76` — middleware `web, auth, permission:finance.tax.manage`
- `POST finance/ird-filings/from-payroll/{run}` — `finance.ird-filings.from-payroll` — `App\Domain\Finance\Http\Controllers\IrdFilingController@createFromPayrollRun` — `app/Domain/Finance/Http/Controllers/IrdFilingController.php:97` — middleware `web, auth, permission:finance.tax.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/IrdFilingController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/IrdFilings/Index.tsx`, `resources/js/pages/finance/IrdFilings/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
