# CAP-HR-MY-HR-DOCUMENTS-POLICIES: My documents signatures and policy attestations

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Human resources
- Legacy family: `HR-MY-HR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/my/documents` (`hr.my.documents`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/my/documents` (`hr.my.documents`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/my/documents/{document}/download` (`hr.my.documents.download`, action `downloadDocument`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/MyHrController.php:1701-1723`.
3. Use `GET|HEAD hr/my/policies` (`hr.my.policies`, action `policies`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/MyHrController.php:761-822`.
4. Invoke only the owning control for `POST hr/my/documents/sign/{signature}` (`hr.my.documents.sign`, action `signDocument`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/MyHrController.php:1683-1699`; `signature_data`.
5. Invoke only the owning control for `POST hr/my/policies/{policy}/attest` (`hr.my.policies.attest`, action `attestPolicy`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/MyHrController.php:824-842`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `documents` / `ROUTE-1513` at `app/Http/Controllers/Hr/MyHrController.php:1629`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadDocument` / `ROUTE-1514` at `app/Http/Controllers/Hr/MyHrController.php:1701`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `signDocument` / `ROUTE-1515` at `app/Http/Controllers/Hr/MyHrController.php:1683`; it is not runtime-observed.
- **information presented** is applicable only to `policies` / `ROUTE-1535` at `app/Http/Controllers/Hr/MyHrController.php:761`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `attestPolicy` / `ROUTE-1536` at `app/Http/Controllers/Hr/MyHrController.php:824`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/my/documents.tsx`, `resources/js/pages/hr/my/policies.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1515` / `signDocument`: fields `signature_data`; success app/Http/Controllers/Hr/MyHrController.php:1698 `return redirect()->back()->with('success', 'Document signed & filed.');`.
- `ROUTE-1536` / `attestPolicy`: success app/Http/Controllers/Hr/MyHrController.php:841 `return redirect()->back()->with('success', 'Policy attestation recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/MyHrController.php:832 `HrPolicyAttestation::create([`; responses app/Http/Controllers/Hr/MyHrController.php:1673 `return Inertia::render('hr/my/documents', [`; app/Http/Controllers/Hr/MyHrController.php:1722 `return Storage::disk($document->storage_disk)->download($document->storage_path, $filename);`; app/Http/Controllers/Hr/MyHrController.php:1695 `return redirect()->back()->with('error', $e->getMessage());`; app/Http/Controllers/Hr/MyHrController.php:1698 `return redirect()->back()->with('success', 'Document signed & filed.');`; app/Http/Controllers/Hr/MyHrController.php:815 `return $policy;`; app/Http/Controllers/Hr/MyHrController.php:818 `return Inertia::render('hr/my/policies', [`; app/Http/Controllers/Hr/MyHrController.php:841 `return redirect()->back()->with('success', 'Policy attestation recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/my/documents` — `hr.my.documents` — `App\Http\Controllers\Hr\MyHrController@documents` — `app/Http/Controllers/Hr/MyHrController.php:1629` — middleware `web, auth`
- `GET|HEAD hr/my/documents/{document}/download` — `hr.my.documents.download` — `App\Http\Controllers\Hr\MyHrController@downloadDocument` — `app/Http/Controllers/Hr/MyHrController.php:1701` — middleware `web, auth`
- `POST hr/my/documents/sign/{signature}` — `hr.my.documents.sign` — `App\Http\Controllers\Hr\MyHrController@signDocument` — `app/Http/Controllers/Hr/MyHrController.php:1683` — middleware `web, auth`
- `GET|HEAD hr/my/policies` — `hr.my.policies` — `App\Http\Controllers\Hr\MyHrController@policies` — `app/Http/Controllers/Hr/MyHrController.php:761` — middleware `web, auth`
- `POST hr/my/policies/{policy}/attest` — `hr.my.policies.attest` — `App\Http\Controllers\Hr\MyHrController@attestPolicy` — `app/Http/Controllers/Hr/MyHrController.php:824` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/MyHrController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/my/documents.tsx`, `resources/js/pages/hr/my/policies.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
