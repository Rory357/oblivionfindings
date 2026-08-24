# HR-CONTROLLERS-CAREER-PORTAL: Career Portal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution
- Owning module: Human resources
- Legacy family: `HR-CONTROLLERS-CAREER-PORTAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `careers/application/{token}` (`careers.application.status`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor/job owner not established by route middleware; controller/policy/binding evidence must be reviewed before execution.
- Exact middleware atoms: `web`, `throttle:30,1`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD careers/application/{token}` (`careers.application.status`); the route is exact, but menu visibility and runtime access were not executed.

## Source-applicable states and transitions

- **information presented** is applicable only to `applicationStatus` / `ROUTE-0090` at `app/Http/Controllers/CareerPortalController.php:22`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/careers/application-status.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD careers/application/{token}` — `careers.application.status` — `App\Http\Controllers\CareerPortalController@applicationStatus` — `app/Http/Controllers/CareerPortalController.php:22` — middleware `web, throttle:30,1`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/CareerPortalController.php`.
- Exact render/action page relationships: `resources/js/pages/careers/application-status.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
