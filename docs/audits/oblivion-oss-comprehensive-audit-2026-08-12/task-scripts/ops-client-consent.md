# OPS-CLIENT-CONSENT: Client Consent

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.record`, `permission:consents.withdraw|consents.manage`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-CONSENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/consents` (`operations.clients.consents.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.record`, `permission:consents.withdraw|consents.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`, `permission:consents.viewAny`, `permission:consents.record`, `permission:consents.withdraw|consents.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/consents` (`operations.clients.consents.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/consents` (`operations.clients.consents.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientConsentController.php:45-113`; `consent_type_id`.
3. Invoke only the owning control for `POST operations/clients/{client}/consents/{consent}/withdraw` (`operations.clients.consents.withdraw`, action `withdraw`). Source category: **mutation outcome source gap (withdraw)**; controller `app/Http/Controllers/Operations/ClientConsentController.php:115-169`; `withdrawal_reason`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1953` at `app/Http/Controllers/Operations/ClientConsentController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1954` at `app/Http/Controllers/Operations/ClientConsentController.php:45`; it is not runtime-observed.
- **mutation outcome source gap (withdraw)** is applicable only to `withdraw` / `ROUTE-1955` at `app/Http/Controllers/Operations/ClientConsentController.php:115`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/clients/consents/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1954` / `store`: fields `consent_type_id`; success app/Http/Controllers/Operations/ClientConsentController.php:112 `return redirect()->back()->with('success', 'Consent recorded successfully.');`.
- `ROUTE-1955` / `withdraw`: fields `withdrawal_reason`; success app/Http/Controllers/Operations/ClientConsentController.php:168 `return redirect()->back()->with('success', 'Consent withdrawn.');`; failure app/Http/Controllers/Operations/ClientConsentController.php:142 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/ClientConsentController.php:148 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `withdraw`: app/Http/Controllers/Operations/ClientConsentController.php:142 `throw ValidationException::withMessages([`; app/Http/Controllers/Operations/ClientConsentController.php:148 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientConsentController.php:76 `$consent = ClientConsent::create([`; app/Http/Controllers/Operations/ClientConsentController.php:107 `$consent->update(['signed_document_path' => $path]);`; app/Http/Controllers/Operations/ClientConsentController.php:153 `$lockedConsent->update([`; responses app/Http/Controllers/Operations/ClientConsentController.php:37 `return inertia('operations/clients/consents/Index', [`; app/Http/Controllers/Operations/ClientConsentController.php:112 `return redirect()->back()->with('success', 'Consent recorded successfully.');`; app/Http/Controllers/Operations/ClientConsentController.php:139 `return false;`; app/Http/Controllers/Operations/ClientConsentController.php:161 `return true;`; app/Http/Controllers/Operations/ClientConsentController.php:168 `return redirect()->back()->with('success', 'Consent withdrawn.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/consents` — `operations.clients.consents.index` — `App\Http\Controllers\Operations\ClientConsentController@index` — `app/Http/Controllers/Operations/ClientConsentController.php:17` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned, permission:consents.viewAny`
- `POST operations/clients/{client}/consents` — `operations.clients.consents.store` — `App\Http\Controllers\Operations\ClientConsentController@store` — `app/Http/Controllers/Operations/ClientConsentController.php:45` — middleware `web, auth, permission:consents.record`
- `POST operations/clients/{client}/consents/{consent}/withdraw` — `operations.clients.consents.withdraw` — `App\Http\Controllers\Operations\ClientConsentController@withdraw` — `app/Http/Controllers/Operations/ClientConsentController.php:115` — middleware `web, auth, permission:consents.withdraw|consents.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientConsentController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/clients/consents/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
