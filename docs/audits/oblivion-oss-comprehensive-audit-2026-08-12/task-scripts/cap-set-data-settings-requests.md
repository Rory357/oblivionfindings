# CAP-SET-DATA-SETTINGS-REQUESTS: Privacy or data request intake

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`
- Owning module: Settings and system access
- Legacy family: `SET-DATA-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `settings/data` (`settings.data`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:settings.access.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:settings.access.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD settings/data` (`settings.data`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST settings/data/requests` (`settings.data.requests.store`, action `storeRequest`). Source category: **created/recorded**; controller `app/Http/Controllers/Settings/DataSettingsController.php:197-248`; `request_type`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeRequest` / `ROUTE-2648` at `app/Http/Controllers/Settings/DataSettingsController.php:197`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2648` / `storeRequest`: fields `request_type`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Settings/DataSettingsController.php:227 `$dsar = DataSubjectRequest::create([`; responses app/Http/Controllers/Settings/DataSettingsController.php:244 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST settings/data/requests` — `settings.data.requests.store` — `App\Http\Controllers\Settings\DataSettingsController@storeRequest` — `app/Http/Controllers/Settings/DataSettingsController.php:197` — middleware `web, auth, permission:settings.access.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Settings/DataSettingsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
