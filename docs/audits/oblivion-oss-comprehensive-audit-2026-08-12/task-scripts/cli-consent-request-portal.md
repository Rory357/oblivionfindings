# CLI-CONSENT-REQUEST-PORTAL: Consent Request Portal

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-CONSENT-REQUEST-PORTAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/consent-requests/{consentRequest}` (`portal.clients.consent-requests.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/consent-requests/{consentRequest}` (`portal.clients.consent-requests.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/clients/{client}/consent-requests/{consentRequest}/approve` (`portal.clients.consent-requests.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Portal/ConsentRequestPortalController.php:69-88`; FormRequest `app/Models/ConsentRequest.php:line unresolved`; `response_notes`.
3. Invoke only the owning control for `POST portal/clients/{client}/consent-requests/{consentRequest}/decline` (`portal.clients.consent-requests.decline`, action `decline`). Source category: **rejected/returned**; controller `app/Http/Controllers/Portal/ConsentRequestPortalController.php:90-108`; FormRequest `app/Models/ConsentRequest.php:line unresolved`; `response_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-2248` at `app/Http/Controllers/Portal/ConsentRequestPortalController.php:28`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2249` at `app/Http/Controllers/Portal/ConsentRequestPortalController.php:69`; it is not runtime-observed.
- **rejected/returned** is applicable only to `decline` / `ROUTE-2250` at `app/Http/Controllers/Portal/ConsentRequestPortalController.php:90`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/consent-requests/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2248` / `show`: FormRequest `app/Models/ConsentRequest.php:line unresolved`.
- `ROUTE-2249` / `approve`: FormRequest `app/Models/ConsentRequest.php:line unresolved`; fields `response_notes`; success app/Http/Controllers/Portal/ConsentRequestPortalController.php:87 `->with('success', 'Consent recorded. Thank you.');`.
- `ROUTE-2250` / `decline`: FormRequest `app/Models/ConsentRequest.php:line unresolved`; fields `response_notes`; success app/Http/Controllers/Portal/ConsentRequestPortalController.php:107 `->with('success', 'The care team has been notified of your response.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Portal/ConsentRequestPortalController.php:39 `return inertia('portal/consent-requests/Show', [`; app/Http/Controllers/Portal/ConsentRequestPortalController.php:85 `return redirect()`; app/Http/Controllers/Portal/ConsentRequestPortalController.php:105 `return redirect()`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/consent-requests/{consentRequest}` — `portal.clients.consent-requests.show` — `App\Http\Controllers\Portal\ConsentRequestPortalController@show` — `app/Http/Controllers/Portal/ConsentRequestPortalController.php:28` — middleware `web, auth`
- `POST portal/clients/{client}/consent-requests/{consentRequest}/approve` — `portal.clients.consent-requests.approve` — `App\Http\Controllers\Portal\ConsentRequestPortalController@approve` — `app/Http/Controllers/Portal/ConsentRequestPortalController.php:69` — middleware `web, auth`
- `POST portal/clients/{client}/consent-requests/{consentRequest}/decline` — `portal.clients.consent-requests.decline` — `App\Http\Controllers\Portal\ConsentRequestPortalController@decline` — `app/Http/Controllers/Portal/ConsentRequestPortalController.php:90` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/ConsentRequestPortalController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/consent-requests/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
