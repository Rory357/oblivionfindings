# CLI-CLIENT-NOTE: Client Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:timeline.create`, `permission:timeline.pin|clients.update`
- Owning module: Clients and supported people
- Legacy family: `CLI-CLIENT-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:timeline.create`, `permission:timeline.pin|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:timeline.create`, `permission:timeline.pin|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/notes` (`clients.notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientNoteController.php:13-56`; `type`.
3. Invoke only the owning control for `POST clients/{client}/notes/{note}/pin` (`clients.notes.pin`, action `togglePin`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientNoteController.php:58-79`; no exact validation fields extracted.
4. Invoke only the owning control for `POST operations/clients/{client}/notes` (`operations.clients.notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/ClientNoteController.php:13-56`; `type`.
5. Invoke only the owning control for `POST operations/clients/{client}/notes/{note}/pin` (`operations.clients.notes.pin`, action `togglePin`). Source category: **updated/revised**; controller `app/Http/Controllers/ClientNoteController.php:58-79`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0181` at `app/Http/Controllers/ClientNoteController.php:13`; it is not runtime-observed.
- **updated/revised** is applicable only to `togglePin` / `ROUTE-0182` at `app/Http/Controllers/ClientNoteController.php:58`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2024` at `app/Http/Controllers/ClientNoteController.php:13`; it is not runtime-observed.
- **updated/revised** is applicable only to `togglePin` / `ROUTE-2025` at `app/Http/Controllers/ClientNoteController.php:58`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0181` / `store`: fields `type`.
- `ROUTE-2024` / `store`: fields `type`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/ClientNoteController.php:42 `$note = ClientNote::create([`; app/Http/Controllers/ClientNoteController.php:68 `$note->update([`; app/Http/Controllers/ClientNoteController.php:76 `->update(['is_pinned' => $note->is_pinned]);`; responses app/Http/Controllers/ClientNoteController.php:55 `return back()->with('status', 'Note added.');`; app/Http/Controllers/ClientNoteController.php:78 `return back()->with('status', $note->is_pinned ? 'Pinned to handover.' : 'Unpinned.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/notes` — `clients.notes.store` — `App\Http\Controllers\ClientNoteController@store` — `app/Http/Controllers/ClientNoteController.php:13` — middleware `web, auth, permission:timeline.create`
- `POST clients/{client}/notes/{note}/pin` — `clients.notes.pin` — `App\Http\Controllers\ClientNoteController@togglePin` — `app/Http/Controllers/ClientNoteController.php:58` — middleware `web, auth, permission:timeline.pin|clients.update`
- `POST operations/clients/{client}/notes` — `operations.clients.notes.store` — `App\Http\Controllers\ClientNoteController@store` — `app/Http/Controllers/ClientNoteController.php:13` — middleware `web, auth, permission:timeline.create`
- `POST operations/clients/{client}/notes/{note}/pin` — `operations.clients.notes.pin` — `App\Http\Controllers\ClientNoteController@togglePin` — `app/Http/Controllers/ClientNoteController.php:58` — middleware `web, auth, permission:timeline.pin|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/ClientNoteController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
