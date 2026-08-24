# FIN-PAYMENT-MATCH: Payment Match

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-PAYMENT-MATCH`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/payment-matching` (`finance.payment-matching.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.view`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/payment-matching` (`finance.payment-matching.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/payment-matching/{match}/confirm` (`finance.payment-matching.confirm`, action `confirm`). Source category: **mutation outcome source gap (confirm)**; controller `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:149-155`; no exact validation fields extracted.
3. Invoke only the owning control for `POST finance/payment-matching/{match}/reject` (`finance.payment-matching.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:160-166`; no exact validation fields extracted.
4. Invoke only the owning control for `POST finance/payment-matching/match-all` (`finance.payment-matching.match-all`, action `matchAll`). Source category: **mutation outcome source gap (matchAll)**; controller `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:130-144`; no exact validation fields extracted.
5. Invoke only the owning control for `POST finance/payment-matching/suggest/{transaction}` (`finance.payment-matching.suggest`, action `suggest`). Source category: **mutation outcome source gap (suggest)**; controller `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:94-125`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0627` at `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:22`; it is not runtime-observed.
- **mutation outcome source gap (confirm)** is applicable only to `confirm` / `ROUTE-0628` at `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:149`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-0629` at `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:160`; it is not runtime-observed.
- **mutation outcome source gap (matchAll)** is applicable only to `matchAll` / `ROUTE-0630` at `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:130`; it is not runtime-observed.
- **mutation outcome source gap (suggest)** is applicable only to `suggest` / `ROUTE-0631` at `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:94`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/payment-matching/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0628` / `confirm`: success app/Domain/Finance/Http/Controllers/PaymentMatchController.php:154 `->with('success', 'Payment match confirmed.');`.
- `ROUTE-0629` / `reject`: success app/Domain/Finance/Http/Controllers/PaymentMatchController.php:165 `->with('success', 'Payment match rejected.');`.
- `ROUTE-0630` / `matchAll`: success app/Domain/Finance/Http/Controllers/PaymentMatchController.php:143 `return redirect()->back()->with('success', $message . '.');`.
- `ROUTE-0631` / `suggest`: success app/Domain/Finance/Http/Controllers/PaymentMatchController.php:124 `->with('success', "{$created} potential match(es) found for transaction.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/PaymentMatchController.php:110 `FinPaymentMatch::create([`; responses app/Domain/Finance/Http/Controllers/PaymentMatchController.php:62 `return [`; app/Domain/Finance/Http/Controllers/PaymentMatchController.php:82 `return Inertia::render('finance/payment-matching/Index', [`; app/Domain/Finance/Http/Controllers/PaymentMatchController.php:153 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/PaymentMatchController.php:164 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/PaymentMatchController.php:143 `return redirect()->back()->with('success', $message . '.');`; app/Domain/Finance/Http/Controllers/PaymentMatchController.php:123 `return redirect()->back()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/payment-matching` — `finance.payment-matching.index` — `App\Domain\Finance\Http\Controllers\PaymentMatchController@index` — `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:22` — middleware `web, auth, permission:finance.bank.view`
- `POST finance/payment-matching/{match}/confirm` — `finance.payment-matching.confirm` — `App\Domain\Finance\Http\Controllers\PaymentMatchController@confirm` — `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:149` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/payment-matching/{match}/reject` — `finance.payment-matching.reject` — `App\Domain\Finance\Http\Controllers\PaymentMatchController@reject` — `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:160` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/payment-matching/match-all` — `finance.payment-matching.match-all` — `App\Domain\Finance\Http\Controllers\PaymentMatchController@matchAll` — `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:130` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/payment-matching/suggest/{transaction}` — `finance.payment-matching.suggest` — `App\Domain\Finance\Http\Controllers\PaymentMatchController@suggest` — `app/Domain/Finance/Http/Controllers/PaymentMatchController.php:94` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/PaymentMatchController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/payment-matching/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
