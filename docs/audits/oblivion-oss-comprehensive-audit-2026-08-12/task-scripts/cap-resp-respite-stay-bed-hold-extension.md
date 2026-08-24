# CAP-RESP-RESPITE-STAY-BED-HOLD-EXTENSION: Respite bed hold and stay extension

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`
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

- Actor satisfying exact route middleware `auth`, `permission:respite.stays.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:respite.stays.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD respite/stays/{stay}` (`respite.stays.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST respite/stays/{stay}/bed-hold` (`respite.stays.bed-hold`, action `recordBedHold`). Source category: **created/recorded**; controller `app/Http/Controllers/Respite/RespiteStayController.php:158-196`; `bed_hold_status`, `bed_hold_reason`, `bed_hold_until`, `absence_record`.
3. Invoke only the owning control for `POST respite/stays/{stay}/extend` (`respite.stays.extend`, action `extend`). Source category: **mutation outcome source gap (extend)**; controller `app/Http/Controllers/Respite/RespiteStayController.php:123-156`; `new_end`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `recordBedHold` / `ROUTE-2445` at `app/Http/Controllers/Respite/RespiteStayController.php:158`; it is not runtime-observed.
- **mutation outcome source gap (extend)** is applicable only to `extend` / `ROUTE-2452` at `app/Http/Controllers/Respite/RespiteStayController.php:123`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2445` / `recordBedHold`: fields `bed_hold_status`, `bed_hold_reason`, `bed_hold_until`, `absence_record`; success app/Http/Controllers/Respite/RespiteStayController.php:195 `return back()->with('success', 'Bed hold updated.');`.
- `ROUTE-2452` / `extend`: fields `new_end`; success app/Http/Controllers/Respite/RespiteStayController.php:155 `return back()->with('success', 'Stay extended.');`; failure app/Http/Controllers/Respite/RespiteStayController.php:136 `throw ValidationException::withMessages([`.

## Failure and recovery paths

- `extend`: app/Http/Controllers/Respite/RespiteStayController.php:136 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Respite/RespiteStayController.php:181 `$stay->update([`; app/Http/Controllers/Respite/RespiteStayController.php:141 `$stay->update([`; responses app/Http/Controllers/Respite/RespiteStayController.php:195 `return back()->with('success', 'Bed hold updated.');`; app/Http/Controllers/Respite/RespiteStayController.php:155 `return back()->with('success', 'Stay extended.');`. Runtime persistence and user comprehension were not executed.
- Exact event/job/notification candidates: app/Http/Controllers/Respite/RespiteStayController.php:189 `event(new RespiteEvent('respite.stay.bed_hold_recorded', [`; app/Http/Controllers/Respite/RespiteStayController.php:149 `event(new RespiteEvent('respite.stay.extended', [`. Static dispatch is not delivery proof and downstream ownership remains unexecuted.

## Route/action evidence

- `POST respite/stays/{stay}/bed-hold` — `respite.stays.bed-hold` — `App\Http\Controllers\Respite\RespiteStayController@recordBedHold` — `app/Http/Controllers/Respite/RespiteStayController.php:158` — middleware `web, auth, permission:respite.stays.manage`
- `POST respite/stays/{stay}/extend` — `respite.stays.extend` — `App\Http\Controllers\Respite\RespiteStayController@extend` — `app/Http/Controllers/Respite/RespiteStayController.php:123` — middleware `web, auth, permission:respite.stays.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Respite/RespiteStayController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
