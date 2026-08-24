# OPS-SHIFT-NOTE: Shift Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:shifts.viewAny`
- Owning module: Operations and rostering
- Legacy family: `OPS-SHIFT-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `operations/shift-notes` (`operations.shift_notes.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:shifts.viewAny`.
- Exact middleware atoms: `web`, `auth`, `role_scope:my-day`, `permission:shifts.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD operations/shift-notes` (`operations.shift_notes.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD operations/shift-notes/export` (`operations.shift_notes.export`, action `export`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Operations/ShiftNoteController.php:173-228`.
3. Invoke only the owning control for `POST operations/shift-notes` (`operations.shift_notes.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Operations/ShiftNoteController.php:97-133`; `shift_id`.
4. Invoke only the owning control for `PUT operations/shift-notes/{note}` (`operations.shift_notes.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Operations/ShiftNoteController.php:135-171`; `type`.
5. Invoke only the owning control for `PATCH operations/shift-notes/{note}/flag` (`operations.shift_notes.flag`, action `flag`). Source category: **escalated/flagged**; controller `app/Http/Controllers/Operations/ShiftNoteController.php:230-249`; `flagged_reason`.
6. Invoke only the owning control for `PATCH operations/shift-notes/{note}/review` (`operations.shift_notes.review`, action `markReviewed`). Source category: **mutation outcome source gap (markReviewed)**; controller `app/Http/Controllers/Operations/ShiftNoteController.php:251-266`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2185` at `app/Http/Controllers/Operations/ShiftNoteController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2186` at `app/Http/Controllers/Operations/ShiftNoteController.php:97`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2187` at `app/Http/Controllers/Operations/ShiftNoteController.php:135`; it is not runtime-observed.
- **escalated/flagged** is applicable only to `flag` / `ROUTE-2188` at `app/Http/Controllers/Operations/ShiftNoteController.php:230`; it is not runtime-observed.
- **mutation outcome source gap (markReviewed)** is applicable only to `markReviewed` / `ROUTE-2189` at `app/Http/Controllers/Operations/ShiftNoteController.php:251`; it is not runtime-observed.
- **file/report delivered** is applicable only to `export` / `ROUTE-2190` at `app/Http/Controllers/Operations/ShiftNoteController.php:173`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/operations/shift-notes/Index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2185` / `index`: fields `week`.
- `ROUTE-2186` / `store`: fields `shift_id`; success app/Http/Controllers/Operations/ShiftNoteController.php:132 `return redirect()->back()->with('success', 'Shift note added.');`.
- `ROUTE-2187` / `update`: fields `type`; success app/Http/Controllers/Operations/ShiftNoteController.php:170 `return redirect()->back()->with('success', 'Shift note updated.');`.
- `ROUTE-2188` / `flag`: fields `flagged_reason`; success app/Http/Controllers/Operations/ShiftNoteController.php:248 `return redirect()->back()->with('success', $note->is_flagged ? 'Note flagged.' : 'Flag removed.');`.
- `ROUTE-2189` / `markReviewed`: success app/Http/Controllers/Operations/ShiftNoteController.php:265 `return redirect()->back()->with('success', 'Note marked as reviewed.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Operations/ShiftNoteController.php:117 `ClientNote::query()->create([`; app/Http/Controllers/Operations/ShiftNoteController.php:160 `$note->update([`; app/Http/Controllers/Operations/ShiftNoteController.php:243 `$note->update([`; app/Http/Controllers/Operations/ShiftNoteController.php:260 `$note->update([`; responses app/Http/Controllers/Operations/ShiftNoteController.php:79 `return inertia('operations/shift-notes/Index', [`; app/Http/Controllers/Operations/ShiftNoteController.php:132 `return redirect()->back()->with('success', 'Shift note added.');`; app/Http/Controllers/Operations/ShiftNoteController.php:170 `return redirect()->back()->with('success', 'Shift note updated.');`; app/Http/Controllers/Operations/ShiftNoteController.php:248 `return redirect()->back()->with('success', $note->is_flagged ? 'Note flagged.' : 'Flag removed.');`; app/Http/Controllers/Operations/ShiftNoteController.php:265 `return redirect()->back()->with('success', 'Note marked as reviewed.');`; app/Http/Controllers/Operations/ShiftNoteController.php:197 `return $q->whereBetween('created_at', [$start, $end]);`; app/Http/Controllers/Operations/ShiftNoteController.php:224 `return response($csv, 200, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD operations/shift-notes` — `operations.shift_notes.index` — `App\Http\Controllers\Operations\ShiftNoteController@index` — `app/Http/Controllers/Operations/ShiftNoteController.php:26` — middleware `web, auth, role_scope:my-day, permission:shifts.viewAny`
- `POST operations/shift-notes` — `operations.shift_notes.store` — `App\Http\Controllers\Operations\ShiftNoteController@store` — `app/Http/Controllers/Operations/ShiftNoteController.php:97` — middleware `web, auth, permission:shifts.viewAny`
- `PUT operations/shift-notes/{note}` — `operations.shift_notes.update` — `App\Http\Controllers\Operations\ShiftNoteController@update` — `app/Http/Controllers/Operations/ShiftNoteController.php:135` — middleware `web, auth, permission:shifts.viewAny`
- `PATCH operations/shift-notes/{note}/flag` — `operations.shift_notes.flag` — `App\Http\Controllers\Operations\ShiftNoteController@flag` — `app/Http/Controllers/Operations/ShiftNoteController.php:230` — middleware `web, auth, permission:shifts.viewAny`
- `PATCH operations/shift-notes/{note}/review` — `operations.shift_notes.review` — `App\Http\Controllers\Operations\ShiftNoteController@markReviewed` — `app/Http/Controllers/Operations/ShiftNoteController.php:251` — middleware `web, auth, permission:shifts.viewAny`
- `GET|HEAD operations/shift-notes/export` — `operations.shift_notes.export` — `App\Http\Controllers\Operations\ShiftNoteController@export` — `app/Http/Controllers/Operations/ShiftNoteController.php:173` — middleware `web, auth, role_scope:my-day, permission:shifts.viewAny`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Operations/ShiftNoteController.php`.
- Exact render/action page relationships: `resources/js/pages/operations/shift-notes/Index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
