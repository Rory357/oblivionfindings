# CAP-SITE-SITE-CREDENTIAL-REVEAL-TOTP: Credential reveal TOTP and access proof

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.reveal`, `permission:credentials.manage`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.reveal`, `permission:credentials.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.reveal`, `permission:credentials.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/credentials` (`sites.credentials.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST sites/{site}/credentials/{credential}/reveal` (`sites.credentials.reveal`, action `reveal`). Source category: **mutation outcome source gap (reveal)**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:104-130`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE sites/{site}/credentials/{credential}/totp` (`sites.credentials.totp.remove`, action `removeTotp`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:339-362`; no exact validation fields extracted.
4. Invoke only the owning control for `POST sites/{site}/credentials/{credential}/totp/code` (`sites.credentials.totp.code`, action `totpCode`). Source category: **mutation outcome source gap (totpCode)**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:301-337`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (reveal)** is applicable only to `reveal` / `ROUTE-2770` at `app/Http/Controllers/Sites/SiteCredentialController.php:104`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `removeTotp` / `ROUTE-2772` at `app/Http/Controllers/Sites/SiteCredentialController.php:339`; it is not runtime-observed.
- **mutation outcome source gap (totpCode)** is applicable only to `totpCode` / `ROUTE-2773` at `app/Http/Controllers/Sites/SiteCredentialController.php:301`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2770` / `reveal`: failure app/Http/Controllers/Sites/SiteCredentialController.php:107 `$request->user()->canDo('credentials.reveal') || abort(403);`.
- `ROUTE-2772` / `removeTotp`: success app/Http/Controllers/Sites/SiteCredentialController.php:361 `return back(303)->with('success', 'Authenticator removed.');`; failure app/Http/Controllers/Sites/SiteCredentialController.php:342 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `ROUTE-2773` / `totpCode`: failure app/Http/Controllers/Sites/SiteCredentialController.php:304 `$request->user()->canDo('credentials.reveal') || abort(403);`; app/Http/Controllers/Sites/SiteCredentialController.php:308 `abort(404, 'No authenticator configured for this credential.');`.

## Failure and recovery paths

- `reveal`: app/Http/Controllers/Sites/SiteCredentialController.php:107 `$request->user()->canDo('credentials.reveal') || abort(403);`.
- `removeTotp`: app/Http/Controllers/Sites/SiteCredentialController.php:342 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `totpCode`: app/Http/Controllers/Sites/SiteCredentialController.php:304 `$request->user()->canDo('credentials.reveal') || abort(403);`; app/Http/Controllers/Sites/SiteCredentialController.php:308 `abort(404, 'No authenticator configured for this credential.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteCredentialController.php:115 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:345 `$credential->update([`; app/Http/Controllers/Sites/SiteCredentialController.php:351 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:322 `SiteCredentialAuditLog::create([`; responses app/Http/Controllers/Sites/SiteCredentialController.php:111 `return $reauthResponse;`; app/Http/Controllers/Sites/SiteCredentialController.php:127 `return response()->json([`; app/Http/Controllers/Sites/SiteCredentialController.php:361 `return back(303)->with('success', 'Authenticator removed.');`; app/Http/Controllers/Sites/SiteCredentialController.php:312 `return $reauthResponse;`; app/Http/Controllers/Sites/SiteCredentialController.php:332 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/credentials/{credential}/reveal` — `sites.credentials.reveal` — `App\Http\Controllers\Sites\SiteCredentialController@reveal` — `app/Http/Controllers/Sites/SiteCredentialController.php:104` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.reveal`
- `DELETE sites/{site}/credentials/{credential}/totp` — `sites.credentials.totp.remove` — `App\Http\Controllers\Sites\SiteCredentialController@removeTotp` — `app/Http/Controllers/Sites/SiteCredentialController.php:339` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`
- `POST sites/{site}/credentials/{credential}/totp/code` — `sites.credentials.totp.code` — `App\Http\Controllers\Sites\SiteCredentialController@totpCode` — `app/Http/Controllers/Sites/SiteCredentialController.php:301` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.reveal`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteCredentialController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
