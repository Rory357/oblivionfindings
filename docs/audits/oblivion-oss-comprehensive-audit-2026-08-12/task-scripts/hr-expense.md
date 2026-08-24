# HR-EXPENSE: Expense

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.expenses.view`, `permission:hr.expenses.manage`, `permission:hr.expenses.approve`
- Owning module: Human resources
- Legacy family: `HR-EXPENSE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/compensation/expenses` (`hr.compensation.expenses.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.expenses.view`, `permission:hr.expenses.manage`, `permission:hr.expenses.approve`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.expenses.view`, `permission:hr.expenses.manage`, `permission:hr.expenses.approve`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/compensation/expenses` (`hr.compensation.expenses.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/compensation/expenses/{expenseClaim}` (`hr.compensation.expenses.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ExpenseController.php:193-241`.
3. Use `GET|HEAD hr/compensation/expenses/{expenseClaim}/items/{item}/receipt` (`hr.compensation.expenses.receipt`, action `downloadReceipt`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/ExpenseController.php:247-272`.
4. Use `GET|HEAD hr/compensation/expenses/create` (`hr.compensation.expenses.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/ExpenseController.php:96-121`.
5. Invoke only the owning control for `POST hr/compensation/expenses` (`hr.compensation.expenses.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ExpenseController.php:145-187`; FormRequest `app/Http/Requests/Hr/StoreExpenseClaimRequest.php:16`; `title`, `notes`, `currency`, `on_behalf_user_id`, `items`.
6. Invoke only the owning control for `POST hr/compensation/expenses/{expenseClaim}/approve` (`hr.compensation.expenses.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/ExpenseController.php:297-310`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/compensation/expenses/{expenseClaim}/pay` (`hr.compensation.expenses.pay`, action `pay`). Source category: **mutation outcome source gap (pay)**; controller `app/Http/Controllers/Hr/ExpenseController.php:339-358`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/compensation/expenses/{expenseClaim}/reject` (`hr.compensation.expenses.reject`, action `reject`). Source category: **rejected/returned**; controller `app/Http/Controllers/Hr/ExpenseController.php:316-333`; `rejection_reason`.
9. Invoke only the owning control for `POST hr/compensation/expenses/{expenseClaim}/submit` (`hr.compensation.expenses.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/ExpenseController.php:278-291`; no exact validation fields extracted.
10. Invoke only the owning control for `POST hr/compensation/expenses/bulk-approve` (`hr.compensation.expenses.bulk-approve`, action `bulkApprove`). Source category: **mutation outcome source gap (bulkApprove)**; controller `app/Http/Controllers/Hr/ExpenseController.php:364-396`; `claim_ids`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1331` at `app/Http/Controllers/Hr/ExpenseController.php:31`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1332` at `app/Http/Controllers/Hr/ExpenseController.php:145`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1333` at `app/Http/Controllers/Hr/ExpenseController.php:193`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-1334` at `app/Http/Controllers/Hr/ExpenseController.php:297`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadReceipt` / `ROUTE-1335` at `app/Http/Controllers/Hr/ExpenseController.php:247`; it is not runtime-observed.
- **mutation outcome source gap (pay)** is applicable only to `pay` / `ROUTE-1336` at `app/Http/Controllers/Hr/ExpenseController.php:339`; it is not runtime-observed.
- **rejected/returned** is applicable only to `reject` / `ROUTE-1337` at `app/Http/Controllers/Hr/ExpenseController.php:316`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-1338` at `app/Http/Controllers/Hr/ExpenseController.php:278`; it is not runtime-observed.
- **mutation outcome source gap (bulkApprove)** is applicable only to `bulkApprove` / `ROUTE-1339` at `app/Http/Controllers/Hr/ExpenseController.php:364`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1340` at `app/Http/Controllers/Hr/ExpenseController.php:96`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/compensation/expenses/create.tsx`, `resources/js/pages/hr/compensation/expenses/index.tsx`, `resources/js/pages/hr/compensation/expenses/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1332` / `store`: FormRequest `app/Http/Requests/Hr/StoreExpenseClaimRequest.php:16`; fields `title`, `notes`, `currency`, `on_behalf_user_id`, `items`; success app/Http/Controllers/Hr/ExpenseController.php:186 `return redirect("/hr/compensation/expenses/{$claim->id}")->with('success', 'Expense claim created.');`.
- `ROUTE-1334` / `approve`: success app/Http/Controllers/Hr/ExpenseController.php:309 `return redirect()->back()->with('success', 'Expense claim approved.');`.
- `ROUTE-1336` / `pay`: success app/Http/Controllers/Hr/ExpenseController.php:357 `return redirect()->back()->with('success', 'Expense claim marked as paid.');`.
- `ROUTE-1337` / `reject`: fields `rejection_reason`; success app/Http/Controllers/Hr/ExpenseController.php:332 `return redirect()->back()->with('success', 'Expense claim rejected.');`.
- `ROUTE-1338` / `submit`: success app/Http/Controllers/Hr/ExpenseController.php:290 `return redirect()->back()->with('success', 'Expense claim submitted for approval.');`.
- `ROUTE-1339` / `bulkApprove`: fields `claim_ids`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/ExpenseController.php:69 `return Inertia::render('hr/compensation/expenses/index', [`; app/Http/Controllers/Hr/ExpenseController.php:183 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ExpenseController.php:186 `return redirect("/hr/compensation/expenses/{$claim->id}")->with('success', 'Expense claim created.');`; app/Http/Controllers/Hr/ExpenseController.php:203 `return Inertia::render('hr/compensation/expenses/show', [`; app/Http/Controllers/Hr/ExpenseController.php:306 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ExpenseController.php:309 `return redirect()->back()->with('success', 'Expense claim approved.');`; app/Http/Controllers/Hr/ExpenseController.php:265 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/ExpenseController.php:348 `return redirect()->back()->with('error', 'Expense claim must be posted to the general ledger before it can be marked paid.');`; app/Http/Controllers/Hr/ExpenseController.php:354 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ExpenseController.php:357 `return redirect()->back()->with('success', 'Expense claim marked as paid.');`; app/Http/Controllers/Hr/ExpenseController.php:329 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ExpenseController.php:332 `return redirect()->back()->with('success', 'Expense claim rejected.');`; app/Http/Controllers/Hr/ExpenseController.php:287 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/ExpenseController.php:290 `return redirect()->back()->with('success', 'Expense claim submitted for approval.');`; app/Http/Controllers/Hr/ExpenseController.php:392 `return redirect()->back()->with(`; app/Http/Controllers/Hr/ExpenseController.php:115 `return Inertia::render('hr/compensation/expenses/create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/compensation/expenses` — `hr.compensation.expenses.index` — `App\Http\Controllers\Hr\ExpenseController@index` — `app/Http/Controllers/Hr/ExpenseController.php:31` — middleware `web, auth, permission:hr.expenses.view`
- `POST hr/compensation/expenses` — `hr.compensation.expenses.store` — `App\Http\Controllers\Hr\ExpenseController@store` — `app/Http/Controllers/Hr/ExpenseController.php:145` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.manage`
- `GET|HEAD hr/compensation/expenses/{expenseClaim}` — `hr.compensation.expenses.show` — `App\Http\Controllers\Hr\ExpenseController@show` — `app/Http/Controllers/Hr/ExpenseController.php:193` — middleware `web, auth, permission:hr.expenses.view`
- `POST hr/compensation/expenses/{expenseClaim}/approve` — `hr.compensation.expenses.approve` — `App\Http\Controllers\Hr\ExpenseController@approve` — `app/Http/Controllers/Hr/ExpenseController.php:297` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.approve`
- `GET|HEAD hr/compensation/expenses/{expenseClaim}/items/{item}/receipt` — `hr.compensation.expenses.receipt` — `App\Http\Controllers\Hr\ExpenseController@downloadReceipt` — `app/Http/Controllers/Hr/ExpenseController.php:247` — middleware `web, auth, permission:hr.expenses.view`
- `POST hr/compensation/expenses/{expenseClaim}/pay` — `hr.compensation.expenses.pay` — `App\Http\Controllers\Hr\ExpenseController@pay` — `app/Http/Controllers/Hr/ExpenseController.php:339` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.approve`
- `POST hr/compensation/expenses/{expenseClaim}/reject` — `hr.compensation.expenses.reject` — `App\Http\Controllers\Hr\ExpenseController@reject` — `app/Http/Controllers/Hr/ExpenseController.php:316` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.approve`
- `POST hr/compensation/expenses/{expenseClaim}/submit` — `hr.compensation.expenses.submit` — `App\Http\Controllers\Hr\ExpenseController@submit` — `app/Http/Controllers/Hr/ExpenseController.php:278` — middleware `web, auth, permission:hr.expenses.view`
- `POST hr/compensation/expenses/bulk-approve` — `hr.compensation.expenses.bulk-approve` — `App\Http\Controllers\Hr\ExpenseController@bulkApprove` — `app/Http/Controllers/Hr/ExpenseController.php:364` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.approve`
- `GET|HEAD hr/compensation/expenses/create` — `hr.compensation.expenses.create` — `App\Http\Controllers\Hr\ExpenseController@create` — `app/Http/Controllers/Hr/ExpenseController.php:96` — middleware `web, auth, permission:hr.expenses.view, permission:hr.expenses.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/ExpenseController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/compensation/expenses/create.tsx`, `resources/js/pages/hr/compensation/expenses/index.tsx`, `resources/js/pages/hr/compensation/expenses/show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
