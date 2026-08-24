# CAP-INC-SAFEGUARDING-CONCERN-REPORT-TRIAGE: Safeguarding concern reporting sensitivity assignment and triage

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:safeguarding.viewAny`, `permission:safeguarding.create`
- Owning module: Incidents and safeguarding
- Legacy family: `INC-SAFEGUARDING-CONCERN`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `safeguarding` (`safeguarding.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:safeguarding.viewAny`, `permission:safeguarding.create`.
- Exact middleware atoms: `web`, `auth`, `permission:safeguarding.viewAny`, `permission:safeguarding.create`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD safeguarding` (`safeguarding.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD safeguarding/{concern}` (`safeguarding.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SafeguardingConcernController.php:252-261`.
3. Use `GET|HEAD safeguarding/{concern}/edit` (`safeguarding.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SafeguardingConcernController.php:270-275`.
4. Use `GET|HEAD safeguarding/create` (`safeguarding.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/SafeguardingConcernController.php:186-191`.
5. Invoke only the owning control for `POST safeguarding` (`safeguarding.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/SafeguardingConcernController.php:196-242`; `subject_type`, `subject_id`, `subject_name`, `concern_type`, `abuse_category`, `severity`, `description`, `occurred_at`, `location`, `alleged_perpetrator_type`, `alleged_perpetrator_id`, `alleged_perpetrator_name`, `alleged_perpetrator_details`, `reporter_notes`, `witnesses`, `immediate_actions`, `requires_external_referral`, `is_sensitive`, `site_id`, `related_incident_id`.
6. Invoke only the owning control for `PUT safeguarding/{concern}` (`safeguarding.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingConcernController.php:280-314`; `subject_type`, `subject_id`, `subject_name`, `concern_type`, `abuse_category`, `severity`, `description`, `occurred_at`, `location`, `alleged_perpetrator_type`, `alleged_perpetrator_id`, `alleged_perpetrator_name`, `alleged_perpetrator_details`, `witnesses`, `immediate_actions`, `requires_external_referral`, `protective_measures`, `site_id`.
7. Invoke only the owning control for `POST safeguarding/{concern}/assign` (`safeguarding.assign`, action `assign`). Source category: **assigned**; controller `app/Http/Controllers/SafeguardingConcernController.php:319-334`; `assigned_to_user_id`.
8. Invoke only the owning control for `POST safeguarding/{concern}/sensitivity` (`safeguarding.setSensitivity`, action `setSensitivity`). Source category: **updated/revised**; controller `app/Http/Controllers/SafeguardingConcernController.php:524-538`; `is_sensitive`.
9. Invoke only the owning control for `POST safeguarding/{concern}/subject-informed` (`safeguarding.markSubjectInformed`, action `markSubjectInformed`). Source category: **mutation outcome source gap (markSubjectInformed)**; controller `app/Http/Controllers/SafeguardingConcernController.php:506-517`; no exact validation fields extracted.
10. Invoke only the owning control for `POST safeguarding/{concern}/triage` (`safeguarding.triage`, action `triage`). Source category: **mutation outcome source gap (triage)**; controller `app/Http/Controllers/SafeguardingConcernController.php:370-447`; `substantiation`, `initial_risk`, `lead_user_id`, `path`, `notes`, `investigation_type`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2502` at `app/Http/Controllers/SafeguardingConcernController.php:24`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2503` at `app/Http/Controllers/SafeguardingConcernController.php:196`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2504` at `app/Http/Controllers/SafeguardingConcernController.php:252`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2505` at `app/Http/Controllers/SafeguardingConcernController.php:280`; it is not runtime-observed.
- **assigned** is applicable only to `assign` / `ROUTE-2509` at `app/Http/Controllers/SafeguardingConcernController.php:319`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2514` at `app/Http/Controllers/SafeguardingConcernController.php:270`; it is not runtime-observed.
- **updated/revised** is applicable only to `setSensitivity` / `ROUTE-2520` at `app/Http/Controllers/SafeguardingConcernController.php:524`; it is not runtime-observed.
- **mutation outcome source gap (markSubjectInformed)** is applicable only to `markSubjectInformed` / `ROUTE-2522` at `app/Http/Controllers/SafeguardingConcernController.php:506`; it is not runtime-observed.
- **mutation outcome source gap (triage)** is applicable only to `triage` / `ROUTE-2523` at `app/Http/Controllers/SafeguardingConcernController.php:370`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2524` at `app/Http/Controllers/SafeguardingConcernController.php:186`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/safeguarding/concern.tsx`, `resources/js/pages/safeguarding/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2503` / `store`: fields `subject_type`, `subject_id`, `subject_name`, `concern_type`, `abuse_category`, `severity`, `description`, `occurred_at`, `location`, `alleged_perpetrator_type`, `alleged_perpetrator_id`, `alleged_perpetrator_name`, `alleged_perpetrator_details`, `reporter_notes`, `witnesses`, `immediate_actions`, `requires_external_referral`, `is_sensitive`, `site_id`, `related_incident_id`; success app/Http/Controllers/SafeguardingConcernController.php:240 `->with('success', 'Safeguarding concern raised — reference ' . $concern->reference_number . '.')`.
- `ROUTE-2505` / `update`: fields `subject_type`, `subject_id`, `subject_name`, `concern_type`, `abuse_category`, `severity`, `description`, `occurred_at`, `location`, `alleged_perpetrator_type`, `alleged_perpetrator_id`, `alleged_perpetrator_name`, `alleged_perpetrator_details`, `witnesses`, `immediate_actions`, `requires_external_referral`, `protective_measures`, `site_id`; success app/Http/Controllers/SafeguardingConcernController.php:313 `->with('success', 'Safeguarding concern updated successfully.');`.
- `ROUTE-2509` / `assign`: fields `assigned_to_user_id`; success app/Http/Controllers/SafeguardingConcernController.php:333 `return back()->with('success', 'Concern assigned successfully.');`.
- `ROUTE-2520` / `setSensitivity`: fields `is_sensitive`; success app/Http/Controllers/SafeguardingConcernController.php:535 `return back()->with('success', $validated['is_sensitive']`.
- `ROUTE-2522` / `markSubjectInformed`: success app/Http/Controllers/SafeguardingConcernController.php:516 `return back()->with('success', 'Subject marked as informed.');`.
- `ROUTE-2523` / `triage`: fields `substantiation`, `initial_risk`, `lead_user_id`, `path`, `notes`, `investigation_type`; success app/Http/Controllers/SafeguardingConcernController.php:446 `return back()->with('success', $message);`; failure app/Http/Controllers/SafeguardingConcernController.php:375 `return back()->withErrors(['triage' => 'This concern has already been triaged.']);`; app/Http/Controllers/SafeguardingConcernController.php:390 `return back()->withErrors(['notes' => 'Record why no further action is required.']);`.

## Failure and recovery paths

- `triage`: app/Http/Controllers/SafeguardingConcernController.php:375 `return back()->withErrors(['triage' => 'This concern has already been triaged.']);`; app/Http/Controllers/SafeguardingConcernController.php:390 `return back()->withErrors(['notes' => 'Record why no further action is required.']);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/SafeguardingConcernController.php:235 `$concern = SafeguardingConcern::create($validated);`; app/Http/Controllers/SafeguardingConcernController.php:309 `$concern->update($validated);`; app/Http/Controllers/SafeguardingConcernController.php:327 `$concern->update([`; app/Http/Controllers/SafeguardingConcernController.php:530 `$concern->update([`; app/Http/Controllers/SafeguardingConcernController.php:510 `$concern->update([`; app/Http/Controllers/SafeguardingConcernController.php:414 `SafeguardingInvestigation::create([`; app/Http/Controllers/SafeguardingConcernController.php:440 `$concern->update($attributes);`; responses app/Http/Controllers/SafeguardingConcernController.php:122 `return Inertia::render('safeguarding/index', [`; app/Http/Controllers/SafeguardingConcernController.php:239 `return back()`; app/Http/Controllers/SafeguardingConcernController.php:258 `return Inertia::render('safeguarding/concern', [`; app/Http/Controllers/SafeguardingConcernController.php:311 `return redirect()`; app/Http/Controllers/SafeguardingConcernController.php:333 `return back()->with('success', 'Concern assigned successfully.');`; app/Http/Controllers/SafeguardingConcernController.php:274 `return redirect()->route('safeguarding.show', $concern);`; app/Http/Controllers/SafeguardingConcernController.php:535 `return back()->with('success', $validated['is_sensitive']`; app/Http/Controllers/SafeguardingConcernController.php:516 `return back()->with('success', 'Subject marked as informed.');`; app/Http/Controllers/SafeguardingConcernController.php:375 `return back()->withErrors(['triage' => 'This concern has already been triaged.']);`; app/Http/Controllers/SafeguardingConcernController.php:390 `return back()->withErrors(['notes' => 'Record why no further action is required.']);`; app/Http/Controllers/SafeguardingConcernController.php:446 `return back()->with('success', $message);`; app/Http/Controllers/SafeguardingConcernController.php:190 `return redirect()->route('safeguarding.index', ['raise' => 1]);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD safeguarding` — `safeguarding.index` — `App\Http\Controllers\SafeguardingConcernController@index` — `app/Http/Controllers/SafeguardingConcernController.php:24` — middleware `web, auth, permission:safeguarding.viewAny`
- `POST safeguarding` — `safeguarding.store` — `App\Http\Controllers\SafeguardingConcernController@store` — `app/Http/Controllers/SafeguardingConcernController.php:196` — middleware `web, auth, permission:safeguarding.create`
- `GET|HEAD safeguarding/{concern}` — `safeguarding.show` — `App\Http\Controllers\SafeguardingConcernController@show` — `app/Http/Controllers/SafeguardingConcernController.php:252` — middleware `web, auth`
- `PUT safeguarding/{concern}` — `safeguarding.update` — `App\Http\Controllers\SafeguardingConcernController@update` — `app/Http/Controllers/SafeguardingConcernController.php:280` — middleware `web, auth`
- `POST safeguarding/{concern}/assign` — `safeguarding.assign` — `App\Http\Controllers\SafeguardingConcernController@assign` — `app/Http/Controllers/SafeguardingConcernController.php:319` — middleware `web, auth`
- `GET|HEAD safeguarding/{concern}/edit` — `safeguarding.edit` — `App\Http\Controllers\SafeguardingConcernController@edit` — `app/Http/Controllers/SafeguardingConcernController.php:270` — middleware `web, auth`
- `POST safeguarding/{concern}/sensitivity` — `safeguarding.setSensitivity` — `App\Http\Controllers\SafeguardingConcernController@setSensitivity` — `app/Http/Controllers/SafeguardingConcernController.php:524` — middleware `web, auth`
- `POST safeguarding/{concern}/subject-informed` — `safeguarding.markSubjectInformed` — `App\Http\Controllers\SafeguardingConcernController@markSubjectInformed` — `app/Http/Controllers/SafeguardingConcernController.php:506` — middleware `web, auth`
- `POST safeguarding/{concern}/triage` — `safeguarding.triage` — `App\Http\Controllers\SafeguardingConcernController@triage` — `app/Http/Controllers/SafeguardingConcernController.php:370` — middleware `web, auth`
- `GET|HEAD safeguarding/create` — `safeguarding.create` — `App\Http\Controllers\SafeguardingConcernController@create` — `app/Http/Controllers/SafeguardingConcernController.php:186` — middleware `web, auth, permission:safeguarding.create`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/SafeguardingConcernController.php`.
- Exact render/action page relationships: `resources/js/pages/safeguarding/concern.tsx`, `resources/js/pages/safeguarding/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
