# OPS-CLIENT-VISIT-REQUEST: Client Visit Request

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-VISIT-REQUEST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/visit-requests` (`client.visit-requests.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/visit-requests` (`client.visit-requests.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/visit-requests/{visit}/approve` (`client.visit-requests.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/ClientVisitRequestController.php:54-87`; FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; `review_notes`.
3. Invoke only the owning control for `POST operations/clients/{client}/visit-requests/{visit}/decline` (`client.visit-requests.decline`, action `decline`). Source category: **rejected/returned**; controller `app/Http/Controllers/ClientVisitRequestController.php:89-122`; FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; `review_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2053` at `app/Http/Controllers/ClientVisitRequestController.php:12`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2054` at `app/Http/Controllers/ClientVisitRequestController.php:54`; it is not runtime-observed.
- **rejected/returned** is applicable only to `decline` / `ROUTE-2055` at `app/Http/Controllers/ClientVisitRequestController.php:89`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/visit-requests.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2053` / `index`: FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`.
- `ROUTE-2054` / `approve`: FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; fields `review_notes`; success app/Http/Controllers/ClientVisitRequestController.php:86 `return redirect()->back()->with('success', 'Visit request approved.');`.
- `ROUTE-2055` / `decline`: FormRequest `app/Models/FamilyVisitRequest.php:line unresolved`; fields `review_notes`; success app/Http/Controllers/ClientVisitRequestController.php:121 `return redirect()->back()->with('success', 'Visit request declined.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientVisitRequestController.php:64 `$visit->update([`; app/Http/Controllers/ClientVisitRequestController.php:99 `$visit->update([`; responses app/Http/Controllers/ClientVisitRequestController.php:42 `return inertia('operations/clients/visit-requests', [`; app/Http/Controllers/ClientVisitRequestController.php:86 `return redirect()->back()->with('success', 'Visit request approved.');`; app/Http/Controllers/ClientVisitRequestController.php:121 `return redirect()->back()->with('success', 'Visit request declined.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/visit-requests` — `client.visit-requests.index` — `App\Http\Controllers\ClientVisitRequestController@index` — `app/Http/Controllers/ClientVisitRequestController.php:12` — middleware `web, auth`
- `POST operations/clients/{client}/visit-requests/{visit}/approve` — `client.visit-requests.approve` — `App\Http\Controllers\ClientVisitRequestController@approve` — `app/Http/Controllers/ClientVisitRequestController.php:54` — middleware `web, auth`
- `POST operations/clients/{client}/visit-requests/{visit}/decline` — `client.visit-requests.decline` — `App\Http\Controllers\ClientVisitRequestController@decline` — `app/Http/Controllers/ClientVisitRequestController.php:89` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientVisitRequestController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/visit-requests.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
