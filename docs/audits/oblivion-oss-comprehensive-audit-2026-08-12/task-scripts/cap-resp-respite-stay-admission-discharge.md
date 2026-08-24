# CAP-RESP-RESPITE-STAY-ADMISSION-DISCHARGE: Respite stay admission check-in and discharge

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`, `permission:respite.viewAny`
- Owning module: Respite
- Legacy family: `RESP-RESPITE-STAY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `respite/stays/{stay}` (`respite.stays.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`, `permission:respite.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.stays.manage`, `permission:respite.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/stays/{stay}` (`respite.stays.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST respite/stays` (`respite.stays.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:39-70`; `booking_id`, `client_id`.
3. Invoke only the owning control for `POST respite/stays/{stay}/check-in` (`respite.stays.checkin`, action `checkIn`). Source category: **mutation outcome source gap (checkIn)**; controller `app/Http/Controllers/Respite/RespiteStayController.php:91-121`; `med_rec_override_reason`, `anaphylaxis_acknowledged`, `epipen_location`, `anaphylaxis_escalation_note`.
4. Invoke only the owning control for `POST respite/stays/{stay}/discharge` (`respite.stays.discharge`, action `discharge`). Source category: **mutation outcome source gap (discharge)**; controller `app/Http/Controllers/Respite/RespiteStayController.php:198-241`; `discharge_summary`, `discharge_reason`, `discharge_medication_reconciliation`, `discharge_medication_reconciliation.medicines_returned_to`, `discharge_medication_reconciliation.count`, `discharge_medication_reconciliation.received_by`, `discharge_medication_reconciliation.changed_during_stay`, `discharge_medication_reconciliation.gp_pharmacy_handover_sent`, `discharge_medication_reconciliation.whanau_briefing_acknowledged`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-2443` at `app/Http/Controllers/Respite/RespiteStayController.php:39`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2444` at `app/Http/Controllers/Respite/RespiteStayController.php:72`; it is not runtime-observed.
- **mutation outcome source gap (checkIn)** is applicable only to `checkIn` / `ROUTE-2446` at `app/Http/Controllers/Respite/RespiteStayController.php:91`; it is not runtime-observed.
- **mutation outcome source gap (discharge)** is applicable only to `discharge` / `ROUTE-2450` at `app/Http/Controllers/Respite/RespiteStayController.php:198`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/respite/stays/show.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2443` / `store`: fields `booking_id`, `client_id`; success app/Http/Controllers/Respite/RespiteStayController.php:69 `->with('success', 'Respite stay created.');`; failure app/Http/Controllers/Respite/RespiteStayController.php:50 `throw ValidationException::withMessages([`.
- `ROUTE-2446` / `checkIn`: fields `med_rec_override_reason`, `anaphylaxis_acknowledged`, `epipen_location`, `anaphylaxis_escalation_note`; success app/Http/Controllers/Respite/RespiteStayController.php:120 `return back()->with('success', 'Stay checked in.');`.
- `ROUTE-2450` / `discharge`: fields `discharge_summary`, `discharge_reason`, `discharge_medication_reconciliation`, `discharge_medication_reconciliation.medicines_returned_to`, `discharge_medication_reconciliation.count`, `discharge_medication_reconciliation.received_by`, `discharge_medication_reconciliation.changed_during_stay`, `discharge_medication_reconciliation.gp_pharmacy_handover_sent`, `discharge_medication_reconciliation.whanau_briefing_acknowledged`; success app/Http/Controllers/Respite/RespiteStayController.php:240 `return back()->with('success', 'Stay discharged.');`.

## Failure and recovery paths

- `store`: app/Http/Controllers/Respite/RespiteStayController.php:50 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteStayController.php:59 `$stay = RespiteStay::create($validated);`; app/Http/Controllers/Respite/RespiteStayController.php:106 `$stay->update([`; app/Http/Controllers/Respite/RespiteStayController.php:219 `$stay->update([`; responses app/Http/Controllers/Respite/RespiteStayController.php:67 `return redirect()`; app/Http/Controllers/Respite/RespiteStayController.php:86 `return Inertia::render('respite/stays/show', [`; app/Http/Controllers/Respite/RespiteStayController.php:120 `return back()->with('success', 'Stay checked in.');`; app/Http/Controllers/Respite/RespiteStayController.php:240 `return back()->with('success', 'Stay discharged.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteStayController.php:61 `event(new RespiteEvent('respite.stay.created', [`; app/Http/Controllers/Respite/RespiteStayController.php:114 `event(new RespiteEvent('respite.stay.checked_in', [`; app/Http/Controllers/Respite/RespiteStayController.php:234 `event(new RespiteEvent('respite.stay.discharged', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/stays` — `respite.stays.store` — `App\Http\Controllers\Respite\RespiteStayController@store` — `app/Http/Controllers/Respite/RespiteStayController.php:39` — middleware `web, auth, permission:respite.stays.manage`
- `GET|HEAD respite/stays/{stay}` — `respite.stays.show` — `App\Http\Controllers\Respite\RespiteStayController@show` — `app/Http/Controllers/Respite/RespiteStayController.php:72` — middleware `web, auth, permission:respite.viewAny`
- `POST respite/stays/{stay}/check-in` — `respite.stays.checkin` — `App\Http\Controllers\Respite\RespiteStayController@checkIn` — `app/Http/Controllers/Respite/RespiteStayController.php:91` — middleware `web, auth, permission:respite.stays.manage`
- `POST respite/stays/{stay}/discharge` — `respite.stays.discharge` — `App\Http\Controllers\Respite\RespiteStayController@discharge` — `app/Http/Controllers/Respite/RespiteStayController.php:198` — middleware `web, auth, permission:respite.stays.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteStayController.php`.
- Exact render/action page relationships: `resources/js/pages/respite/stays/show.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
