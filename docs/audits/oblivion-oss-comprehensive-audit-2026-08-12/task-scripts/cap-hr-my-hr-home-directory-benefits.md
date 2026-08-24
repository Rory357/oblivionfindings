# CAP-HR-MY-HR-HOME-DIRECTORY-BENEFITS: My HR home directory and benefits

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my` (`hr.my.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Retrieve and understand the exact routed information/file without claiming a mutation.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- No mutation fixture is required.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my` (`hr.my.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/benefits` (`hr.my.benefits`, action `benefits`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:447-485`.
3. Use `GET|HEAD hr/my/directory` (`hr.my.directory`, action `directory`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:951-1000`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1509` at `app/Http/Controllers/Hr/MyHrController.php:160`; it is not runtime-observed.
- **information presented** is applicable only to `benefits` / `ROUTE-1510` at `app/Http/Controllers/Hr/MyHrController.php:447`; it is not runtime-observed.
- **information presented** is applicable only to `directory` / `ROUTE-1512` at `app/Http/Controllers/Hr/MyHrController.php:951`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/benefits.tsx`, `resources/js/pages/hr/my/directory.tsx`, `resources/js/pages/hr/my/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- No exact FormRequest/inline field, success, abort, exception, or withErrors evidence was extracted from the assigned methods; validation and recovery are explicit gaps.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion is limited to  the requested information being presented for the actor's decision. No persisted outcome is claimed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my` — `hr.my.index` — `App\Http\Controllers\Hr\MyHrController@index` — `app/Http/Controllers/Hr/MyHrController.php:160` — middleware `web, auth`
- `GET|HEAD hr/my/benefits` — `hr.my.benefits` — `App\Http\Controllers\Hr\MyHrController@benefits` — `app/Http/Controllers/Hr/MyHrController.php:447` — middleware `web, auth`
- `GET|HEAD hr/my/directory` — `hr.my.directory` — `App\Http\Controllers\Hr\MyHrController@directory` — `app/Http/Controllers/Hr/MyHrController.php:951` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/benefits.tsx`, `resources/js/pages/hr/my/directory.tsx`, `resources/js/pages/hr/my/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
