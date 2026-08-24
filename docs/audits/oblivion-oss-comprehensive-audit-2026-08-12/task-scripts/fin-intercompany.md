# FIN-INTERCOMPANY: Intercompany

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.admin`
- Owning module: Finance and funding
- Legacy family: `FIN-INTERCOMPANY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/intercompany/{group}` (`finance.intercompany.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.admin`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.admin`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/intercompany/{group}` (`finance.intercompany.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST finance/intercompany/{group}` (`finance.intercompany.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/IntercompanyController.php:64-80`; `from_entity_id`, `to_entity_id`, `transaction_date`, `description`, `amount`.
3. Invoke only the owning control for `POST finance/intercompany/{group}/{transaction}/post` (`finance.intercompany.post`, action `post`). Source category: **mutation outcome source gap (post)**; controller `app/Domain/Finance/Http/Controllers/IntercompanyController.php:85-101`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0594` at `app/Domain/Finance/Http/Controllers/IntercompanyController.php:21`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0595` at `app/Domain/Finance/Http/Controllers/IntercompanyController.php:64`; it is not runtime-observed.
- **mutation outcome source gap (post)** is applicable only to `post` / `ROUTE-0596` at `app/Domain/Finance/Http/Controllers/IntercompanyController.php:85`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/Intercompany/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0595` / `store`: fields `from_entity_id`, `to_entity_id`, `transaction_date`, `description`, `amount`; success app/Domain/Finance/Http/Controllers/IntercompanyController.php:79 `->with('success', 'Intercompany transaction created.');`.
- `ROUTE-0596` / `post`: success app/Domain/Finance/Http/Controllers/IntercompanyController.php:97 `->with('success', 'Intercompany transaction posted successfully.');`; failure app/Domain/Finance/Http/Controllers/IntercompanyController.php:90 `abort(404);`; app/Domain/Finance/Http/Controllers/IntercompanyController.php:99 `return back()->withErrors(['transaction' => 'Failed to post: ' . $e->getMessage()]);`.

## Failure and recovery paths

- `post`: app/Domain/Finance/Http/Controllers/IntercompanyController.php:90 `abort(404);`; app/Domain/Finance/Http/Controllers/IntercompanyController.php:99 `return back()->withErrors(['transaction' => 'Failed to post: ' . $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Finance/Http/Controllers/IntercompanyController.php:51 `return Inertia::render('finance/Intercompany/Index', [`; app/Domain/Finance/Http/Controllers/IntercompanyController.php:78 `return redirect()->route('finance.intercompany.index', $group)`; app/Domain/Finance/Http/Controllers/IntercompanyController.php:96 `return redirect()->route('finance.intercompany.index', $group)`; app/Domain/Finance/Http/Controllers/IntercompanyController.php:99 `return back()->withErrors(['transaction' => 'Failed to post: ' . $e->getMessage()]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/intercompany/{group}` — `finance.intercompany.index` — `App\Domain\Finance\Http\Controllers\IntercompanyController@index` — `app/Domain/Finance/Http/Controllers/IntercompanyController.php:21` — middleware `web, auth, permission:finance.admin`
- `POST finance/intercompany/{group}` — `finance.intercompany.store` — `App\Domain\Finance\Http\Controllers\IntercompanyController@store` — `app/Domain/Finance/Http/Controllers/IntercompanyController.php:64` — middleware `web, auth, permission:finance.admin`
- `POST finance/intercompany/{group}/{transaction}/post` — `finance.intercompany.post` — `App\Domain\Finance\Http\Controllers\IntercompanyController@post` — `app/Domain/Finance/Http/Controllers/IntercompanyController.php:85` — middleware `web, auth, permission:finance.admin`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/IntercompanyController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/Intercompany/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
