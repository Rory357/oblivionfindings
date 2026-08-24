# CAP-MED-EMAR-HANDOVERS: Medication handover drafting acknowledgement and locking

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:handovers.create|shifts.update|shifts.manageAny|clients.update`, `permission:handovers.viewAny|shifts.update|shifts.viewAssigned|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/handovers` (`emar.handovers`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:handovers.create|shifts.update|shifts.manageAny|clients.update`, `permission:handovers.viewAny|shifts.update|shifts.viewAssigned|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:handovers.create|shifts.update|shifts.manageAny|clients.update`, `permission:handovers.viewAny|shifts.update|shifts.viewAssigned|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/handovers` (`emar.handovers`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/handovers/shift-medications` (`emar.handovers.shift_medications`, action `shiftMedicationSnapshot`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarController.php:4040-4065`.
3. Invoke only the owning control for `POST emar/handovers` (`emar.handovers.store`, action `storeHandover`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3938-3997`; `shift_id`.
4. Invoke only the owning control for `DELETE emar/handovers/{handover}` (`emar.handovers.destroy`, action `destroyHandover`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:4937-4953`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT emar/handovers/{handover}` (`emar.handovers.update`, action `updateHandover`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:4884-4935`; `incoming_shift_id`.
6. Invoke only the owning control for `POST emar/handovers/{handover}/acknowledge` (`emar.handovers.acknowledge`, action `acknowledgeHandover`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Emar/EmarController.php:4016-4031`; no exact validation fields extracted.
7. Invoke only the owning control for `POST emar/handovers/{handover}/lock` (`emar.handovers.lock`, action `lockHandover`). Source category: **mutation outcome source gap (lockHandover)**; controller `app/Http/Controllers/Emar/EmarController.php:4072-4086`; no exact validation fields extracted.
8. Invoke only the owning control for `POST emar/handovers/{handover}/submit` (`emar.handovers.submit`, action `submitHandover`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3999-4014`; no exact validation fields extracted.
9. Invoke only the owning control for `POST emar/handovers/{handover}/unlock` (`emar.handovers.unlock`, action `unlockHandover`). Source category: **mutation outcome source gap (unlockHandover)**; controller `app/Http/Controllers/Emar/EmarController.php:4089-4103`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `handovers` / `ROUTE-0373` at `app/Http/Controllers/Emar/EmarController.php:2833`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeHandover` / `ROUTE-0374` at `app/Http/Controllers/Emar/EmarController.php:3938`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyHandover` / `ROUTE-0375` at `app/Http/Controllers/Emar/EmarController.php:4937`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateHandover` / `ROUTE-0376` at `app/Http/Controllers/Emar/EmarController.php:4884`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeHandover` / `ROUTE-0377` at `app/Http/Controllers/Emar/EmarController.php:4016`; it is not runtime-observed.
- **mutation outcome source gap (lockHandover)** is applicable only to `lockHandover` / `ROUTE-0378` at `app/Http/Controllers/Emar/EmarController.php:4072`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitHandover` / `ROUTE-0379` at `app/Http/Controllers/Emar/EmarController.php:3999`; it is not runtime-observed.
- **mutation outcome source gap (unlockHandover)** is applicable only to `unlockHandover` / `ROUTE-0380` at `app/Http/Controllers/Emar/EmarController.php:4089`; it is not runtime-observed.
- **information presented** is applicable only to `shiftMedicationSnapshot` / `ROUTE-0381` at `app/Http/Controllers/Emar/EmarController.php:4040`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Handovers.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0374` / `storeHandover`: fields `shift_id`; failure app/Http/Controllers/Emar/EmarController.php:3972 `abort(403);`.
- `ROUTE-0375` / `destroyHandover`: success app/Http/Controllers/Emar/EmarController.php:4952 `return redirect()->back()->with('success', 'Medication handover draft deleted.');`.
- `ROUTE-0376` / `updateHandover`: fields `incoming_shift_id`.
- `ROUTE-0377` / `acknowledgeHandover`: success app/Http/Controllers/Emar/EmarController.php:4030 `return redirect()->back()->with('success', 'Medication handover acknowledged.');`.
- `ROUTE-0379` / `submitHandover`: success app/Http/Controllers/Emar/EmarController.php:4013 `return redirect()->back()->with('success', 'Medication handover submitted.');`.
- `ROUTE-0381` / `shiftMedicationSnapshot`: fields `shift_id`.

## Failure and recovery paths

- `storeHandover`: app/Http/Controllers/Emar/EmarController.php:3972 `abort(403);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3975 `$result = $this->handoverService->save($shift, $auth, [`; app/Http/Controllers/Emar/EmarController.php:4950 `$handover->delete();`; app/Http/Controllers/Emar/EmarController.php:4913 `$result = $this->handoverService->save($handover->outgoingShift, $auth, [`; responses app/Http/Controllers/Emar/EmarController.php:2898 `return $client;`; app/Http/Controllers/Emar/EmarController.php:2903 `return Inertia::render('emar/Handovers', [`; app/Http/Controllers/Emar/EmarController.php:3993 `return redirect()->back()->with(`; app/Http/Controllers/Emar/EmarController.php:4952 `return redirect()->back()->with('success', 'Medication handover draft deleted.');`; app/Http/Controllers/Emar/EmarController.php:4931 `return redirect()->back()->with(`; app/Http/Controllers/Emar/EmarController.php:4030 `return redirect()->back()->with('success', 'Medication handover acknowledged.');`; app/Http/Controllers/Emar/EmarController.php:4085 `return response()->json(['locked' => $heldBy === null, 'held_by' => $heldBy]);`; app/Http/Controllers/Emar/EmarController.php:4013 `return redirect()->back()->with('success', 'Medication handover submitted.');`; app/Http/Controllers/Emar/EmarController.php:4102 `return response()->json(['released' => true]);`; app/Http/Controllers/Emar/EmarController.php:4062 `return response()->json([`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/handovers` — `emar.handovers` — `App\Http\Controllers\Emar\EmarController@handovers` — `app/Http/Controllers/Emar/EmarController.php:2833` — middleware `web, auth, permission:medications.view`
- `POST emar/handovers` — `emar.handovers.store` — `App\Http\Controllers\Emar\EmarController@storeHandover` — `app/Http/Controllers/Emar/EmarController.php:3938` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `DELETE emar/handovers/{handover}` — `emar.handovers.destroy` — `App\Http\Controllers\Emar\EmarController@destroyHandover` — `app/Http/Controllers/Emar/EmarController.php:4937` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `PUT emar/handovers/{handover}` — `emar.handovers.update` — `App\Http\Controllers\Emar\EmarController@updateHandover` — `app/Http/Controllers/Emar/EmarController.php:4884` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `POST emar/handovers/{handover}/acknowledge` — `emar.handovers.acknowledge` — `App\Http\Controllers\Emar\EmarController@acknowledgeHandover` — `app/Http/Controllers/Emar/EmarController.php:4016` — middleware `web, auth, permission:handovers.viewAny|shifts.update|shifts.viewAssigned|clients.update`
- `POST emar/handovers/{handover}/lock` — `emar.handovers.lock` — `App\Http\Controllers\Emar\EmarController@lockHandover` — `app/Http/Controllers/Emar/EmarController.php:4072` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `POST emar/handovers/{handover}/submit` — `emar.handovers.submit` — `App\Http\Controllers\Emar\EmarController@submitHandover` — `app/Http/Controllers/Emar/EmarController.php:3999` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `POST emar/handovers/{handover}/unlock` — `emar.handovers.unlock` — `App\Http\Controllers\Emar\EmarController@unlockHandover` — `app/Http/Controllers/Emar/EmarController.php:4089` — middleware `web, auth, permission:handovers.create|shifts.update|shifts.manageAny|clients.update`
- `GET|HEAD emar/handovers/shift-medications` — `emar.handovers.shift_medications` — `App\Http\Controllers\Emar\EmarController@shiftMedicationSnapshot` — `app/Http/Controllers/Emar/EmarController.php:4040` — middleware `web, auth, permission:medications.view`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Handovers.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
