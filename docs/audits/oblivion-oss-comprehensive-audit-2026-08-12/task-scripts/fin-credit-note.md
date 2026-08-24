# FIN-CREDIT-NOTE: Credit Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-CREDIT-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/credit-notes` (`finance.credit-notes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ap.view`, `permission:finance.ap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/credit-notes` (`finance.credit-notes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/credit-notes/{creditNote}` (`finance.credit-notes.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/CreditNoteController.php:106-120`.
3. Invoke only the owning control for `POST finance/credit-notes` (`finance.credit-notes.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/CreditNoteController.php:96-104`; FormRequest `app/Domain/Finance/Http/Requests/StoreCreditNoteRequest.php:15`; `type`, `vendor_id`, `client_id`, `credit_date`, `reason`, `lines`.
4. Invoke only the owning control for `POST finance/credit-notes/{creditNote}/approve` (`finance.credit-notes.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Domain/Finance/Http/Controllers/CreditNoteController.php:122-136`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0531` at `app/Domain/Finance/Http/Controllers/CreditNoteController.php:22`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0532` at `app/Domain/Finance/Http/Controllers/CreditNoteController.php:96`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0533` at `app/Domain/Finance/Http/Controllers/CreditNoteController.php:106`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-0534` at `app/Domain/Finance/Http/Controllers/CreditNoteController.php:122`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/credit-notes/Index.tsx`, `resources/js/pages/finance/credit-notes/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0532` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreCreditNoteRequest.php:15`; fields `type`, `vendor_id`, `client_id`, `credit_date`, `reason`, `lines`; success app/Domain/Finance/Http/Controllers/CreditNoteController.php:103 `->with('success', 'Credit note created successfully.');`.
- `ROUTE-0534` / `approve`: success app/Domain/Finance/Http/Controllers/CreditNoteController.php:135 `->with('success', 'Credit note approved and journal posted successfully.');`; failure app/Domain/Finance/Http/Controllers/CreditNoteController.php:129 `return back()->withErrors(['credit_note' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:131 `return back()->withErrors(['credit_note' => 'Failed to approve credit note: ' . $e->getMessage()]);`.

## Failure and recovery paths

- `approve`: app/Domain/Finance/Http/Controllers/CreditNoteController.php:129 `return back()->withErrors(['credit_note' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:131 `return back()->withErrors(['credit_note' => 'Failed to approve credit note: ' . $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/CreditNoteController.php:49 `return Inertia::render('finance/credit-notes/Index', [`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:102 `return redirect()->route('finance.credit-notes.show', $creditNote)`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:117 `return Inertia::render('finance/credit-notes/Show', [`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:129 `return back()->withErrors(['credit_note' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:131 `return back()->withErrors(['credit_note' => 'Failed to approve credit note: ' . $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/CreditNoteController.php:134 `return redirect()->route('finance.credit-notes.show', $creditNote)`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/credit-notes` — `finance.credit-notes.index` — `App\Domain\Finance\Http\Controllers\CreditNoteController@index` — `app/Domain/Finance/Http/Controllers/CreditNoteController.php:22` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/credit-notes` — `finance.credit-notes.store` — `App\Domain\Finance\Http\Controllers\CreditNoteController@store` — `app/Domain/Finance/Http/Controllers/CreditNoteController.php:96` — middleware `web, auth, permission:finance.ap.manage`
- `GET|HEAD finance/credit-notes/{creditNote}` — `finance.credit-notes.show` — `App\Domain\Finance\Http\Controllers\CreditNoteController@show` — `app/Domain/Finance/Http/Controllers/CreditNoteController.php:106` — middleware `web, auth, permission:finance.ap.view`
- `POST finance/credit-notes/{creditNote}/approve` — `finance.credit-notes.approve` — `App\Domain\Finance\Http\Controllers\CreditNoteController@approve` — `app/Domain/Finance/Http/Controllers/CreditNoteController.php:122` — middleware `web, auth, permission:finance.ap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/CreditNoteController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/credit-notes/Index.tsx`, `resources/js/pages/finance/credit-notes/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
