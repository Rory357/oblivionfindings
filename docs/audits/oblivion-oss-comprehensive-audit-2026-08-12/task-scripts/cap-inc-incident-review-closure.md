# CAP-INC-INCIDENT-REVIEW-CLOSURE: Incident submission review closure and reopening

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.approve`, `permission:incidents.reopen`, `permission:incidents.submit`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-INCIDENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `incidents` (`incidents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.approve`, `permission:incidents.reopen`, `permission:incidents.submit`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:incidents.approve`, `permission:incidents.reopen`, `permission:incidents.submit`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD incidents` (`incidents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST incidents/{incident}/close` (`incidents.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/IncidentController.php:908-974`; `closed_outcome`.
3. Invoke only the owning control for `POST incidents/{incident}/reopen` (`incidents.reopen`, action `reopen`). Source category: **mutation outcome source gap (reopen)**; controller `app/Http/Controllers/IncidentController.php:1005-1055`; `reopened_reason`.
4. Invoke only the owning control for `POST incidents/{incident}/review` (`incidents.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/IncidentController.php:862-906`; `review_notes`.
5. Invoke only the owning control for `POST incidents/{incident}/submit` (`incidents.submit`, action `submit`). Source category: **created/recorded**; controller `app/Http/Controllers/IncidentController.php:809-860`; no exact validation fields extracted.

## Source-applicable states and transitions

- **completed/closed/released** is applicable only to `close` / `ROUTE-1846` at `app/Http/Controllers/IncidentController.php:908`; it is not runtime-observed.
- **mutation outcome source gap (reopen)** is applicable only to `reopen` / `ROUTE-1851` at `app/Http/Controllers/IncidentController.php:1005`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-1852` at `app/Http/Controllers/IncidentController.php:862`; it is not runtime-observed.
- **created/recorded** is applicable only to `submit` / `ROUTE-1853` at `app/Http/Controllers/IncidentController.php:809`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1846` / `close`: fields `closed_outcome`; success app/Http/Controllers/IncidentController.php:973 `return back()->with('success', 'Incident closed.');`.
- `ROUTE-1851` / `reopen`: fields `reopened_reason`; success app/Http/Controllers/IncidentController.php:1054 `return back()->with('success', 'Incident reopened.');`.
- `ROUTE-1852` / `review`: fields `review_notes`; success app/Http/Controllers/IncidentController.php:905 `return back()->with('success', 'Incident reviewed.');`.
- `ROUTE-1853` / `submit`: success app/Http/Controllers/IncidentController.php:859 `return back()->with('success', 'Incident submitted.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/IncidentController.php:936 `$incident->update([`; app/Http/Controllers/IncidentController.php:1016 `$incident->update([`; app/Http/Controllers/IncidentController.php:873 `$incident->update([`; app/Http/Controllers/IncidentController.php:813 `$incident->update([`; responses app/Http/Controllers/IncidentController.php:926 `return back()->with('error', 'High-severity incidents require a completed investigation before closure. Open the Investigation section to start one.');`; app/Http/Controllers/IncidentController.php:933 `return back()->with('error', 'There are open follow-ups. Please complete them before closing the incident.');`; app/Http/Controllers/IncidentController.php:973 `return back()->with('success', 'Incident closed.');`; app/Http/Controllers/IncidentController.php:1054 `return back()->with('success', 'Incident reopened.');`; app/Http/Controllers/IncidentController.php:905 `return back()->with('success', 'Incident reviewed.');`; app/Http/Controllers/IncidentController.php:859 `return back()->with('success', 'Incident submitted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST incidents/{incident}/close` — `incidents.close` — `App\Http\Controllers\IncidentController@close` — `app/Http/Controllers/IncidentController.php:908` — middleware `web, auth, verified, permission:incidents.approve`
- `POST incidents/{incident}/reopen` — `incidents.reopen` — `App\Http\Controllers\IncidentController@reopen` — `app/Http/Controllers/IncidentController.php:1005` — middleware `web, auth, verified, permission:incidents.reopen`
- `POST incidents/{incident}/review` — `incidents.review` — `App\Http\Controllers\IncidentController@review` — `app/Http/Controllers/IncidentController.php:862` — middleware `web, auth, verified, permission:incidents.approve`
- `POST incidents/{incident}/submit` — `incidents.submit` — `App\Http\Controllers\IncidentController@submit` — `app/Http/Controllers/IncidentController.php:809` — middleware `web, auth, verified, permission:incidents.submit`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
