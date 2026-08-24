# FIN-EFTPOS: Eftpos

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-EFTPOS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/eftpos/batches` (`finance.eftpos.batches`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.bank.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.bank.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/eftpos/batches` (`finance.eftpos.batches`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/eftpos/batches/{batch}` (`finance.eftpos.batches.show`, action `batchDetail`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/EftposController.php:237-292`.
3. Use `GET|HEAD finance/eftpos/terminals` (`finance.eftpos.terminals`, action `terminals`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/EftposController.php:24-52`.
4. Invoke only the owning control for `POST finance/eftpos/batches/{batch}/reconcile` (`finance.eftpos.batches.reconcile`, action `reconcile`). Source category: **retried/replayed/reconciled**; controller `app/Domain/Finance/Http/Controllers/EftposController.php:206-232`; `bank_transaction_id`, `discrepancy_notes`.
5. Invoke only the owning control for `POST finance/eftpos/batches/import` (`finance.eftpos.batches.import`, action `importBatch`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/EftposController.php:174-201`; `terminal_id`, `batch_number`, `batch_date`, `transactions`.
6. Invoke only the owning control for `POST finance/eftpos/terminals` (`finance.eftpos.terminals.store`, action `storeTerminal`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/EftposController.php:57-80`; `terminal_id`, `name`, `location`, `provider`, `merchant_id`, `bank_account_id`, `gl_account_id`.
7. Invoke only the owning control for `PUT finance/eftpos/terminals/{terminal}` (`finance.eftpos.terminals.update`, action `updateTerminal`). Source category: **updated/revised**; controller `app/Domain/Finance/Http/Controllers/EftposController.php:85-101`; `name`, `location`, `provider`, `merchant_id`, `bank_account_id`, `gl_account_id`, `is_active`.

## Source-applicable states and transitions

- **information presented** is applicable only to `batches` / `ROUTE-0553` at `app/Domain/Finance/Http/Controllers/EftposController.php:106`; it is not runtime-observed.
- **information presented** is applicable only to `batchDetail` / `ROUTE-0554` at `app/Domain/Finance/Http/Controllers/EftposController.php:237`; it is not runtime-observed.
- **retried/replayed/reconciled** is applicable only to `reconcile` / `ROUTE-0555` at `app/Domain/Finance/Http/Controllers/EftposController.php:206`; it is not runtime-observed.
- **created/recorded** is applicable only to `importBatch` / `ROUTE-0556` at `app/Domain/Finance/Http/Controllers/EftposController.php:174`; it is not runtime-observed.
- **information presented** is applicable only to `terminals` / `ROUTE-0557` at `app/Domain/Finance/Http/Controllers/EftposController.php:24`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeTerminal` / `ROUTE-0558` at `app/Domain/Finance/Http/Controllers/EftposController.php:57`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateTerminal` / `ROUTE-0559` at `app/Domain/Finance/Http/Controllers/EftposController.php:85`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/eftpos/BatchDetail.tsx`, `resources/js/pages/finance/eftpos/Batches.tsx`, `resources/js/pages/finance/eftpos/Terminals.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0555` / `reconcile`: fields `bank_transaction_id`, `discrepancy_notes`; success app/Domain/Finance/Http/Controllers/EftposController.php:231 `->with('success', $message);`; failure app/Domain/Finance/Http/Controllers/EftposController.php:223 `return back()->withErrors(['reconcile' => $e->getMessage()]);`.
- `ROUTE-0556` / `importBatch`: fields `terminal_id`, `batch_number`, `batch_date`, `transactions`; success app/Domain/Finance/Http/Controllers/EftposController.php:200 `->with('success', "Batch {$batch->batch_number} imported with {$batch->total_transactions} transactions.");`; failure app/Domain/Finance/Http/Controllers/EftposController.php:196 `return back()->withErrors(['import' => $e->getMessage()]);`.
- `ROUTE-0558` / `storeTerminal`: fields `terminal_id`, `name`, `location`, `provider`, `merchant_id`, `bank_account_id`, `gl_account_id`; success app/Domain/Finance/Http/Controllers/EftposController.php:79 `->with('success', 'EFTPOS terminal added successfully.');`.
- `ROUTE-0559` / `updateTerminal`: fields `name`, `location`, `provider`, `merchant_id`, `bank_account_id`, `gl_account_id`, `is_active`; success app/Domain/Finance/Http/Controllers/EftposController.php:100 `->with('success', 'EFTPOS terminal updated successfully.');`.

## Failure and recovery paths

- `reconcile`: app/Domain/Finance/Http/Controllers/EftposController.php:223 `return back()->withErrors(['reconcile' => $e->getMessage()]);`.
- `importBatch`: app/Domain/Finance/Http/Controllers/EftposController.php:196 `return back()->withErrors(['import' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/EftposController.php:220 `$batch->update(['discrepancy_notes' => $validated['discrepancy_notes']]);`; app/Domain/Finance/Http/Controllers/EftposController.php:71 `FinEftposTerminal::create([`; app/Domain/Finance/Http/Controllers/EftposController.php:97 `$terminal->update($validated);`; responses app/Domain/Finance/Http/Controllers/EftposController.php:163 `return Inertia::render('finance/eftpos/Batches', [`; app/Domain/Finance/Http/Controllers/EftposController.php:262 `return Inertia::render('finance/eftpos/BatchDetail', [`; app/Domain/Finance/Http/Controllers/EftposController.php:223 `return back()->withErrors(['reconcile' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/EftposController.php:230 `return redirect()->route('finance.eftpos.batches')`; app/Domain/Finance/Http/Controllers/EftposController.php:196 `return back()->withErrors(['import' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/EftposController.php:199 `return redirect()->route('finance.eftpos.batches.show', $batch)`; app/Domain/Finance/Http/Controllers/EftposController.php:47 `return Inertia::render('finance/eftpos/Terminals', [`; app/Domain/Finance/Http/Controllers/EftposController.php:78 `return redirect()->route('finance.eftpos.terminals')`; app/Domain/Finance/Http/Controllers/EftposController.php:99 `return redirect()->route('finance.eftpos.terminals')`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/eftpos/batches` — `finance.eftpos.batches` — `App\Domain\Finance\Http\Controllers\EftposController@batches` — `app/Domain/Finance/Http/Controllers/EftposController.php:106` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/eftpos/batches/{batch}` — `finance.eftpos.batches.show` — `App\Domain\Finance\Http\Controllers\EftposController@batchDetail` — `app/Domain/Finance/Http/Controllers/EftposController.php:237` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/eftpos/batches/{batch}/reconcile` — `finance.eftpos.batches.reconcile` — `App\Domain\Finance\Http\Controllers\EftposController@reconcile` — `app/Domain/Finance/Http/Controllers/EftposController.php:206` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/eftpos/batches/import` — `finance.eftpos.batches.import` — `App\Domain\Finance\Http\Controllers\EftposController@importBatch` — `app/Domain/Finance/Http/Controllers/EftposController.php:174` — middleware `web, auth, permission:finance.bank.manage`
- `GET|HEAD finance/eftpos/terminals` — `finance.eftpos.terminals` — `App\Domain\Finance\Http\Controllers\EftposController@terminals` — `app/Domain/Finance/Http/Controllers/EftposController.php:24` — middleware `web, auth, permission:finance.bank.manage`
- `POST finance/eftpos/terminals` — `finance.eftpos.terminals.store` — `App\Domain\Finance\Http\Controllers\EftposController@storeTerminal` — `app/Domain/Finance/Http/Controllers/EftposController.php:57` — middleware `web, auth, permission:finance.bank.manage`
- `PUT finance/eftpos/terminals/{terminal}` — `finance.eftpos.terminals.update` — `App\Domain\Finance\Http\Controllers\EftposController@updateTerminal` — `app/Domain/Finance/Http/Controllers/EftposController.php:85` — middleware `web, auth, permission:finance.bank.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/EftposController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/eftpos/BatchDetail.tsx`, `resources/js/pages/finance/eftpos/Batches.tsx`, `resources/js/pages/finance/eftpos/Terminals.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
