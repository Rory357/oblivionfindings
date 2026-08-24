# CLI-FAMILY-NOTE: Family Note

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Clients and supported people
- Legacy family: `CLI-FAMILY-NOTE`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST clients/{client}/family-notes/{familyNote}/assign-shift` (`client.family-notes.assign-shift`, action `assignToShift`). Source category: **mutation outcome source gap (assignToShift)**; controller `app/Http/Controllers/FamilyNoteController.php:79-144`; `shift_id`.
3. Invoke only the owning control for `POST clients/{client}/family-notes/{familyNote}/respond` (`client.family-notes.respond`, action `respond`). Source category: **mutation outcome source gap (respond)**; controller `app/Http/Controllers/FamilyNoteController.php:20-38`; `staff_response`.
4. Invoke only the owning control for `POST clients/{client}/family-notes/{familyNote}/status` (`client.family-notes.status`, action `updateStatus`). Source category: **updated/revised**; controller `app/Http/Controllers/FamilyNoteController.php:40-77`; no exact validation fields extracted.

## Source-applicable states and transitions

- **mutation outcome source gap (assignToShift)** is applicable only to `assignToShift` / `ROUTE-0153` at `app/Http/Controllers/FamilyNoteController.php:79`; it is not runtime-observed.
- **mutation outcome source gap (respond)** is applicable only to `respond` / `ROUTE-0154` at `app/Http/Controllers/FamilyNoteController.php:20`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateStatus` / `ROUTE-0155` at `app/Http/Controllers/FamilyNoteController.php:40`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0153` / `assignToShift`: fields `shift_id`; success app/Http/Controllers/FamilyNoteController.php:98 `return redirect()->back()->with('success', 'Added to shift checklist.');`; app/Http/Controllers/FamilyNoteController.php:143 `return redirect()->back()->with('success', 'Added to shift checklist.');`; failure app/Http/Controllers/FamilyNoteController.php:102 `throw ValidationException::withMessages([`.
- `ROUTE-0154` / `respond`: fields `staff_response`; success app/Http/Controllers/FamilyNoteController.php:37 `return redirect()->back()->with('success', 'Response added.');`.
- `ROUTE-0155` / `updateStatus`: success app/Http/Controllers/FamilyNoteController.php:76 `return redirect()->back()->with('success', 'Status updated.');`.

## Failure and recovery paths

- `assignToShift`: app/Http/Controllers/FamilyNoteController.php:102 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/FamilyNoteController.php:110 `ShiftTask::create([`; app/Http/Controllers/FamilyNoteController.php:118 `$familyNote->update([`; app/Http/Controllers/FamilyNoteController.php:31 `$familyNote->update([`; app/Http/Controllers/FamilyNoteController.php:74 `$familyNote->update($updates);`; responses app/Http/Controllers/FamilyNoteController.php:98 `return redirect()->back()->with('success', 'Added to shift checklist.');`; app/Http/Controllers/FamilyNoteController.php:143 `return redirect()->back()->with('success', 'Added to shift checklist.');`; app/Http/Controllers/FamilyNoteController.php:37 `return redirect()->back()->with('success', 'Response added.');`; app/Http/Controllers/FamilyNoteController.php:76 `return redirect()->back()->with('success', 'Status updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST clients/{client}/family-notes/{familyNote}/assign-shift` — `client.family-notes.assign-shift` — `App\Http\Controllers\FamilyNoteController@assignToShift` — `app/Http/Controllers/FamilyNoteController.php:79` — middleware `web, auth`
- `POST clients/{client}/family-notes/{familyNote}/respond` — `client.family-notes.respond` — `App\Http\Controllers\FamilyNoteController@respond` — `app/Http/Controllers/FamilyNoteController.php:20` — middleware `web, auth`
- `POST clients/{client}/family-notes/{familyNote}/status` — `client.family-notes.status` — `App\Http\Controllers\FamilyNoteController@updateStatus` — `app/Http/Controllers/FamilyNoteController.php:40` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/FamilyNoteController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
