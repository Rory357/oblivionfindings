# GOV-BOARD-EVALUATION: Board Evaluation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.evaluations.view`, `permission:governance.evaluations.manage`
- Owning module: Governance
- Legacy family: `GOV-BOARD-EVALUATION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/evaluations` (`governance.evaluations.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.evaluations.view`, `permission:governance.evaluations.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.evaluations.view`, `permission:governance.evaluations.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/evaluations` (`governance.evaluations.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD governance/evaluations/{evaluation}` (`governance.evaluations.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:78-121`.
3. Use `GET|HEAD governance/evaluations/{evaluation}/results` (`governance.evaluations.results`, action `results`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:173-182`.
4. Use `GET|HEAD governance/evaluations/create` (`governance.evaluations.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:39-44`.
5. Invoke only the owning control for `POST governance/evaluations` (`governance.evaluations.store`, action `store`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:46-76`; `title`, `evaluation_type`, `period_start`, `period_end`, `questions`, `due_date`.
6. Invoke only the owning control for `POST governance/evaluations/{evaluation}/close` (`governance.evaluations.close`, action `close`). Source category: **completed/closed/released**; controller `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:161-171`; no exact validation fields extracted.
7. Invoke only the owning control for `POST governance/evaluations/{evaluation}/launch` (`governance.evaluations.launch`, action `launch`). Source category: **mutation outcome source gap (launch)**; controller `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:123-133`; no exact validation fields extracted.
8. Invoke only the owning control for `POST governance/evaluations/{evaluation}/respond` (`governance.evaluations.respond`, action `respond`). Source category: **mutation outcome source gap (respond)**; controller `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:135-159`; `answers`, `overall_comments`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0920` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0921` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:46`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-0922` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:78`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-0923` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:161`; it is not runtime-observed.
- **mutation outcome source gap (launch)** is applicable only to `launch` / `ROUTE-0924` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:123`; it is not runtime-observed.
- **mutation outcome source gap (respond)** is applicable only to `respond` / `ROUTE-0925` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:135`; it is not runtime-observed.
- **information presented** is applicable only to `results` / `ROUTE-0926` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:173`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-0927` at `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:39`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Governance/Evaluations/Create.tsx`, `resources/js/pages/Governance/Evaluations/Index.tsx`, `resources/js/pages/Governance/Evaluations/Results.tsx`, `resources/js/pages/Governance/Evaluations/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0921` / `store`: fields `title`, `evaluation_type`, `period_start`, `period_end`, `questions`, `due_date`; success app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:75 `->with('success', 'Board evaluation created.');`.
- `ROUTE-0923` / `close`: success app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:170 `return redirect()->back()->with('success', 'Evaluation closed.');`.
- `ROUTE-0924` / `launch`: success app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:132 `return redirect()->back()->with('success', 'Evaluation launched. Board members can now respond.');`.
- `ROUTE-0925` / `respond`: fields `answers`, `overall_comments`; success app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:158 `return redirect()->back()->with('success', 'Response submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:61 `$evaluation = BoardEvaluation::create([`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:165 `$evaluation->update([`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:127 `$evaluation->update([`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:147 `BoardEvaluationResponse::updateOrCreate(`; responses app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:22 `return [`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:34 `return Inertia::render('Governance/Evaluations/Index', [`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:74 `return redirect()->route('governance.evaluations.show', $evaluation)`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:93 `return Inertia::render('Governance/Evaluations/Show', [`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:170 `return redirect()->back()->with('success', 'Evaluation closed.');`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:132 `return redirect()->back()->with('success', 'Evaluation launched. Board members can now respond.');`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:139 `return redirect()->back()->with('error', 'You are not a board member.');`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:158 `return redirect()->back()->with('success', 'Response submitted.');`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:179 `return Inertia::render('Governance/Evaluations/Results', [`; app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:43 `return Inertia::render('Governance/Evaluations/Create');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD governance/evaluations` — `governance.evaluations.index` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@index` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:14` — middleware `web, auth, permission:governance.evaluations.view`
- `POST governance/evaluations` — `governance.evaluations.store` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@store` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:46` — middleware `web, auth, permission:governance.evaluations.view, permission:governance.evaluations.manage`
- `GET|HEAD governance/evaluations/{evaluation}` — `governance.evaluations.show` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@show` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:78` — middleware `web, auth, permission:governance.evaluations.view`
- `POST governance/evaluations/{evaluation}/close` — `governance.evaluations.close` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@close` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:161` — middleware `web, auth, permission:governance.evaluations.view, permission:governance.evaluations.manage`
- `POST governance/evaluations/{evaluation}/launch` — `governance.evaluations.launch` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@launch` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:123` — middleware `web, auth, permission:governance.evaluations.view, permission:governance.evaluations.manage`
- `POST governance/evaluations/{evaluation}/respond` — `governance.evaluations.respond` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@respond` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:135` — middleware `web, auth, permission:governance.evaluations.view`
- `GET|HEAD governance/evaluations/{evaluation}/results` — `governance.evaluations.results` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@results` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:173` — middleware `web, auth, permission:governance.evaluations.view`
- `GET|HEAD governance/evaluations/create` — `governance.evaluations.create` — `App\Domain\Governance\Http\Controllers\BoardEvaluationController@create` — `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:39` — middleware `web, auth, permission:governance.evaluations.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/BoardEvaluationController.php`.
- Exact render/action page relationships: `resources/js/pages/Governance/Evaluations/Create.tsx`, `resources/js/pages/Governance/Evaluations/Index.tsx`, `resources/js/pages/Governance/Evaluations/Results.tsx`, `resources/js/pages/Governance/Evaluations/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
