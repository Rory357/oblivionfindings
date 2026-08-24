# ROAD-SUGGESTION: Suggestion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.manage`
- Owning module: Roadmap
- Legacy family: `ROAD-SUGGESTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `roadmap/suggestions` (`roadmap.suggestions.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:roadmap.view`, `permission:roadmap.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:roadmap.view`, `permission:roadmap.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD roadmap/suggestions` (`roadmap.suggestions.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST roadmap/suggestions/{suggestion}/convert` (`roadmap.suggestions.convert`, action `convert`). Source category: **mutation outcome source gap (convert)**; controller `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:127-150`; FormRequest `app/Domain/Roadmap/Http/Requests/StoreSuggestionRequest.php:14`; `title`, `summary`, `category_key`, `stream`, `owner_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `triage_notes`, `impact_profile`.
3. Invoke only the owning control for `POST roadmap/suggestions/{suggestion}/triage` (`roadmap.suggestions.triage`, action `triage`). Source category: **mutation outcome source gap (triage)**; controller `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:78-125`; no exact validation fields extracted.
4. Invoke only the owning control for `POST roadmap/suggestions/ingest` (`roadmap.suggestions.ingest`, action `ingest`). Source category: **mutation outcome source gap (ingest)**; controller `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:66-76`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2493` at `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:27`; it is not runtime-observed.
- **mutation outcome source gap (convert)** is applicable only to `convert` / `ROUTE-2494` at `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:127`; it is not runtime-observed.
- **mutation outcome source gap (triage)** is applicable only to `triage` / `ROUTE-2495` at `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:78`; it is not runtime-observed.
- **mutation outcome source gap (ingest)** is applicable only to `ingest` / `ROUTE-2496` at `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:66`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/Roadmap/Suggestions/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2494` / `convert`: FormRequest `app/Domain/Roadmap/Http/Requests/StoreSuggestionRequest.php:14`; fields `title`, `summary`, `category_key`, `stream`, `owner_user_id`, `next_decision`, `decision_due_at`, `target_fiscal_year`, `target_quarter`, `cost_estimate_low`, `cost_estimate_high`, `benefit_summary`, `risk_summary`, `dependency_summary`, `triage_notes`, `impact_profile`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Domain/Roadmap/Http/Controllers/SuggestionController.php:52 `return response()->json(['items' => $items]);`; app/Domain/Roadmap/Http/Controllers/SuggestionController.php:55 `return Inertia::render('Roadmap/Suggestions/Index', [`; app/Domain/Roadmap/Http/Controllers/SuggestionController.php:146 `return response()->json([`; app/Domain/Roadmap/Http/Controllers/SuggestionController.php:124 `return response()->json(['item' => $updated]);`; app/Domain/Roadmap/Http/Controllers/SuggestionController.php:72 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD roadmap/suggestions` — `roadmap.suggestions.index` — `App\Domain\Roadmap\Http\Controllers\SuggestionController@index` — `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:27` — middleware `web, auth, permission:roadmap.view`
- `POST roadmap/suggestions/{suggestion}/convert` — `roadmap.suggestions.convert` — `App\Domain\Roadmap\Http\Controllers\SuggestionController@convert` — `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:127` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/suggestions/{suggestion}/triage` — `roadmap.suggestions.triage` — `App\Domain\Roadmap\Http\Controllers\SuggestionController@triage` — `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:78` — middleware `web, auth, permission:roadmap.manage`
- `POST roadmap/suggestions/ingest` — `roadmap.suggestions.ingest` — `App\Domain\Roadmap\Http\Controllers\SuggestionController@ingest` — `app/Domain/Roadmap/Http/Controllers/SuggestionController.php:66` — middleware `web, auth, permission:roadmap.manage`

## Source anchors and limits

- Backend anchor: `app/Domain/Roadmap/Http/Controllers/SuggestionController.php`.
- Exact render/action page relationships: `resources/js/pages/Roadmap/Suggestions/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
