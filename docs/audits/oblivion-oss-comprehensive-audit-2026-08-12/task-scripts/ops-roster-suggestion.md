# OPS-ROSTER-SUGGESTION: Roster Suggestion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:rostering.autoSchedule`
- Owning module: Operations and rostering
- Legacy family: `OPS-ROSTER-SUGGESTION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/rostering/suggestions/{run}` (`operations.rostering.suggestions.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:rostering.autoSchedule`.
- Exact middleware atoms: `web`, `auth`, `permission:rostering.autoSchedule`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/rostering/suggestions/{run}` (`operations.rostering.suggestions.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/rostering/suggestions/{run}/apply-accepted` (`operations.rostering.suggestions.apply_accepted`, action `applyAccepted`). Source category: **mutation outcome source gap (applyAccepted)**; controller `app/Http/Controllers/Operations/RosterSuggestionController.php:125-138`; no exact validation fields extracted.
3. Invoke only the owning control for `POST operations/rostering/suggestions/{suggestion}/accept` (`operations.rostering.suggestions.accept`, action `accept`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Operations/RosterSuggestionController.php:89-99`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/rostering/suggestions/{suggestion}/apply` (`operations.rostering.suggestions.apply`, action `apply`). Source category: **mutation outcome source gap (apply)**; controller `app/Http/Controllers/Operations/RosterSuggestionController.php:113-123`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/rostering/suggestions/{suggestion}/dismiss` (`operations.rostering.suggestions.dismiss`, action `dismiss`). Source category: **mutation outcome source gap (dismiss)**; controller `app/Http/Controllers/Operations/RosterSuggestionController.php:101-111`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2156` at `app/Http/Controllers/Operations/RosterSuggestionController.php:22`; it is not runtime-observed.
- **mutation outcome source gap (applyAccepted)** is applicable only to `applyAccepted` / `ROUTE-2157` at `app/Http/Controllers/Operations/RosterSuggestionController.php:125`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `accept` / `ROUTE-2158` at `app/Http/Controllers/Operations/RosterSuggestionController.php:89`; it is not runtime-observed.
- **mutation outcome source gap (apply)** is applicable only to `apply` / `ROUTE-2159` at `app/Http/Controllers/Operations/RosterSuggestionController.php:113`; it is not runtime-observed.
- **mutation outcome source gap (dismiss)** is applicable only to `dismiss` / `ROUTE-2160` at `app/Http/Controllers/Operations/RosterSuggestionController.php:101`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/rostering/suggestions/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2158` / `accept`: success app/Http/Controllers/Operations/RosterSuggestionController.php:98 `return back()->with('success', __('rostering.suggestions.accepted', ['id' => $updated->id]));`.
- `ROUTE-2159` / `apply`: success app/Http/Controllers/Operations/RosterSuggestionController.php:122 `return back()->with('success', __('rostering.suggestions.applied'));`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Operations/RosterSuggestionController.php:44 `return inertia('operations/rostering/suggestions/Show', [`; app/Http/Controllers/Operations/RosterSuggestionController.php:134 `return back()->with(`; app/Http/Controllers/Operations/RosterSuggestionController.php:98 `return back()->with('success', __('rostering.suggestions.accepted', ['id' => $updated->id]));`; app/Http/Controllers/Operations/RosterSuggestionController.php:122 `return back()->with('success', __('rostering.suggestions.applied'));`; app/Http/Controllers/Operations/RosterSuggestionController.php:110 `return back()->with('warning', __('rostering.suggestions.dismissed', ['id' => $updated->id]));`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/rostering/suggestions/{run}` — `operations.rostering.suggestions.show` — `App\Http\Controllers\Operations\RosterSuggestionController@show` — `app/Http/Controllers/Operations/RosterSuggestionController.php:22` — middleware `web, auth, permission:rostering.autoSchedule`
- `POST operations/rostering/suggestions/{run}/apply-accepted` — `operations.rostering.suggestions.apply_accepted` — `App\Http\Controllers\Operations\RosterSuggestionController@applyAccepted` — `app/Http/Controllers/Operations/RosterSuggestionController.php:125` — middleware `web, auth, permission:rostering.autoSchedule`
- `POST operations/rostering/suggestions/{suggestion}/accept` — `operations.rostering.suggestions.accept` — `App\Http\Controllers\Operations\RosterSuggestionController@accept` — `app/Http/Controllers/Operations/RosterSuggestionController.php:89` — middleware `web, auth, permission:rostering.autoSchedule`
- `POST operations/rostering/suggestions/{suggestion}/apply` — `operations.rostering.suggestions.apply` — `App\Http\Controllers\Operations\RosterSuggestionController@apply` — `app/Http/Controllers/Operations/RosterSuggestionController.php:113` — middleware `web, auth, permission:rostering.autoSchedule`
- `POST operations/rostering/suggestions/{suggestion}/dismiss` — `operations.rostering.suggestions.dismiss` — `App\Http\Controllers\Operations\RosterSuggestionController@dismiss` — `app/Http/Controllers/Operations/RosterSuggestionController.php:101` — middleware `web, auth, permission:rostering.autoSchedule`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/RosterSuggestionController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/rostering/suggestions/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
