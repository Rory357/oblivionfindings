# HR-REFERENCE: Reference

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution
- Owning module: Human resources
- Legacy family: `HR-REFERENCE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `careers/references/{token}` (`careers.reference.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution.
- Exact middleware atoms: `web`, `throttle:30,1`, `throttle:10,1`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD careers/references/{token}` (`careers.reference.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST careers/references/{token}` (`careers.reference.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/Careers/ReferenceController.php:44-74`; `responses`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-0095` at `app/Http/Controllers/Careers/ReferenceController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-0096` at `app/Http/Controllers/Careers/ReferenceController.php:44`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/careers/reference-questionnaire.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0096` / `submit`: fields `responses`; success app/Http/Controllers/Careers/ReferenceController.php:73 `->with('success', 'Thank you — your reference has been submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Careers/ReferenceController.php:66 `$reference->update([`; responses app/Http/Controllers/Careers/ReferenceController.php:35 `return Inertia::render('careers/reference-questionnaire', [`; app/Http/Controllers/Careers/ReferenceController.php:50 `return redirect()->route('careers.reference.show', ['token' => $token])`; app/Http/Controllers/Careers/ReferenceController.php:72 `return redirect()->route('careers.reference.show', ['token' => $token])`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD careers/references/{token}` — `careers.reference.show` — `App\Http\Controllers\Careers\ReferenceController@show` — `app/Http/Controllers/Careers/ReferenceController.php:28` — middleware `web, throttle:30,1`
- `POST careers/references/{token}` — `careers.reference.submit` — `App\Http\Controllers\Careers\ReferenceController@submit` — `app/Http/Controllers/Careers/ReferenceController.php:44` — middleware `web, throttle:10,1`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Careers/ReferenceController.php`.
- Exact render/action page relationships: `resources/js/pages/careers/reference-questionnaire.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
