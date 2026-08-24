# FIN-RECURRING-CHARGE: Recurring Charge

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-RECURRING-CHARGE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/recurring-charges` (`finance.recurring_charges.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ar.view`, `permission:finance.ar.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/recurring-charges` (`finance.recurring_charges.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/recurring-charges` (`finance.recurring_charges.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:92-124`; `client_id`.
3. Invoke only the owning control for `DELETE finance/recurring-charges/{charge}` (`finance.recurring_charges.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:158-169`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT finance/recurring-charges/{charge}` (`finance.recurring_charges.update`, action `update`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:126-156`; `client_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0675` at `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:13`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0676` at `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:92`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0677` at `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:158`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0678` at `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:126`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/recurring-charges/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0675` / `index`: fields `q`.
- `ROUTE-0676` / `store`: fields `client_id`; success app/Domain/Finance/Http/Controllers/RecurringChargeController.php:123 `return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge created.');`.
- `ROUTE-0677` / `destroy`: success app/Domain/Finance/Http/Controllers/RecurringChargeController.php:168 `return redirect()->back()->with('success', 'Recurring charge deleted.');`.
- `ROUTE-0678` / `update`: fields `client_id`; success app/Domain/Finance/Http/Controllers/RecurringChargeController.php:155 `return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/RecurringChargeController.php:106 `RecurringCharge::create([`; app/Domain/Finance/Http/Controllers/RecurringChargeController.php:166 `$charge->delete();`; app/Domain/Finance/Http/Controllers/RecurringChargeController.php:153 `$charge->update($data);`; responses app/Domain/Finance/Http/Controllers/RecurringChargeController.php:68 `return inertia('finance/recurring-charges/Index', [`; app/Domain/Finance/Http/Controllers/RecurringChargeController.php:123 `return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge created.');`; app/Domain/Finance/Http/Controllers/RecurringChargeController.php:168 `return redirect()->back()->with('success', 'Recurring charge deleted.');`; app/Domain/Finance/Http/Controllers/RecurringChargeController.php:155 `return redirect()->route('finance.recurring_charges.index')->with('success', 'Recurring charge updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/recurring-charges` — `finance.recurring_charges.index` — `App\Domain\Finance\Http\Controllers\RecurringChargeController@index` — `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:13` — middleware `web, auth, permission:finance.ar.view`
- `POST finance/recurring-charges` — `finance.recurring_charges.store` — `App\Domain\Finance\Http\Controllers\RecurringChargeController@store` — `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:92` — middleware `web, auth, permission:finance.ar.manage`
- `DELETE finance/recurring-charges/{charge}` — `finance.recurring_charges.destroy` — `App\Domain\Finance\Http\Controllers\RecurringChargeController@destroy` — `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:158` — middleware `web, auth, permission:finance.ar.manage`
- `PUT finance/recurring-charges/{charge}` — `finance.recurring_charges.update` — `App\Domain\Finance\Http\Controllers\RecurringChargeController@update` — `app/Domain/Finance/Http/Controllers/RecurringChargeController.php:126` — middleware `web, auth, permission:finance.ar.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/RecurringChargeController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/recurring-charges/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
