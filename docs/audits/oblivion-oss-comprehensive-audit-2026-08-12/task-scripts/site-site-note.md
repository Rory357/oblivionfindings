# SITE-SITE-NOTE: Site Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:sites.update`
- Owning module: Sites, facilities and catering
- Legacy family: `SITE-SITE-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:sites.update`.
- Exact middleware atoms: `web`, `auth`, `permission:sites.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST sites/{site}/notes` (`sites.notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Sites/SiteNoteController.php:13-31`; `body`.
3. Invoke only the owning control for `DELETE sites/{site}/notes/{note}` (`sites.notes.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Sites/SiteNoteController.php:33-48`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2856` at `app/Http/Controllers/Sites/SiteNoteController.php:13`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-2857` at `app/Http/Controllers/Sites/SiteNoteController.php:33`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2856` / `store`: fields `body`; success app/Http/Controllers/Sites/SiteNoteController.php:30 `return back()->with('success', 'Note added.');`.
- `ROUTE-2857` / `destroy`: success app/Http/Controllers/Sites/SiteNoteController.php:47 `return back()->with('success', 'Note removed.');`; failure app/Http/Controllers/Sites/SiteNoteController.php:38 `abort(404);`.

## Failure and recovery paths

- `destroy`: app/Http/Controllers/Sites/SiteNoteController.php:38 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Sites/SiteNoteController.php:21 `$note = $site->siteNotes()->create([`; app/Http/Controllers/Sites/SiteNoteController.php:41 `$note->delete();`; responses app/Http/Controllers/Sites/SiteNoteController.php:30 `return back()->with('success', 'Note added.');`; app/Http/Controllers/Sites/SiteNoteController.php:47 `return back()->with('success', 'Note removed.');`; audit calls app/Http/Controllers/Sites/SiteNoteController.php:26 `AuditLogger::log('site.note.create', $note, [`; app/Http/Controllers/Sites/SiteNoteController.php:43 `AuditLogger::log('site.note.delete', $note, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST sites/{site}/notes` — `sites.notes.store` — `App\Http\Controllers\Sites\SiteNoteController@store` — `app/Http/Controllers/Sites/SiteNoteController.php:13` — middleware `web, auth, permission:sites.update`
- `DELETE sites/{site}/notes/{note}` — `sites.notes.destroy` — `App\Http\Controllers\Sites\SiteNoteController@destroy` — `app/Http/Controllers/Sites/SiteNoteController.php:33` — middleware `web, auth, permission:sites.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Sites/SiteNoteController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
