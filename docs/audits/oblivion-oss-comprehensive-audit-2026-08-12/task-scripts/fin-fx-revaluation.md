# FIN-FX-REVALUATION: Fx Revaluation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:finance.ledger.manage`
- Owning module: Finance and funding
- Legacy family: `FIN-FX-REVALUATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `finance/fx-revaluations` (`finance.fx-revaluations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:finance.ledger.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:finance.ledger.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD finance/fx-revaluations` (`finance.fx-revaluations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD finance/fx-revaluations/create` (`finance.fx-revaluations.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:41-52`.
3. Invoke only the owning control for `POST finance/fx-revaluations` (`finance.fx-revaluations.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54-70`; `date`, `notes`.
4. Invoke only the owning control for `POST finance/fx-revaluations/{revaluation}/post` (`finance.fx-revaluations.post`, action `post`). Source category: **mutation outcome source gap (post)**; controller `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72-82`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0577` at `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0578` at `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54`; it is not runtime-observed.
- **mutation outcome source gap (post)** is applicable only to `post` / `ROUTE-0579` at `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0580` at `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:41`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/finance/fx-revaluations/Create.tsx`, `resources/js/pages/finance/fx-revaluations/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0578` / `store`: fields `date`, `notes`; success app/Domain/Finance/Http/Controllers/FxRevaluationController.php:69 `->with('success', 'FX Revaluation created as draft.');`.
- `ROUTE-0579` / `post`: success app/Domain/Finance/Http/Controllers/FxRevaluationController.php:78 `->with('success', 'FX Revaluation posted to General Ledger.');`; failure app/Domain/Finance/Http/Controllers/FxRevaluationController.php:80 `return back()->withErrors(['post' => $e->getMessage()]);`.

## Failure and recovery paths

- `post`: app/Domain/Finance/Http/Controllers/FxRevaluationController.php:80 `return back()->withErrors(['post' => $e->getMessage()]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Finance/Http/Controllers/FxRevaluationController.php:65 `$reval->update(['notes' => $validated['notes']]);`; responses app/Domain/Finance/Http/Controllers/FxRevaluationController.php:36 `return Inertia::render('finance/fx-revaluations/Index', [`; app/Domain/Finance/Http/Controllers/FxRevaluationController.php:68 `return redirect()->route('finance.fx-revaluations.index')`; app/Domain/Finance/Http/Controllers/FxRevaluationController.php:77 `return redirect()->route('finance.fx-revaluations.index')`; app/Domain/Finance/Http/Controllers/FxRevaluationController.php:80 `return back()->withErrors(['post' => $e->getMessage()]);`; app/Domain/Finance/Http/Controllers/FxRevaluationController.php:48 `return Inertia::render('finance/fx-revaluations/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD finance/fx-revaluations` — `finance.fx-revaluations.index` — `App\Domain\Finance\Http\Controllers\FxRevaluationController@index` — `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:17` — middleware `web, auth, permission:finance.ledger.manage`
- `POST finance/fx-revaluations` — `finance.fx-revaluations.store` — `App\Domain\Finance\Http\Controllers\FxRevaluationController@store` — `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:54` — middleware `web, auth, permission:finance.ledger.manage`
- `POST finance/fx-revaluations/{revaluation}/post` — `finance.fx-revaluations.post` — `App\Domain\Finance\Http\Controllers\FxRevaluationController@post` — `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:72` — middleware `web, auth, permission:finance.ledger.manage`
- `GET|HEAD finance/fx-revaluations/create` — `finance.fx-revaluations.create` — `App\Domain\Finance\Http\Controllers\FxRevaluationController@create` — `app/Domain/Finance/Http/Controllers/FxRevaluationController.php:41` — middleware `web, auth, permission:finance.ledger.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Finance/Http/Controllers/FxRevaluationController.php`.
- Exact render/action page relationships: `resources/js/pages/finance/fx-revaluations/Create.tsx`, `resources/js/pages/finance/fx-revaluations/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
