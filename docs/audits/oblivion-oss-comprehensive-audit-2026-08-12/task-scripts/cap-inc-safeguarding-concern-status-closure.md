# CAP-INC-SAFEGUARDING-CONCERN-STATUS-CLOSURE: Safeguarding status progression and closure

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-CONCERN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `safeguarding` (`safeguarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD safeguarding` (`safeguarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST safeguarding/{concern}/close` (`safeguarding.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/SafeguardingConcernController.php:452-501`; `closure_summary`, `lessons_learned`, `override_reason`.
3. Invoke only the owning control for `PATCH safeguarding/{concern}/status` (`safeguarding.updateStatus`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingConcernController.php:339-361`; no exact validation fields extracted.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `close` / `ROUTE-2513` at `app/Http/Controllers/SafeguardingConcernController.php:452`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-2521` at `app/Http/Controllers/SafeguardingConcernController.php:339`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2513` / `close`: fields `closure_summary`, `lessons_learned`, `override_reason`; success app/Http/Controllers/SafeguardingConcernController.php:500 `return back()->with('success', 'Concern closed.');`; failure app/Http/Controllers/SafeguardingConcernController.php:465 `return back()->withErrors(['close' => 'Triage the concern before closing.']);`; app/Http/Controllers/SafeguardingConcernController.php:469 `return back()->withErrors(['close' => 'This concern is already closed.']);`; app/Http/Controllers/SafeguardingConcernController.php:479 `return back()->withErrors([`.
- `ROUTE-2521` / `updateStatus`: success app/Http/Controllers/SafeguardingConcernController.php:360 `return back()->with('success', 'Status updated to ' . $lifecycle->label($validated['status']) . '.');`; failure app/Http/Controllers/SafeguardingConcernController.php:352 `return back()->withErrors(['status' => $guard['reason']]);`.

## Failure and recovery paths

- `close`: app/Http/Controllers/SafeguardingConcernController.php:465 `return back()->withErrors(['close' => 'Triage the concern before closing.']);`; app/Http/Controllers/SafeguardingConcernController.php:469 `return back()->withErrors(['close' => 'This concern is already closed.']);`; app/Http/Controllers/SafeguardingConcernController.php:479 `return back()->withErrors([`.
- `updateStatus`: app/Http/Controllers/SafeguardingConcernController.php:352 `return back()->withErrors(['status' => $guard['reason']]);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingConcernController.php:489 `$concern->update([`; app/Http/Controllers/SafeguardingConcernController.php:355 `$concern->update([`; responses app/Http/Controllers/SafeguardingConcernController.php:465 `return back()->withErrors(['close' => 'Triage the concern before closing.']);`; app/Http/Controllers/SafeguardingConcernController.php:469 `return back()->withErrors(['close' => 'This concern is already closed.']);`; app/Http/Controllers/SafeguardingConcernController.php:479 `return back()->withErrors([`; app/Http/Controllers/SafeguardingConcernController.php:500 `return back()->with('success', 'Concern closed.');`; app/Http/Controllers/SafeguardingConcernController.php:352 `return back()->withErrors(['status' => $guard['reason']]);`; app/Http/Controllers/SafeguardingConcernController.php:360 `return back()->with('success', 'Status updated to ' . $lifecycle->label($validated['status']) . '.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST safeguarding/{concern}/close` — `safeguarding.close` — `App\Http\Controllers\SafeguardingConcernController@close` — `app/Http/Controllers/SafeguardingConcernController.php:452` — middleware `web, auth`
- `PATCH safeguarding/{concern}/status` — `safeguarding.updateStatus` — `App\Http\Controllers\SafeguardingConcernController@updateStatus` — `app/Http/Controllers/SafeguardingConcernController.php:339` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingConcernController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
