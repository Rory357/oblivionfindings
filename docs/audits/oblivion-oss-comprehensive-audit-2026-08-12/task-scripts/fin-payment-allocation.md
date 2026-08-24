# FIN-PAYMENT-ALLOCATION: Payment Allocation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PAYMENT-ALLOCATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/payment-allocations` (`finance.payment-allocations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/payment-allocations` (`finance.payment-allocations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/payment-allocations` (`finance.payment-allocations.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:50-87`; `type`, `amount`, `payment_date`, `allocatable_type`, `allocatable_id`, `notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0625` at `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:19`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0626` at `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:50`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/payment-allocations/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0626` / `store`: fields `type`, `amount`, `payment_date`, `allocatable_type`, `allocatable_id`, `notes`; success app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:86 `return back()->with('success', 'Payment allocation recorded successfully.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:68 `$allocation = FinPaymentAllocation::create([`; responses app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:42 `return Inertia::render('finance/payment-allocations/Index', [`; app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:86 `return back()->with('success', 'Payment allocation recorded successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/payment-allocations` — `finance.payment-allocations.index` — `App\Domain\Finance\Http\Controllers\PaymentAllocationController@index` — `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:19` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/payment-allocations` — `finance.payment-allocations.store` — `App\Domain\Finance\Http\Controllers\PaymentAllocationController@store` — `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php:50` — middleware `web, auth, permission:finance.ar.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PaymentAllocationController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/payment-allocations/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
