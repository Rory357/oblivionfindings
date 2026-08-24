# CAP-SITE-SITE-CREDENTIAL-LIBRARY-AUDIT: Site credential library copy and audit

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.view`, `permission:credentials.manage`, `permission:credentials.reveal`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-CREDENTIAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `sites/{site}/credentials` (`sites.credentials.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.view`, `permission:credentials.manage`, `permission:credentials.reveal`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.view`, `permission:credentials.manage`, `permission:credentials.reveal`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/credentials` (`sites.credentials.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD sites/{site}/credentials/{credential}/audit` (`sites.credentials.audit`, action `auditLog`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Sites/SiteCredentialController.php:451-472`.
3. Invoke only the owning control for `POST sites/{site}/credentials` (`sites.credentials.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:38-102`; `label`, `credential_type`, `value`, `username`, `url`.
4. Invoke only the owning control for `DELETE sites/{site}/credentials/{credential}` (`sites.credentials.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:218-238`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT sites/{site}/credentials/{credential}` (`sites.credentials.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:132-216`; `label`, `credential_type`, `value`, `username`, `url`.
6. Invoke only the owning control for `POST sites/{site}/credentials/{credential}/copy` (`sites.credentials.copy`, action `copy`). Source category: **mutation outcome source gap (copy)**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:474-491`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2763` at `app/Http/Controllers/Sites/SiteCredentialController.php:24`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2764` at `app/Http/Controllers/Sites/SiteCredentialController.php:38`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2765` at `app/Http/Controllers/Sites/SiteCredentialController.php:218`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2766` at `app/Http/Controllers/Sites/SiteCredentialController.php:132`; it is not runtime-observed.
- **information presented** is applicable only to `auditLog` / `ROUTE-2767` at `app/Http/Controllers/Sites/SiteCredentialController.php:451`; it is not runtime-observed.
- **mutation outcome source gap (copy)** is applicable only to `copy` / `ROUTE-2768` at `app/Http/Controllers/Sites/SiteCredentialController.php:474`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/sites/credentials/audit.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2764` / `store`: fields `label`, `credential_type`, `value`, `username`, `url`; success app/Http/Controllers/Sites/SiteCredentialController.php:101 `return back(303)->with('success', 'Credential added successfully.');`; failure app/Http/Controllers/Sites/SiteCredentialController.php:41 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `ROUTE-2765` / `destroy`: success app/Http/Controllers/Sites/SiteCredentialController.php:237 `return back(303)->with('success', 'Credential deleted successfully.');`; failure app/Http/Controllers/Sites/SiteCredentialController.php:221 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `ROUTE-2766` / `update`: fields `label`, `credential_type`, `value`, `username`, `url`; success app/Http/Controllers/Sites/SiteCredentialController.php:215 `return back(303)->with('success', 'Credential updated successfully.');`; failure app/Http/Controllers/Sites/SiteCredentialController.php:135 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `ROUTE-2768` / `copy`: failure app/Http/Controllers/Sites/SiteCredentialController.php:477 `$request->user()->canDo('credentials.reveal') || abort(403);`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Sites/SiteCredentialController.php:41 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `destroy`: app/Http/Controllers/Sites/SiteCredentialController.php:221 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `update`: app/Http/Controllers/Sites/SiteCredentialController.php:135 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `copy`: app/Http/Controllers/Sites/SiteCredentialController.php:477 `$request->user()->canDo('credentials.reveal') || abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteCredentialController.php:66 `$credential = SiteCredential::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:91 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:225 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:235 `$credential->delete();`; app/Http/Controllers/Sites/SiteCredentialController.php:191 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:202 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:213 `$credential->update($updateData);`; app/Http/Controllers/Sites/SiteCredentialController.php:480 `SiteCredentialAuditLog::create([`; responses app/Http/Controllers/Sites/SiteCredentialController.php:32 `return redirect()->route('sites.vendors.global', [`; app/Http/Controllers/Sites/SiteCredentialController.php:101 `return back(303)->with('success', 'Credential added successfully.');`; app/Http/Controllers/Sites/SiteCredentialController.php:237 `return back(303)->with('success', 'Credential deleted successfully.');`; app/Http/Controllers/Sites/SiteCredentialController.php:215 `return back(303)->with('success', 'Credential updated successfully.');`; app/Http/Controllers/Sites/SiteCredentialController.php:461 `return inertia('sites/credentials/audit', [`; app/Http/Controllers/Sites/SiteCredentialController.php:490 `return response()->json(['ok' => true]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD sites/{site}/credentials` — `sites.credentials.index` — `App\Http\Controllers\Sites\SiteCredentialController@index` — `app/Http/Controllers/Sites/SiteCredentialController.php:24` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.view`
- `POST sites/{site}/credentials` — `sites.credentials.store` — `App\Http\Controllers\Sites\SiteCredentialController@store` — `app/Http/Controllers/Sites/SiteCredentialController.php:38` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`
- `DELETE sites/{site}/credentials/{credential}` — `sites.credentials.destroy` — `App\Http\Controllers\Sites\SiteCredentialController@destroy` — `app/Http/Controllers/Sites/SiteCredentialController.php:218` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`
- `PUT sites/{site}/credentials/{credential}` — `sites.credentials.update` — `App\Http\Controllers\Sites\SiteCredentialController@update` — `app/Http/Controllers/Sites/SiteCredentialController.php:132` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`
- `GET|HEAD sites/{site}/credentials/{credential}/audit` — `sites.credentials.audit` — `App\Http\Controllers\Sites\SiteCredentialController@auditLog` — `app/Http/Controllers/Sites/SiteCredentialController.php:451` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.view`
- `POST sites/{site}/credentials/{credential}/copy` — `sites.credentials.copy` — `App\Http\Controllers\Sites\SiteCredentialController@copy` — `app/Http/Controllers/Sites/SiteCredentialController.php:474` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.reveal`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteCredentialController.php`.
- Exact render/action page relationships: `resources/js/pages/sites/credentials/audit.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
