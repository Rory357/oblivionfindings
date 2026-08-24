# CAP-HR-MY-HR-SURVEYS: My survey participation

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/surveys` (`hr.my.surveys`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/surveys` (`hr.my.surveys`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/my/surveys/{survey}` (`hr.my.surveys.submit`, action `submitSurvey`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/MyHrController.php:1224-1242`; `answers`.

## Source-applicable states and transitions

- **information presented** is applicable only to `surveys` / `ROUTE-1542` at `app/Http/Controllers/Hr/MyHrController.php:1176`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitSurvey` / `ROUTE-1543` at `app/Http/Controllers/Hr/MyHrController.php:1224`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/surveys.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1543` / `submitSurvey`: fields `answers`; success app/Http/Controllers/Hr/MyHrController.php:1241 `return redirect()->back()->with('success', 'Survey response submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/MyHrController.php:1198 `return [`; app/Http/Controllers/Hr/MyHrController.php:1218 `return Inertia::render('hr/my/surveys', [`; app/Http/Controllers/Hr/MyHrController.php:1238 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:1241 `return redirect()->back()->with('success', 'Survey response submitted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/surveys` — `hr.my.surveys` — `App\Http\Controllers\Hr\MyHrController@surveys` — `app/Http/Controllers/Hr/MyHrController.php:1176` — middleware `web, auth`
- `POST hr/my/surveys/{survey}` — `hr.my.surveys.submit` — `App\Http\Controllers\Hr\MyHrController@submitSurvey` — `app/Http/Controllers/Hr/MyHrController.php:1224` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/surveys.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
