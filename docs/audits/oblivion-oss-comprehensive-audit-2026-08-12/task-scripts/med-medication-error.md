# MED-MEDICATION-ERROR: Medication Error

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATION-ERROR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/errors` (`emar.errors`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.administer.record|clients.update`, `permission:medications.administer.correct|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/errors` (`emar.errors`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/errors` (`emar.errors.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:157-207`; `client_id`, `client_medication_id`, `error_type`, `severity`, `reached_client`, `open_disclosure`, `description`, `immediate_action`, `contributing_factors`, `create_incident`.
3. Invoke only the owning control for `PUT emar/errors/{error}` (`emar.errors.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:209-223`; `error_type`, `severity`, `description`, `immediate_action`, `contributing_factors`.
4. Invoke only the owning control for `POST emar/errors/{error}/close` (`emar.errors.close`, action `close`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:273-291`; `close_note`.
5. Invoke only the owning control for `POST emar/errors/{error}/link-incident` (`emar.errors.link_incident`, action `linkIncident`). Source category: **mutation outcome source gap (linkIncident)**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:301-327`; no exact validation fields extracted.
6. Invoke only the owning control for `POST emar/errors/{error}/resolve` (`emar.errors.resolve`, action `resolve`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:242-266`; `outcome`, `preventive_actions`, `harm_level`.
7. Invoke only the owning control for `POST emar/errors/{error}/review` (`emar.errors.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/Emar/MedicationErrorController.php:225-240`; `review_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0366` at `app/Http/Controllers/Emar/MedicationErrorController.php:94`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0367` at `app/Http/Controllers/Emar/MedicationErrorController.php:157`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0368` at `app/Http/Controllers/Emar/MedicationErrorController.php:209`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `close` / `ROUTE-0369` at `app/Http/Controllers/Emar/MedicationErrorController.php:273`; it is not runtime-observed.
- **mutation outcome source gap (linkIncident)** is applicable only to `linkIncident` / `ROUTE-0370` at `app/Http/Controllers/Emar/MedicationErrorController.php:301`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolve` / `ROUTE-0371` at `app/Http/Controllers/Emar/MedicationErrorController.php:242`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-0372` at `app/Http/Controllers/Emar/MedicationErrorController.php:225`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/MedicationErrors.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0367` / `store`: fields `client_id`, `client_medication_id`, `error_type`, `severity`, `reached_client`, `open_disclosure`, `description`, `immediate_action`, `contributing_factors`, `create_incident`; success app/Http/Controllers/Emar/MedicationErrorController.php:206 `return redirect()->back()->with('success', 'Medication error reported successfully.');`.
- `ROUTE-0368` / `update`: fields `error_type`, `severity`, `description`, `immediate_action`, `contributing_factors`; success app/Http/Controllers/Emar/MedicationErrorController.php:222 `return redirect()->back()->with('success', 'Medication error updated successfully.');`.
- `ROUTE-0369` / `close`: fields `close_note`; success app/Http/Controllers/Emar/MedicationErrorController.php:290 `return redirect()->back()->with('success', 'Error closed out.');`; failure app/Http/Controllers/Emar/MedicationErrorController.php:280 `return redirect()->back()->withErrors(['status' => 'Only a resolved error can be closed out.']);`.
- `ROUTE-0371` / `resolve`: fields `outcome`, `preventive_actions`, `harm_level`; success app/Http/Controllers/Emar/MedicationErrorController.php:265 `return redirect()->back()->with('success', 'Error resolved successfully.');`.
- `ROUTE-0372` / `review`: fields `review_notes`; success app/Http/Controllers/Emar/MedicationErrorController.php:239 `return redirect()->back()->with('success', 'Error reviewed successfully.');`.

## Failure and recovery paths

- `close`: app/Http/Controllers/Emar/MedicationErrorController.php:280 `return redirect()->back()->withErrors(['status' => 'Only a resolved error can be closed out.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/MedicationErrorController.php:179 `$incident = ClientIncident::create([`; app/Http/Controllers/Emar/MedicationErrorController.php:201 `$error = MedicationError::create($validated);`; app/Http/Controllers/Emar/MedicationErrorController.php:220 `$error->update($validated);`; app/Http/Controllers/Emar/MedicationErrorController.php:283 `$error->update([`; app/Http/Controllers/Emar/MedicationErrorController.php:307 `$incident = ClientIncident::create([`; app/Http/Controllers/Emar/MedicationErrorController.php:324 `$error->update(['client_incident_id' => $incident->id]);`; app/Http/Controllers/Emar/MedicationErrorController.php:250 `$error->update([`; app/Http/Controllers/Emar/MedicationErrorController.php:232 `$error->update([`; responses app/Http/Controllers/Emar/MedicationErrorController.php:125 `return [`; app/Http/Controllers/Emar/MedicationErrorController.php:132 `return Inertia::render('emar/MedicationErrors', [`; app/Http/Controllers/Emar/MedicationErrorController.php:206 `return redirect()->back()->with('success', 'Medication error reported successfully.');`; app/Http/Controllers/Emar/MedicationErrorController.php:222 `return redirect()->back()->with('success', 'Medication error updated successfully.');`; app/Http/Controllers/Emar/MedicationErrorController.php:280 `return redirect()->back()->withErrors(['status' => 'Only a resolved error can be closed out.']);`; app/Http/Controllers/Emar/MedicationErrorController.php:290 `return redirect()->back()->with('success', 'Error closed out.');`; app/Http/Controllers/Emar/MedicationErrorController.php:304 `return redirect()->route('incidents.show', $error->client_incident_id);`; app/Http/Controllers/Emar/MedicationErrorController.php:326 `return redirect()->route('incidents.show', $incident);`; app/Http/Controllers/Emar/MedicationErrorController.php:265 `return redirect()->back()->with('success', 'Error resolved successfully.');`; app/Http/Controllers/Emar/MedicationErrorController.php:239 `return redirect()->back()->with('success', 'Error reviewed successfully.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/errors` — `emar.errors` — `App\Http\Controllers\Emar\MedicationErrorController@index` — `app/Http/Controllers/Emar/MedicationErrorController.php:94` — middleware `web, auth, permission:medications.view`
- `POST emar/errors` — `emar.errors.store` — `App\Http\Controllers\Emar\MedicationErrorController@store` — `app/Http/Controllers/Emar/MedicationErrorController.php:157` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `PUT emar/errors/{error}` — `emar.errors.update` — `App\Http\Controllers\Emar\MedicationErrorController@update` — `app/Http/Controllers/Emar/MedicationErrorController.php:209` — middleware `web, auth, permission:medications.administer.correct|clients.update`
- `POST emar/errors/{error}/close` — `emar.errors.close` — `App\Http\Controllers\Emar\MedicationErrorController@close` — `app/Http/Controllers/Emar/MedicationErrorController.php:273` — middleware `web, auth, permission:medications.administer.correct|clients.update`
- `POST emar/errors/{error}/link-incident` — `emar.errors.link_incident` — `App\Http\Controllers\Emar\MedicationErrorController@linkIncident` — `app/Http/Controllers/Emar/MedicationErrorController.php:301` — middleware `web, auth, permission:medications.administer.record|clients.update`
- `POST emar/errors/{error}/resolve` — `emar.errors.resolve` — `App\Http\Controllers\Emar\MedicationErrorController@resolve` — `app/Http/Controllers/Emar/MedicationErrorController.php:242` — middleware `web, auth, permission:medications.administer.correct|clients.update`
- `POST emar/errors/{error}/review` — `emar.errors.review` — `App\Http\Controllers\Emar\MedicationErrorController@review` — `app/Http/Controllers/Emar/MedicationErrorController.php:225` — middleware `web, auth, permission:medications.administer.correct|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/MedicationErrorController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/MedicationErrors.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
