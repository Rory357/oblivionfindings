# CLI-PORTAL-FAMILY-NOTE: Portal Family Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-PORTAL-FAMILY-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `portal/clients/{client}/family-notes` (`portal.clients.family-notes`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD portal/clients/{client}/family-notes` (`portal.clients.family-notes`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST portal/clients/{client}/family-notes` (`portal.clients.family-notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Portal/PortalFamilyNoteController.php:83-130`; `title`, `description`, `note_type`, `priority`, `due_date`, `due_time`.
3. Invoke only the owning control for `DELETE portal/clients/{client}/family-notes/{familyNote}` (`portal.clients.family-notes.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Portal/PortalFamilyNoteController.php:159-174`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT portal/clients/{client}/family-notes/{familyNote}` (`portal.clients.family-notes.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Portal/PortalFamilyNoteController.php:132-157`; `title`, `description`, `note_type`, `priority`, `due_date`, `due_time`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2255` at `app/Http/Controllers/Portal/PortalFamilyNoteController.php:14`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2256` at `app/Http/Controllers/Portal/PortalFamilyNoteController.php:83`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2257` at `app/Http/Controllers/Portal/PortalFamilyNoteController.php:159`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2258` at `app/Http/Controllers/Portal/PortalFamilyNoteController.php:132`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/portal/family-notes.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2256` / `store`: fields `title`, `description`, `note_type`, `priority`, `due_date`, `due_time`; success app/Http/Controllers/Portal/PortalFamilyNoteController.php:129 `return redirect()->back()->with('success', 'Note created.');`.
- `ROUTE-2257` / `destroy`: success app/Http/Controllers/Portal/PortalFamilyNoteController.php:173 `return redirect()->back()->with('success', 'Note removed.');`.
- `ROUTE-2258` / `update`: fields `title`, `description`, `note_type`, `priority`, `due_date`, `due_time`; success app/Http/Controllers/Portal/PortalFamilyNoteController.php:156 `return redirect()->back()->with('success', 'Note updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Portal/PortalFamilyNoteController.php:102 `$note = FamilyNote::create([`; app/Http/Controllers/Portal/PortalFamilyNoteController.php:171 `$familyNote->delete();`; app/Http/Controllers/Portal/PortalFamilyNoteController.php:154 `$familyNote->update($data);`; responses app/Http/Controllers/Portal/PortalFamilyNoteController.php:72 `return inertia('portal/family-notes', [`; app/Http/Controllers/Portal/PortalFamilyNoteController.php:129 `return redirect()->back()->with('success', 'Note created.');`; app/Http/Controllers/Portal/PortalFamilyNoteController.php:173 `return redirect()->back()->with('success', 'Note removed.');`; app/Http/Controllers/Portal/PortalFamilyNoteController.php:156 `return redirect()->back()->with('success', 'Note updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD portal/clients/{client}/family-notes` — `portal.clients.family-notes` — `App\Http\Controllers\Portal\PortalFamilyNoteController@index` — `app/Http/Controllers/Portal/PortalFamilyNoteController.php:14` — middleware `web, auth`
- `POST portal/clients/{client}/family-notes` — `portal.clients.family-notes.store` — `App\Http\Controllers\Portal\PortalFamilyNoteController@store` — `app/Http/Controllers/Portal/PortalFamilyNoteController.php:83` — middleware `web, auth`
- `DELETE portal/clients/{client}/family-notes/{familyNote}` — `portal.clients.family-notes.destroy` — `App\Http\Controllers\Portal\PortalFamilyNoteController@destroy` — `app/Http/Controllers/Portal/PortalFamilyNoteController.php:159` — middleware `web, auth`
- `PUT portal/clients/{client}/family-notes/{familyNote}` — `portal.clients.family-notes.update` — `App\Http\Controllers\Portal\PortalFamilyNoteController@update` — `app/Http/Controllers/Portal/PortalFamilyNoteController.php:132` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Portal/PortalFamilyNoteController.php`.
- Exact render/action page relationships: `resources/js/pages/portal/family-notes.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
