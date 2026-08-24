# OPS-CONSENT-REQUEST: Consent Request

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.request`
- Owning module: Operations and rostering
- Legacy family: `OPS-CONSENT-REQUEST`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/consent-requests` (`operations.clients.consent-requests.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.request`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.request`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/consent-requests` (`operations.clients.consent-requests.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/clients/{client}/consent-requests/{consentRequest}` (`operations.clients.consent-requests.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ConsentRequestController.php:129-147`.
3. Use `GET|HEAD operations/clients/{client}/consent-requests/create` (`operations.clients.consent-requests.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Operations/ConsentRequestController.php:51-85`.
4. Invoke only the owning control for `POST operations/clients/{client}/consent-requests` (`operations.clients.consent-requests.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ConsentRequestController.php:87-127`; FormRequest `app/Models/ConsentRequest.php:line unresolved`; `consent_type_id`.
5. Invoke only the owning control for `POST operations/clients/{client}/consent-requests/{consentRequest}/cancel` (`operations.clients.consent-requests.cancel`, action `cancel`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ConsentRequestController.php:149-162`; FormRequest `app/Models/ConsentRequest.php:line unresolved`; `reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1948` at `app/Http/Controllers/Operations/ConsentRequestController.php:28`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1949` at `app/Http/Controllers/Operations/ConsentRequestController.php:87`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1950` at `app/Http/Controllers/Operations/ConsentRequestController.php:129`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancel` / `ROUTE-1951` at `app/Http/Controllers/Operations/ConsentRequestController.php:149`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1952` at `app/Http/Controllers/Operations/ConsentRequestController.php:51`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/consent-requests/Create.tsx`, `resources/js/pages/operations/clients/consent-requests/Index.tsx`, `resources/js/pages/operations/clients/consent-requests/Show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1948` / `index`: FormRequest `app/Models/ConsentRequest.php:line unresolved`.
- `ROUTE-1949` / `store`: FormRequest `app/Models/ConsentRequest.php:line unresolved`; fields `consent_type_id`; success app/Http/Controllers/Operations/ConsentRequestController.php:126 `->with('success', 'Consent request sent to the family portal.');`; failure app/Http/Controllers/Operations/ConsentRequestController.php:113 `return back()->withErrors([`.
- `ROUTE-1950` / `show`: FormRequest `app/Models/ConsentRequest.php:line unresolved`.
- `ROUTE-1951` / `cancel`: FormRequest `app/Models/ConsentRequest.php:line unresolved`; fields `reason`; success app/Http/Controllers/Operations/ConsentRequestController.php:161 `return back()->with('success', 'Consent request cancelled.');`.
- `ROUTE-1952` / `create`: FormRequest `app/Models/ConsentRequest.php:line unresolved`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Operations/ConsentRequestController.php:113 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ConsentRequestController.php:122 `$this->service->create($data, $request->user(), $expiresInDays);`; responses app/Http/Controllers/Operations/ConsentRequestController.php:39 `return inertia('operations/clients/consent-requests/Index', [`; app/Http/Controllers/Operations/ConsentRequestController.php:113 `return back()->withErrors([`; app/Http/Controllers/Operations/ConsentRequestController.php:124 `return redirect()`; app/Http/Controllers/Operations/ConsentRequestController.php:143 `return inertia('operations/clients/consent-requests/Show', [`; app/Http/Controllers/Operations/ConsentRequestController.php:161 `return back()->with('success', 'Consent request cancelled.');`; app/Http/Controllers/Operations/ConsentRequestController.php:72 `return inertia('operations/clients/consent-requests/Create', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/consent-requests` — `operations.clients.consent-requests.index` — `App\Http\Controllers\Operations\ConsentRequestController@index` — `app/Http/Controllers/Operations/ConsentRequestController.php:28` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.viewAny`
- `POST operations/clients/{client}/consent-requests` — `operations.clients.consent-requests.store` — `App\Http\Controllers\Operations\ConsentRequestController@store` — `app/Http/Controllers/Operations/ConsentRequestController.php:87` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.request`
- `GET|HEAD operations/clients/{client}/consent-requests/{consentRequest}` — `operations.clients.consent-requests.show` — `App\Http\Controllers\Operations\ConsentRequestController@show` — `app/Http/Controllers/Operations/ConsentRequestController.php:129` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.viewAny`
- `POST operations/clients/{client}/consent-requests/{consentRequest}/cancel` — `operations.clients.consent-requests.cancel` — `App\Http\Controllers\Operations\ConsentRequestController@cancel` — `app/Http/Controllers/Operations/ConsentRequestController.php:149` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.request`
- `GET|HEAD operations/clients/{client}/consent-requests/create` — `operations.clients.consent-requests.create` — `App\Http\Controllers\Operations\ConsentRequestController@create` — `app/Http/Controllers/Operations/ConsentRequestController.php:51` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.request`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ConsentRequestController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/consent-requests/Create.tsx`, `resources/js/pages/operations/clients/consent-requests/Index.tsx`, `resources/js/pages/operations/clients/consent-requests/Show.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
