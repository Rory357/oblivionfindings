# FIN-JOURNAL: Journal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-JOURNAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/journals` (`finance.journals.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ledger.view`, `permission:finance.ledger.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/journals` (`finance.journals.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/journals/{journal}` (`finance.journals.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/JournalController.php:143-161`.
3. Use `GET|HEAD finance/journals/create` (`finance.journals.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/JournalController.php:86-110`.
4. Invoke only the owning control for `POST finance/journals` (`finance.journals.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/JournalController.php:115-138`; FormRequest `app/Domain/Finance/Http/Requests/StoreJournalRequest.php:15`; `journal_date`, `type`, `reference`, `description`, `lines`, `post_immediately`.
5. Invoke only the owning control for `POST finance/journals/{journal}/post` (`finance.journals.post`, action `post`). Source category: **mutation outcome source gap (post)**; controller `app/Domain/Finance/Http/Controllers/JournalController.php:166-178`; no exact validation fields extracted.
6. Invoke only the owning control for `POST finance/journals/{journal}/reverse` (`finance.journals.reverse`, action `reverse`). Source category: **mutation outcome source gap (reverse)**; controller `app/Domain/Finance/Http/Controllers/JournalController.php:183-199`; `reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0613` at `app/Domain/Finance/Http/Controllers/JournalController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0614` at `app/Domain/Finance/Http/Controllers/JournalController.php:115`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0615` at `app/Domain/Finance/Http/Controllers/JournalController.php:143`; it is not runtime-observed.
- **mutation outcome source gap (post)** is applicable only to `post` / `ROUTE-0616` at `app/Domain/Finance/Http/Controllers/JournalController.php:166`; it is not runtime-observed.
- **mutation outcome source gap (reverse)** is applicable only to `reverse` / `ROUTE-0617` at `app/Domain/Finance/Http/Controllers/JournalController.php:183`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0618` at `app/Domain/Finance/Http/Controllers/JournalController.php:86`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/journals/Create.tsx`, `resources/js/pages/finance/journals/Index.tsx`, `resources/js/pages/finance/journals/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0614` / `store`: FormRequest `app/Domain/Finance/Http/Requests/StoreJournalRequest.php:15`; fields `journal_date`, `type`, `reference`, `description`, `lines`, `post_immediately`; success app/Domain/Finance/Http/Controllers/JournalController.php:134 `->with('success', $journal->status === 'posted'`; failure app/Domain/Finance/Http/Controllers/JournalController.php:130 `->withErrors(['posting' => $e->getMessage()]);`.
- `ROUTE-0616` / `post`: success app/Domain/Finance/Http/Controllers/JournalController.php:177 `->with('success', "Journal {$journal->journal_number} has been posted.");`; failure app/Domain/Finance/Http/Controllers/JournalController.php:173 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`.
- `ROUTE-0617` / `reverse`: fields `reason`; success app/Domain/Finance/Http/Controllers/JournalController.php:198 `->with('success', "Reversing journal {$reversingJournal->journal_number} created and posted.");`; failure app/Domain/Finance/Http/Controllers/JournalController.php:194 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`.

## Failure and recovery paths

- `store`: app/Domain/Finance/Http/Controllers/JournalController.php:130 `->withErrors(['posting' => $e->getMessage()]);`.
- `post`: app/Domain/Finance/Http/Controllers/JournalController.php:173 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`.
- `reverse`: app/Domain/Finance/Http/Controllers/JournalController.php:194 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/JournalController.php:67 `return Inertia::render('finance/journals/Index', [`; app/Domain/Finance/Http/Controllers/JournalController.php:128 `return redirect()->back()`; app/Domain/Finance/Http/Controllers/JournalController.php:133 `return redirect()->route('finance.journals.show', $journal)`; app/Domain/Finance/Http/Controllers/JournalController.php:158 `return Inertia::render('finance/journals/Show', [`; app/Domain/Finance/Http/Controllers/JournalController.php:173 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/JournalController.php:176 `return redirect()->route('finance.journals.show', $journal)`; app/Domain/Finance/Http/Controllers/JournalController.php:194 `return redirect()->back()->withErrors(['posting' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/JournalController.php:197 `return redirect()->route('finance.journals.show', $reversingJournal)`; app/Domain/Finance/Http/Controllers/JournalController.php:92 `return Inertia::render('finance/journals/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/journals` — `finance.journals.index` — `App\Domain\Finance\Http\Controllers\JournalController@index` — `app/Domain/Finance/Http/Controllers/JournalController.php:25` — middleware `web, auth, permission:finance.ledger.view`
- `POST finance/journals` — `finance.journals.store` — `App\Domain\Finance\Http\Controllers\JournalController@store` — `app/Domain/Finance/Http/Controllers/JournalController.php:115` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/journals/{journal}` — `finance.journals.show` — `App\Domain\Finance\Http\Controllers\JournalController@show` — `app/Domain/Finance/Http/Controllers/JournalController.php:143` — middleware `web, auth, permission:finance.ledger.view`
- `POST finance/journals/{journal}/post` — `finance.journals.post` — `App\Domain\Finance\Http\Controllers\JournalController@post` — `app/Domain/Finance/Http/Controllers/JournalController.php:166` — middleware `web, auth, permission:finance.ledger.manage`
- `POST finance/journals/{journal}/reverse` — `finance.journals.reverse` — `App\Domain\Finance\Http\Controllers\JournalController@reverse` — `app/Domain/Finance/Http/Controllers/JournalController.php:183` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/journals/create` — `finance.journals.create` — `App\Domain\Finance\Http\Controllers\JournalController@create` — `app/Domain/Finance/Http/Controllers/JournalController.php:86` — middleware `web, auth, permission:finance.ledger.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/JournalController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/journals/Create.tsx`, `resources/js/pages/finance/journals/Index.tsx`, `resources/js/pages/finance/journals/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
