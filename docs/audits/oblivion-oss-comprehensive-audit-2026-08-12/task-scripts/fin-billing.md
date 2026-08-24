# FIN-BILLING: Billing

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`
- Owning module: Finance and funding
- Legacy family: `FIN-BILLING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/billing` (`finance.billing.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/billing` (`finance.billing.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/billing/entries` (`finance.billing.entries`, action `entries`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/BillingController.php:82-115`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0498` at `app/Domain/Finance/Http/Controllers/BillingController.php:12`; it is not runtime-observed.
- **information presented** is applicable only to `entries` / `ROUTE-0499` at `app/Domain/Finance/Http/Controllers/BillingController.php:82`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/billing/Entries.tsx`, `resources/js/pages/finance/billing/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0499` / `entries`: fields `client_id`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/billing` — `finance.billing.index` — `App\Domain\Finance\Http\Controllers\BillingController@index` — `app/Domain/Finance/Http/Controllers/BillingController.php:12` — middleware `web, auth, permission:finance.ar.view`
- `GET|HEAD finance/billing/entries` — `finance.billing.entries` — `App\Domain\Finance\Http\Controllers\BillingController@entries` — `app/Domain/Finance/Http/Controllers/BillingController.php:82` — middleware `web, auth, permission:finance.ar.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/BillingController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/billing/Entries.tsx`, `resources/js/pages/finance/billing/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
