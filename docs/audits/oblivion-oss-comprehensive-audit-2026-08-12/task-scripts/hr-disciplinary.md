# HR-DISCIPLINARY: Disciplinary

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.cases.view`, `permission:hr.disciplinary.manage`
- Owning module: Human resources
- Legacy family: `HR-DISCIPLINARY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/cases/{case}/disciplinary/create` (`hr.cases.disciplinary.create`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.cases.view`, `permission:hr.disciplinary.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.cases.view`, `permission:hr.disciplinary.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/cases/{case}/disciplinary/create` (`hr.cases.disciplinary.create`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/cases/disciplinary/{action}/edit` (`hr.cases.disciplinary.edit`, action `edit`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/DisciplinaryController.php:86-100`.
3. Invoke only the owning control for `POST hr/cases/{case}/disciplinary` (`hr.cases.disciplinary.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/DisciplinaryController.php:105-129`; FormRequest `app/Http/Requests/Hr/StoreDisciplinaryActionRequest.php:14`; `employee_user_id`, `action_type`, `allegation_summary`, `investigation_notes`, `investigator_user_id`, `meeting_scheduled_at`, `meeting_location`, `support_person_advised`, `response_deadline`, `good_faith_checklist`.
4. Invoke only the owning control for `PUT hr/cases/disciplinary/{action}` (`hr.cases.disciplinary.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/DisciplinaryController.php:134-233`; `action_type`.
5. Invoke only the owning control for `POST hr/cases/disciplinary/{action}/advance` (`hr.cases.disciplinary.advance`, action `advanceStage`). Source category: **mutation outcome source gap (advanceStage)**; controller `app/Http/Controllers/Hr/DisciplinaryController.php:240-299`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `store` / `ROUTE-1310` at `app/Http/Controllers/Hr/DisciplinaryController.php:105`; it is not runtime-observed.
- **information presented** is applicable only to `create` / `ROUTE-1311` at `app/Http/Controllers/Hr/DisciplinaryController.php:72`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1315` at `app/Http/Controllers/Hr/DisciplinaryController.php:134`; it is not runtime-observed.
- **mutation outcome source gap (advanceStage)** is applicable only to `advanceStage` / `ROUTE-1316` at `app/Http/Controllers/Hr/DisciplinaryController.php:240`; it is not runtime-observed.
- **information presented** is applicable only to `edit` / `ROUTE-1317` at `app/Http/Controllers/Hr/DisciplinaryController.php:86`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1310` / `store`: FormRequest `app/Http/Requests/Hr/StoreDisciplinaryActionRequest.php:14`; fields `employee_user_id`, `action_type`, `allegation_summary`, `investigation_notes`, `investigator_user_id`, `meeting_scheduled_at`, `meeting_location`, `support_person_advised`, `response_deadline`, `good_faith_checklist`; success app/Http/Controllers/Hr/DisciplinaryController.php:128 `return redirect()->back()->with('success', 'Disciplinary action created.');`.
- `ROUTE-1315` / `update`: fields `action_type`; success app/Http/Controllers/Hr/DisciplinaryController.php:227 `->with('success', 'Disciplinary action updated. Outcome is dismissal — start offboarding when ready.')`; app/Http/Controllers/Hr/DisciplinaryController.php:232 `return redirect()->back()->with('success', 'Disciplinary action updated.');`; failure app/Http/Controllers/Hr/DisciplinaryController.php:177 `return redirect()->back()->withErrors([`.
- `ROUTE-1316` / `advanceStage`: success app/Http/Controllers/Hr/DisciplinaryController.php:294 `->with('success', "Disciplinary action advanced to: {$nextStage}. Outcome is dismissal — start offboarding when ready.")`; app/Http/Controllers/Hr/DisciplinaryController.php:298 `return redirect()->back()->with('success', "Disciplinary action advanced to: {$nextStage}.");`; failure app/Http/Controllers/Hr/DisciplinaryController.php:250 `return redirect()->back()->withErrors(['stage' => 'Cannot advance beyond the final stage.']);`; app/Http/Controllers/Hr/DisciplinaryController.php:261 `return redirect()->back()->withErrors([`.

## Failure and recovery paths

- `update`: app/Http/Controllers/Hr/DisciplinaryController.php:177 `return redirect()->back()->withErrors([`.
- `advanceStage`: app/Http/Controllers/Hr/DisciplinaryController.php:250 `return redirect()->back()->withErrors(['stage' => 'Cannot advance beyond the final stage.']);`; app/Http/Controllers/Hr/DisciplinaryController.php:261 `return redirect()->back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/DisciplinaryController.php:113 `$action = HrDisciplinaryAction::create([`; app/Http/Controllers/Hr/DisciplinaryController.php:196 `$action->update($data);`; app/Http/Controllers/Hr/DisciplinaryController.php:278 `$action->update($updateData);`; responses app/Http/Controllers/Hr/DisciplinaryController.php:128 `return redirect()->back()->with('success', 'Disciplinary action created.');`; app/Http/Controllers/Hr/DisciplinaryController.php:79 `return redirect()->route('hr.cases.show', ['case' => $case->id, 'new' => 'disciplinary']);`; app/Http/Controllers/Hr/DisciplinaryController.php:177 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/DisciplinaryController.php:226 `return redirect()->back()`; app/Http/Controllers/Hr/DisciplinaryController.php:232 `return redirect()->back()->with('success', 'Disciplinary action updated.');`; app/Http/Controllers/Hr/DisciplinaryController.php:250 `return redirect()->back()->withErrors(['stage' => 'Cannot advance beyond the final stage.']);`; app/Http/Controllers/Hr/DisciplinaryController.php:261 `return redirect()->back()->withErrors([`; app/Http/Controllers/Hr/DisciplinaryController.php:293 `return redirect()->back()`; app/Http/Controllers/Hr/DisciplinaryController.php:298 `return redirect()->back()->with('success', "Disciplinary action advanced to: {$nextStage}.");`; app/Http/Controllers/Hr/DisciplinaryController.php:96 `return redirect()->route('hr.cases.show', [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/cases/{case}/disciplinary` — `hr.cases.disciplinary.store` — `App\Http\Controllers\Hr\DisciplinaryController@store` — `app/Http/Controllers/Hr/DisciplinaryController.php:105` — middleware `web, auth, permission:hr.cases.view, permission:hr.disciplinary.manage`
- `GET|HEAD hr/cases/{case}/disciplinary/create` — `hr.cases.disciplinary.create` — `App\Http\Controllers\Hr\DisciplinaryController@create` — `app/Http/Controllers/Hr/DisciplinaryController.php:72` — middleware `web, auth, permission:hr.cases.view, permission:hr.disciplinary.manage`
- `PUT hr/cases/disciplinary/{action}` — `hr.cases.disciplinary.update` — `App\Http\Controllers\Hr\DisciplinaryController@update` — `app/Http/Controllers/Hr/DisciplinaryController.php:134` — middleware `web, auth, permission:hr.cases.view, permission:hr.disciplinary.manage`
- `POST hr/cases/disciplinary/{action}/advance` — `hr.cases.disciplinary.advance` — `App\Http\Controllers\Hr\DisciplinaryController@advanceStage` — `app/Http/Controllers/Hr/DisciplinaryController.php:240` — middleware `web, auth, permission:hr.cases.view, permission:hr.disciplinary.manage`
- `GET|HEAD hr/cases/disciplinary/{action}/edit` — `hr.cases.disciplinary.edit` — `App\Http\Controllers\Hr\DisciplinaryController@edit` — `app/Http/Controllers/Hr/DisciplinaryController.php:86` — middleware `web, auth, permission:hr.cases.view, permission:hr.disciplinary.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/DisciplinaryController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
