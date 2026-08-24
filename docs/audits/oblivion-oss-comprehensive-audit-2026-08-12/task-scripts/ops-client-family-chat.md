# OPS-CLIENT-FAMILY-CHAT: Client Family Chat

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`
- Owning module: Operations and rostering
- Legacy family: `OPS-CLIENT-FAMILY-CHAT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/clients/{client}/family-chat` (`operations.clients.family-chat.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Exact middleware atoms: `web`, `auth`, `permission:clients.viewAny|clients.viewAssigned`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/clients/{client}/family-chat` (`operations.clients.family-chat.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST operations/clients/{client}/family-chat` (`operations.clients.family-chat.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ClientFamilyChatController.php:91-125`; `content`.

## Source-applicable states and transitions

- **information presented** is applicable only to `show` / `ROUTE-1973` at `app/Http/Controllers/Operations/ClientFamilyChatController.php:25`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1974` at `app/Http/Controllers/Operations/ClientFamilyChatController.php:91`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1974` / `store`: fields `content`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ClientFamilyChatController.php:103 `OpsConversationParticipant::firstOrCreate([`; app/Http/Controllers/Operations/ClientFamilyChatController.php:108 `OpsMessage::create([`; responses app/Http/Controllers/Operations/ClientFamilyChatController.php:36 `return response()->json([`; app/Http/Controllers/Operations/ClientFamilyChatController.php:72 `return response()->json([`; app/Http/Controllers/Operations/ClientFamilyChatController.php:121 `return back();`; app/Http/Controllers/Operations/ClientFamilyChatController.php:124 `return response()->json(['success' => true]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/clients/{client}/family-chat` — `operations.clients.family-chat.show` — `App\Http\Controllers\Operations\ClientFamilyChatController@show` — `app/Http/Controllers/Operations/ClientFamilyChatController.php:25` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`
- `POST operations/clients/{client}/family-chat` — `operations.clients.family-chat.store` — `App\Http\Controllers\Operations\ClientFamilyChatController@store` — `app/Http/Controllers/Operations/ClientFamilyChatController.php:91` — middleware `web, auth, permission:clients.viewAny|clients.viewAssigned`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ClientFamilyChatController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
