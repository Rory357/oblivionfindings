# CAP-HR-MY-HR-EXPENSES: My expenses and claims

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/expenses` (`hr.my.expenses`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/expenses` (`hr.my.expenses`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/my/expenses` (`hr.my.expenses.store`, action `submitExpense`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:621-630`; FormRequest `app/Http/Requests/Hr/StoreExpenseClaimRequest.php:16`; `title`, `notes`, `currency`, `on_behalf_user_id`, `items`.
3. Invoke only the owning control for `POST hr/my/expenses/{expenseClaim}/submit` (`hr.my.expenses.submit`, action `submitExpenseClaim`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:636-650`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `expenses` / `ROUTE-1516` at `app/Http/Controllers/Hr/MyHrController.php:589`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitExpense` / `ROUTE-1517` at `app/Http/Controllers/Hr/MyHrController.php:621`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitExpenseClaim` / `ROUTE-1518` at `app/Http/Controllers/Hr/MyHrController.php:636`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/expenses.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1517` / `submitExpense`: FormRequest `app/Http/Requests/Hr/StoreExpenseClaimRequest.php:16`; fields `title`, `notes`, `currency`, `on_behalf_user_id`, `items`; success app/Http/Controllers/Hr/MyHrController.php:629 `return redirect()->route('hr.my.expenses')->with('success', 'Expense claim created.');`.
- `ROUTE-1518` / `submitExpenseClaim`: success app/Http/Controllers/Hr/MyHrController.php:649 `return redirect()->back()->with('success', 'Expense claim submitted for approval.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:614 `return Inertia::render('hr/my/expenses', [`; app/Http/Controllers/Hr/MyHrController.php:626 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:629 `return redirect()->route('hr.my.expenses')->with('success', 'Expense claim created.');`; app/Http/Controllers/Hr/MyHrController.php:646 `return redirect()->back()->with('error', $exception->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:649 `return redirect()->back()->with('success', 'Expense claim submitted for approval.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/expenses` — `hr.my.expenses` — `App\Http\Controllers\Hr\MyHrController@expenses` — `app/Http/Controllers/Hr/MyHrController.php:589` — middleware `web, auth`
- `POST hr/my/expenses` — `hr.my.expenses.store` — `App\Http\Controllers\Hr\MyHrController@submitExpense` — `app/Http/Controllers/Hr/MyHrController.php:621` — middleware `web, auth`
- `POST hr/my/expenses/{expenseClaim}/submit` — `hr.my.expenses.submit` — `App\Http\Controllers\Hr\MyHrController@submitExpenseClaim` — `app/Http/Controllers/Hr/MyHrController.php:636` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/expenses.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
