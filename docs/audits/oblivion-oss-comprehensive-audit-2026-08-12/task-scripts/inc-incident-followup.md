# INC-INCIDENT-FOLLOWUP: Incident Followup

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.followups.manage`, `permission:incidents.followups.complete|incidents.followups.manage`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-INCIDENT-FOLLOWUP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `verified`, `permission:incidents.followups.manage`, `permission:incidents.followups.complete|incidents.followups.manage`.
- Exact middleware atoms: `web`, `auth`, `verified`, `permission:incidents.followups.manage`, `permission:incidents.followups.complete|incidents.followups.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST incidents/{incident}/followups` (`incidents.followups.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/IncidentFollowupController.php:13-62`; `assigned_to_user_id`.
3. Invoke only the owning control for `PUT incidents/{incident}/followups/{followup}` (`incidents.followups.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/IncidentFollowupController.php:64-111`; `assigned_to_user_id`.
4. Invoke only the owning control for `POST incidents/{incident}/followups/{followup}/complete` (`incidents.followups.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/IncidentFollowupController.php:113-159`; `notes`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1848` at `app/Http/Controllers/IncidentFollowupController.php:13`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1849` at `app/Http/Controllers/IncidentFollowupController.php:64`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-1850` at `app/Http/Controllers/IncidentFollowupController.php:113`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1848` / `store`: fields `assigned_to_user_id`; success app/Http/Controllers/IncidentFollowupController.php:61 `return back()->with('success', 'Follow-up created.');`.
- `ROUTE-1849` / `update`: fields `assigned_to_user_id`; success app/Http/Controllers/IncidentFollowupController.php:110 `return back()->with('success', 'Follow-up updated.');`.
- `ROUTE-1850` / `complete`: fields `notes`; success app/Http/Controllers/IncidentFollowupController.php:158 `return back()->with('success', 'Follow-up completed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/IncidentFollowupController.php:24 `$followup = IncidentFollowup::create([`; app/Http/Controllers/IncidentFollowupController.php:79 `$followup->update($data);`; app/Http/Controllers/IncidentFollowupController.php:123 `$followup->update([`; responses app/Http/Controllers/IncidentFollowupController.php:61 `return back()->with('success', 'Follow-up created.');`; app/Http/Controllers/IncidentFollowupController.php:110 `return back()->with('success', 'Follow-up updated.');`; app/Http/Controllers/IncidentFollowupController.php:158 `return back()->with('success', 'Follow-up completed.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST incidents/{incident}/followups` — `incidents.followups.store` — `App\Http\Controllers\IncidentFollowupController@store` — `app/Http/Controllers/IncidentFollowupController.php:13` — middleware `web, auth, verified, permission:incidents.followups.manage`
- `PUT incidents/{incident}/followups/{followup}` — `incidents.followups.update` — `App\Http\Controllers\IncidentFollowupController@update` — `app/Http/Controllers/IncidentFollowupController.php:64` — middleware `web, auth, verified, permission:incidents.followups.manage`
- `POST incidents/{incident}/followups/{followup}/complete` — `incidents.followups.complete` — `App\Http\Controllers\IncidentFollowupController@complete` — `app/Http/Controllers/IncidentFollowupController.php:113` — middleware `web, auth, verified, permission:incidents.followups.complete|incidents.followups.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/IncidentFollowupController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
