# CAP-SITE-SITE-CREDENTIAL-ROTATION-REAUTH: Credential rotation and reauthentication controls

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.manage`
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

- Actor satisfying exact route middleware `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:sites.viewAny`, `permission:credentials.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD sites/{site}/credentials` (`sites.credentials.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PATCH sites/{site}/credentials/{credential}/reauth` (`sites.credentials.reauth`, action `toggleReauth`). Source category: **updated/revised**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:273-299`; `requires_reauth`.
3. Invoke only the owning control for `POST sites/{site}/credentials/{credential}/rotate` (`sites.credentials.rotate`, action `rotate`). Source category: **mutation outcome source gap (rotate)**; controller `app/Http/Controllers/Sites/SiteCredentialController.php:245-267`; no exact validation fields extracted.

## Source-applicable states and transitions

- **updated/revised** is applicable only to `toggleReauth` / `ROUTE-2769` at `app/Http/Controllers/Sites/SiteCredentialController.php:273`; it is not runtime-observed.
- **mutation outcome source gap (rotate)** is applicable only to `rotate` / `ROUTE-2771` at `app/Http/Controllers/Sites/SiteCredentialController.php:245`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2769` / `toggleReauth`: fields `requires_reauth`; failure app/Http/Controllers/Sites/SiteCredentialController.php:276 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `ROUTE-2771` / `rotate`: success app/Http/Controllers/Sites/SiteCredentialController.php:266 `return back(303)->with('success', 'Marked as rotated today.');`; failure app/Http/Controllers/Sites/SiteCredentialController.php:248 `$request->user()->canDo('credentials.manage') || abort(403);`.

## Failure and recovery paths

- `toggleReauth`: app/Http/Controllers/Sites/SiteCredentialController.php:276 `$request->user()->canDo('credentials.manage') || abort(403);`.
- `rotate`: app/Http/Controllers/Sites/SiteCredentialController.php:248 `$request->user()->canDo('credentials.manage') || abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteCredentialController.php:283 `$credential->update(['requires_reauth' => $validated['requires_reauth']]);`; app/Http/Controllers/Sites/SiteCredentialController.php:285 `SiteCredentialAuditLog::create([`; app/Http/Controllers/Sites/SiteCredentialController.php:251 `$credential->update([`; app/Http/Controllers/Sites/SiteCredentialController.php:256 `SiteCredentialAuditLog::create([`; responses app/Http/Controllers/Sites/SiteCredentialController.php:295 `return back(303)->with(`; app/Http/Controllers/Sites/SiteCredentialController.php:266 `return back(303)->with('success', 'Marked as rotated today.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `PATCH sites/{site}/credentials/{credential}/reauth` — `sites.credentials.reauth` — `App\Http\Controllers\Sites\SiteCredentialController@toggleReauth` — `app/Http/Controllers/Sites/SiteCredentialController.php:273` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`
- `POST sites/{site}/credentials/{credential}/rotate` — `sites.credentials.rotate` — `App\Http\Controllers\Sites\SiteCredentialController@rotate` — `app/Http/Controllers/Sites/SiteCredentialController.php:245` — middleware `web, auth, verified, permission:sites.viewAny, permission:credentials.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteCredentialController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
