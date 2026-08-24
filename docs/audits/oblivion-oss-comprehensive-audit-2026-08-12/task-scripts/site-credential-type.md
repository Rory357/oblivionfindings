# SITE-CREDENTIAL-TYPE: Credential Type

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:credentials.manage`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-CREDENTIAL-TYPE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `credential-types` (`credential-types.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:credentials.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:credentials.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD credential-types` (`credential-types.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `PUT credential-types` (`credential-types.save`, action `bulkSave`). Source category: **mutation outcome source gap (bulkSave)**; controller `app/Http/Controllers/Sites/CredentialTypeController.php:40-110`; `types`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0321` at `app/Http/Controllers/Sites/CredentialTypeController.php:19`; it is not runtime-observed.
- **mutation outcome source gap (bulkSave)** is applicable only to `bulkSave` / `ROUTE-0322` at `app/Http/Controllers/Sites/CredentialTypeController.php:40`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0322` / `bulkSave`: fields `types`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/CredentialTypeController.php:81 `CredentialType::updateOrCreate(`; app/Http/Controllers/Sites/CredentialTypeController.php:104 `$row->delete();`; responses app/Http/Controllers/Sites/CredentialTypeController.php:34 `return response()->json([`; app/Http/Controllers/Sites/CredentialTypeController.php:109 `return response()->json(['ok' => true]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD credential-types` — `credential-types.index` — `App\Http\Controllers\Sites\CredentialTypeController@index` — `app/Http/Controllers/Sites/CredentialTypeController.php:19` — middleware `web, auth, verified, permission:credentials.manage`
- `PUT credential-types` — `credential-types.save` — `App\Http\Controllers\Sites\CredentialTypeController@bulkSave` — `app/Http/Controllers/Sites/CredentialTypeController.php:40` — middleware `web, auth, verified, permission:credentials.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/CredentialTypeController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
