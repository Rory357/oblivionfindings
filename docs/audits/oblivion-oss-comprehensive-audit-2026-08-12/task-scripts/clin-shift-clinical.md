# CLIN-SHIFT-CLINICAL: Shift Clinical

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: Health and clinical
- Legacy family: `CLIN-SHIFT-CLINICAL`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `shifts/{shift}/clinical/observations/due` (`shifts.clinical.observations.due`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD shifts/{shift}/clinical/observations/due` (`shifts.clinical.observations.due`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST shifts/{shift}/clinical/events` (`shifts.clinical.events.store`, action `storeEvent`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ShiftClinicalController.php:132-180`; `event_type`.
3. Invoke only the owning control for `POST shifts/{shift}/clinical/observations` (`shifts.clinical.observations.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Clinical/ShiftClinicalController.php:52-100`; `client_id`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeEvent` / `ROUTE-2715` at `app/Http/Controllers/Clinical/ShiftClinicalController.php:132`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2716` at `app/Http/Controllers/Clinical/ShiftClinicalController.php:52`; it is not runtime-observed.
- **information presented** is applicable only to `dueObservations` / `ROUTE-2717` at `app/Http/Controllers/Clinical/ShiftClinicalController.php:29`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-2715` / `storeEvent`: fields `event_type`; success app/Http/Controllers/Clinical/ShiftClinicalController.php:179 `return back()->with('success', 'Clinical event recorded successfully.');`; failure app/Http/Controllers/Clinical/ShiftClinicalController.php:139 `abort(403);`; app/Http/Controllers/Clinical/ShiftClinicalController.php:159 `abort(422, 'Shift has no associated client.');`.
- `ROUTE-2716` / `store`: fields `client_id`; success app/Http/Controllers/Clinical/ShiftClinicalController.php:99 `return back()->with('success', $type->label() . ' recorded successfully.');`; failure app/Http/Controllers/Clinical/ShiftClinicalController.php:59 `abort(403);`; app/Http/Controllers/Clinical/ShiftClinicalController.php:74 `abort(403, 'Clinical observation permission required for ' . $type->label());`; app/Http/Controllers/Clinical/ShiftClinicalController.php:86 `} catch (ValidationException $e) {`; app/Http/Controllers/Clinical/ShiftClinicalController.php:87 `throw $e;`.

## Failure and recovery paths

- `storeEvent`: app/Http/Controllers/Clinical/ShiftClinicalController.php:139 `abort(403);`; app/Http/Controllers/Clinical/ShiftClinicalController.php:159 `abort(422, 'Shift has no associated client.');`.
- `store`: app/Http/Controllers/Clinical/ShiftClinicalController.php:59 `abort(403);`; app/Http/Controllers/Clinical/ShiftClinicalController.php:74 `abort(403, 'Clinical observation permission required for ' . $type->label());`; app/Http/Controllers/Clinical/ShiftClinicalController.php:86 `} catch (ValidationException $e) {`; app/Http/Controllers/Clinical/ShiftClinicalController.php:87 `throw $e;`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Clinical/ShiftClinicalController.php:170 `return response()->json([`; app/Http/Controllers/Clinical/ShiftClinicalController.php:179 `return back()->with('success', 'Clinical event recorded successfully.');`; app/Http/Controllers/Clinical/ShiftClinicalController.php:91 `return response()->json([`; app/Http/Controllers/Clinical/ShiftClinicalController.php:99 `return back()->with('success', $type->label() . ' recorded successfully.');`; app/Http/Controllers/Clinical/ShiftClinicalController.php:35 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST shifts/{shift}/clinical/events` — `shifts.clinical.events.store` — `App\Http\Controllers\Clinical\ShiftClinicalController@storeEvent` — `app/Http/Controllers/Clinical/ShiftClinicalController.php:132` — middleware `web, auth`
- `POST shifts/{shift}/clinical/observations` — `shifts.clinical.observations.store` — `App\Http\Controllers\Clinical\ShiftClinicalController@store` — `app/Http/Controllers/Clinical/ShiftClinicalController.php:52` — middleware `web, auth`
- `GET|HEAD shifts/{shift}/clinical/observations/due` — `shifts.clinical.observations.due` — `App\Http\Controllers\Clinical\ShiftClinicalController@dueObservations` — `app/Http/Controllers/Clinical/ShiftClinicalController.php:29` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Clinical/ShiftClinicalController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
