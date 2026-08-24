# OPS-CLIENT-LEAVE-EXCURSION: Client Leave Excursion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.update`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-LEAVE-EXCURSION`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST operations/clients/{client}/excursions` (`operations.clients.excursions.store`, action `storeExcursion`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:72-100`; `starts_at`.
3. Invoke only the owning control for `DELETE operations/clients/{client}/excursions/{excursion}` (`operations.clients.excursions.destroy`, action `destroyExcursion`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:123-131`; FormRequest `app/Models/ClientExcursionRequest.php:line unresolved`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT operations/clients/{client}/excursions/{excursion}` (`operations.clients.excursions.update`, action `updateExcursion`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:102-121`; FormRequest `app/Models/ClientExcursionRequest.php:line unresolved`; no exact validation fields extracted.
5. Invoke only the owning control for `POST operations/clients/{client}/leave` (`operations.clients.leave.store`, action `storeLeave`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:14-40`; `starts_on`.
6. Invoke only the owning control for `DELETE operations/clients/{client}/leave/{leave}` (`operations.clients.leave.destroy`, action `destroyLeave`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:62-70`; FormRequest `app/Models/ClientLeaveRequest.php:line unresolved`; no exact validation fields extracted.
7. Invoke only the owning control for `PUT operations/clients/{client}/leave/{leave}` (`operations.clients.leave.update`, action `updateLeave`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:42-60`; FormRequest `app/Models/ClientLeaveRequest.php:line unresolved`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeExcursion` / `ROUTE-1970` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:72`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyExcursion` / `ROUTE-1971` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:123`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateExcursion` / `ROUTE-1972` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:102`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeLeave` / `ROUTE-1999` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:14`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyLeave` / `ROUTE-2000` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:62`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateLeave` / `ROUTE-2001` at `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:42`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1970` / `storeExcursion`: fields `starts_at`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:99 `return back()->with('success', "Excursion #{$excursion->id} captured.");`.
- `ROUTE-1971` / `destroyExcursion`: FormRequest `app/Models/ClientExcursionRequest.php:line unresolved`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:130 `return back()->with('success', "Excursion #{$excursion->id} removed.");`.
- `ROUTE-1972` / `updateExcursion`: FormRequest `app/Models/ClientExcursionRequest.php:line unresolved`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:120 `return back()->with('success', "Excursion #{$excursion->id} updated.");`.
- `ROUTE-1999` / `storeLeave`: fields `starts_on`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:39 `return back()->with('success', "Leave request #{$leave->id} captured.");`.
- `ROUTE-2000` / `destroyLeave`: FormRequest `app/Models/ClientLeaveRequest.php:line unresolved`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:69 `return back()->with('success', "Leave request #{$leave->id} removed.");`.
- `ROUTE-2001` / `updateLeave`: FormRequest `app/Models/ClientLeaveRequest.php:line unresolved`; success app/Http/Controllers/Operations/ClientLeaveExcursionController.php:59 `return back()->with('success', "Leave request #{$leave->id} updated.");`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientLeaveExcursionController.php:89 `$excursion = ClientExcursionRequest::create(array_merge(`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:128 `$excursion->delete();`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:118 `$excursion->save();`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:29 `$leave = ClientLeaveRequest::create(array_merge(`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:67 `$leave->delete();`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:57 `$leave->save();`; responses app/Http/Controllers/Operations/ClientLeaveExcursionController.php:99 `return back()->with('success', "Excursion #{$excursion->id} captured.");`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:130 `return back()->with('success', "Excursion #{$excursion->id} removed.");`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:120 `return back()->with('success', "Excursion #{$excursion->id} updated.");`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:39 `return back()->with('success', "Leave request #{$leave->id} captured.");`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:69 `return back()->with('success', "Leave request #{$leave->id} removed.");`; app/Http/Controllers/Operations/ClientLeaveExcursionController.php:59 `return back()->with('success', "Leave request #{$leave->id} updated.");`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST operations/clients/{client}/excursions` — `operations.clients.excursions.store` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@storeExcursion` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:72` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/excursions/{excursion}` — `operations.clients.excursions.destroy` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@destroyExcursion` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:123` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/excursions/{excursion}` — `operations.clients.excursions.update` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@updateExcursion` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:102` — middleware `web, auth, permission:clients.update`
- `POST operations/clients/{client}/leave` — `operations.clients.leave.store` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@storeLeave` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:14` — middleware `web, auth, permission:clients.update`
- `DELETE operations/clients/{client}/leave/{leave}` — `operations.clients.leave.destroy` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@destroyLeave` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:62` — middleware `web, auth, permission:clients.update`
- `PUT operations/clients/{client}/leave/{leave}` — `operations.clients.leave.update` — `App\Http\Controllers\Operations\ClientLeaveExcursionController@updateLeave` — `app/Http/Controllers/Operations/ClientLeaveExcursionController.php:42` — middleware `web, auth, permission:clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientLeaveExcursionController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
