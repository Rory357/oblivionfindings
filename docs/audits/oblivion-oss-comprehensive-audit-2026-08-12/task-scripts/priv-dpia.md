# PRIV-DPIA: DPIA

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:privacy.conductDPIA`
- Owning module: Privacy and compliance
- Legacy family: `PRIV-DPIA`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `privacy/pia` (`privacy.dpia.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:privacy.conductDPIA`.
- Exact middleware atoms: `web`, `auth`, `permission:privacy.conductDPIA`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD privacy/pia` (`privacy.dpia.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD privacy/pia/{dpia}` (`privacy.dpia.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DPIAController.php:105-114`.
3. Use `GET|HEAD privacy/pia/{dpia}/edit` (`privacy.dpia.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DPIAController.php:119-127`.
4. Use `GET|HEAD privacy/pia/create` (`privacy.dpia.create`, action `create`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/DPIAController.php:58-63`.
5. Invoke only the owning control for `POST privacy/pia` (`privacy.dpia.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/DPIAController.php:68-100`; `assessment_name`, `project_or_process`, `description`, `assessment_type`, `personal_data_types`, `data_subjects`, `processing_purpose`, `legal_basis`, `identified_risks`, `overall_risk_level`, `mitigation_measures`, `residual_risk_level`, `review_date`.
6. Invoke only the owning control for `PUT privacy/pia/{dpia}` (`privacy.dpia.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/DPIAController.php:132-154`; `assessment_name`, `project_or_process`, `description`, `personal_data_types`, `data_subjects`, `processing_purpose`, `legal_basis`, `identified_risks`, `overall_risk_level`, `mitigation_measures`, `residual_risk_level`, `review_date`.
7. Invoke only the owning control for `POST privacy/pia/{dpia}/approve` (`privacy.dpia.approve`, action `approve`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/DPIAController.php:159-170`; no exact validation fields extracted.
8. Invoke only the owning control for `POST privacy/pia/{dpia}/review` (`privacy.dpia.review`, action `review`). Source category: **mutation outcome source gap (review)**; controller `app/Http/Controllers/DPIAController.php:175-189`; `review_notes`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-2314` at `app/Http/Controllers/DPIAController.php:17`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-2315` at `app/Http/Controllers/DPIAController.php:68`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-2316` at `app/Http/Controllers/DPIAController.php:105`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-2317` at `app/Http/Controllers/DPIAController.php:132`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `approve` / `ROUTE-2318` at `app/Http/Controllers/DPIAController.php:159`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-2319` at `app/Http/Controllers/DPIAController.php:119`; it is not runtime-observed.
- **mutation outcome source gap (review)** is applicable only to `review` / `ROUTE-2320` at `app/Http/Controllers/DPIAController.php:175`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-2321` at `app/Http/Controllers/DPIAController.php:58`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/privacy/dpia/edit.tsx`, `resources/js/pages/privacy/dpia/show.tsx`, `resources/js/pages/privacy/dpia.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-2315` / `store`: fields `assessment_name`, `project_or_process`, `description`, `assessment_type`, `personal_data_types`, `data_subjects`, `processing_purpose`, `legal_basis`, `identified_risks`, `overall_risk_level`, `mitigation_measures`, `residual_risk_level`, `review_date`; success app/Http/Controllers/DPIAController.php:94 `return back()->with('success', 'PIA created successfully.');`; app/Http/Controllers/DPIAController.php:99 `->with('success', 'PIA created successfully.');`.
- `ROUTE-2317` / `update`: fields `assessment_name`, `project_or_process`, `description`, `personal_data_types`, `data_subjects`, `processing_purpose`, `legal_basis`, `identified_risks`, `overall_risk_level`, `mitigation_measures`, `residual_risk_level`, `review_date`; success app/Http/Controllers/DPIAController.php:153 `return back()->with('success', 'PIA updated.');`.
- `ROUTE-2318` / `approve`: success app/Http/Controllers/DPIAController.php:169 `return back()->with('success', 'PIA approved.');`.
- `ROUTE-2320` / `review`: fields `review_notes`; success app/Http/Controllers/DPIAController.php:188 `return back()->with('success', 'PIA sent for review.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/DPIAController.php:91 `$dpia = PrivacyImpactAssessment::create($validated);`; app/Http/Controllers/DPIAController.php:151 `$dpia->update($validated);`; app/Http/Controllers/DPIAController.php:163 `$dpia->update([`; app/Http/Controllers/DPIAController.php:183 `$dpia->update([`; responses app/Http/Controllers/DPIAController.php:43 `return Inertia::render('privacy/dpia', [`; app/Http/Controllers/DPIAController.php:94 `return back()->with('success', 'PIA created successfully.');`; app/Http/Controllers/DPIAController.php:97 `return redirect()`; app/Http/Controllers/DPIAController.php:111 `return Inertia::render('privacy/dpia/show', [`; app/Http/Controllers/DPIAController.php:153 `return back()->with('success', 'PIA updated.');`; app/Http/Controllers/DPIAController.php:169 `return back()->with('success', 'PIA approved.');`; app/Http/Controllers/DPIAController.php:123 `return Inertia::render('privacy/dpia/edit', [`; app/Http/Controllers/DPIAController.php:188 `return back()->with('success', 'PIA sent for review.');`; app/Http/Controllers/DPIAController.php:62 `return redirect('/privacy/dashboard?new=dpia');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD privacy/pia` — `privacy.dpia.index` — `App\Http\Controllers\DPIAController@index` — `app/Http/Controllers/DPIAController.php:17` — middleware `web, auth, permission:privacy.conductDPIA`
- `POST privacy/pia` — `privacy.dpia.store` — `App\Http\Controllers\DPIAController@store` — `app/Http/Controllers/DPIAController.php:68` — middleware `web, auth, permission:privacy.conductDPIA`
- `GET|HEAD privacy/pia/{dpia}` — `privacy.dpia.show` — `App\Http\Controllers\DPIAController@show` — `app/Http/Controllers/DPIAController.php:105` — middleware `web, auth, permission:privacy.conductDPIA`
- `PUT privacy/pia/{dpia}` — `privacy.dpia.update` — `App\Http\Controllers\DPIAController@update` — `app/Http/Controllers/DPIAController.php:132` — middleware `web, auth, permission:privacy.conductDPIA`
- `POST privacy/pia/{dpia}/approve` — `privacy.dpia.approve` — `App\Http\Controllers\DPIAController@approve` — `app/Http/Controllers/DPIAController.php:159` — middleware `web, auth, permission:privacy.conductDPIA`
- `GET|HEAD privacy/pia/{dpia}/edit` — `privacy.dpia.edit` — `App\Http\Controllers\DPIAController@edit` — `app/Http/Controllers/DPIAController.php:119` — middleware `web, auth, permission:privacy.conductDPIA`
- `POST privacy/pia/{dpia}/review` — `privacy.dpia.review` — `App\Http\Controllers\DPIAController@review` — `app/Http/Controllers/DPIAController.php:175` — middleware `web, auth, permission:privacy.conductDPIA`
- `GET|HEAD privacy/pia/create` — `privacy.dpia.create` — `App\Http\Controllers\DPIAController@create` — `app/Http/Controllers/DPIAController.php:58` — middleware `web, auth, permission:privacy.conductDPIA`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/DPIAController.php`.
- Exact render/action page relationships: `resources/js/pages/privacy/dpia/edit.tsx`, `resources/js/pages/privacy/dpia/show.tsx`, `resources/js/pages/privacy/dpia.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
