# FIN-ACCOUNTS-RECEIVABLE: Accounts Receivable

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-ACCOUNTS-RECEIVABLE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/receivables` (`finance.receivables.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/receivables` (`finance.receivables.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/receivables/aging` (`finance.receivables.aging`, action `aging`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:63-72`.
3. Use `GET|HEAD finance/receivables/statements` (`finance.receivables.statements`, action `statements`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:77-111`.
4. Invoke only the owning control for `POST finance/receivables/allocate` (`finance.receivables.allocate`, action `allocate`). Source category: **mutation outcome source gap (allocate)**; controller `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:116-134`; `invoice_id`, `amount`, `payment_date`, `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0671` at `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:21`; it is not runtime-observed.
- **information presented** is applicable only to `aging` / `ROUTE-0672` at `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:63`; it is not runtime-observed.
- **mutation outcome source gap (allocate)** is applicable only to `allocate` / `ROUTE-0673` at `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:116`; it is not runtime-observed.
- **information presented** is applicable only to `statements` / `ROUTE-0674` at `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:77`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/receivables/Aging.tsx`, `resources/js/pages/finance/receivables/Index.tsx`, `resources/js/pages/finance/receivables/Statements.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0673` / `allocate`: fields `invoice_id`, `amount`, `payment_date`, `notes`; success app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:133 `return redirect()->back()->with('success', 'Payment allocated successfully.');`; failure app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:130 `return back()->withErrors(['amount' => $e->getMessage()]);`.

## Failure and recovery paths

- `allocate`: app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:130 `return back()->withErrors(['amount' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:50 `return Inertia::render('finance/receivables/Index', [`; app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:68 `return Inertia::render('finance/receivables/Aging', [`; app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:130 `return back()->withErrors(['amount' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:133 `return redirect()->back()->with('success', 'Payment allocated successfully.');`; app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:103 `return Inertia::render('finance/receivables/Statements', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/receivables` — `finance.receivables.index` — `App\Domain\Finance\Http\Controllers\AccountsReceivableController@index` — `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:21` — middleware `web, auth, permission:finance.ar.view`
- `GET|HEAD finance/receivables/aging` — `finance.receivables.aging` — `App\Domain\Finance\Http\Controllers\AccountsReceivableController@aging` — `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:63` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/receivables/allocate` — `finance.receivables.allocate` — `App\Domain\Finance\Http\Controllers\AccountsReceivableController@allocate` — `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:116` — middleware `web, auth, permission:finance.ar.manage`
- `GET|HEAD finance/receivables/statements` — `finance.receivables.statements` — `App\Domain\Finance\Http\Controllers\AccountsReceivableController@statements` — `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php:77` — middleware `web, auth, permission:finance.ar.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/AccountsReceivableController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/receivables/Aging.tsx`, `resources/js/pages/finance/receivables/Index.tsx`, `resources/js/pages/finance/receivables/Statements.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
