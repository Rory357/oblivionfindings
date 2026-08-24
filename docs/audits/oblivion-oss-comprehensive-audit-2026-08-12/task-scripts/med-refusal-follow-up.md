# MED-REFUSAL-FOLLOW-UP: Refusal Follow Up

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-REFUSAL-FOLLOW-UP`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST emar/refusal-followups` (`emar.refusal_followups.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/RefusalFollowUpController.php:17-63`; `client_id`.
3. Invoke only the owning control for `POST emar/refusal-followups/{followup}/complete` (`emar.refusal_followups.complete`, action `complete`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/RefusalFollowUpController.php:68-89`; `outcome`.
4. Invoke only the owning control for `POST emar/refusal-followups/{followup}/notify-gp` (`emar.refusal_followups.notify_gp`, action `notifyGp`). Source category: **mutation outcome source gap (notifyGp)**; controller `app/Http/Controllers/Emar/RefusalFollowUpController.php:94-107`; `gp_response`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-0404` at `app/Http/Controllers/Emar/RefusalFollowUpController.php:17`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `complete` / `ROUTE-0405` at `app/Http/Controllers/Emar/RefusalFollowUpController.php:68`; it is not runtime-observed.
- **mutation outcome source gap (notifyGp)** is applicable only to `notifyGp` / `ROUTE-0406` at `app/Http/Controllers/Emar/RefusalFollowUpController.php:94`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0404` / `store`: fields `client_id`; success app/Http/Controllers/Emar/RefusalFollowUpController.php:62 `return redirect()->back()->with('success', 'Refusal follow-up recorded successfully.');`.
- `ROUTE-0405` / `complete`: fields `outcome`; success app/Http/Controllers/Emar/RefusalFollowUpController.php:88 `return redirect()->back()->with('success', 'Follow-up marked as completed.');`.
- `ROUTE-0406` / `notifyGp`: fields `gp_response`; success app/Http/Controllers/Emar/RefusalFollowUpController.php:106 `return redirect()->back()->with('success', 'GP notification recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/RefusalFollowUpController.php:55 `$followup = MedicationRefusalFollowup::create($validated);`; app/Http/Controllers/Emar/RefusalFollowUpController.php:76 `$followup->update([`; app/Http/Controllers/Emar/RefusalFollowUpController.php:100 `$followup->update([`; responses app/Http/Controllers/Emar/RefusalFollowUpController.php:62 `return redirect()->back()->with('success', 'Refusal follow-up recorded successfully.');`; app/Http/Controllers/Emar/RefusalFollowUpController.php:88 `return redirect()->back()->with('success', 'Follow-up marked as completed.');`; app/Http/Controllers/Emar/RefusalFollowUpController.php:106 `return redirect()->back()->with('success', 'GP notification recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST emar/refusal-followups` — `emar.refusal_followups.store` — `App\Http\Controllers\Emar\RefusalFollowUpController@store` — `app/Http/Controllers/Emar/RefusalFollowUpController.php:17` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `POST emar/refusal-followups/{followup}/complete` — `emar.refusal_followups.complete` — `App\Http\Controllers\Emar\RefusalFollowUpController@complete` — `app/Http/Controllers/Emar/RefusalFollowUpController.php:68` — middleware `web, auth, permission:medications.administer.correct|clients.update`
- `POST emar/refusal-followups/{followup}/notify-gp` — `emar.refusal_followups.notify_gp` — `App\Http\Controllers\Emar\RefusalFollowUpController@notifyGp` — `app/Http/Controllers/Emar/RefusalFollowUpController.php:94` — middleware `web, auth, permission:medications.administer.correct|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/RefusalFollowUpController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
