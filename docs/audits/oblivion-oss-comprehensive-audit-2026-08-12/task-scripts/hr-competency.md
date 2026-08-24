# HR-COMPETENCY: Competency

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-COMPETENCY`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/performance/competencies` (`hr.competencies.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/performance/competencies` (`hr.competencies.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/performance/competencies/{profile}` (`hr.competencies.profile`, action `employeeProfile`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompetencyController.php:262-303`.
3. Use `GET|HEAD hr/performance/competencies/assess` (`hr.competencies.assess.create`, action `createAssessment`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/CompetencyController.php:178-208`.
4. Use `GET|HEAD hr/performance/competencies/assessments/{assessment}/evidence` (`hr.competencies.assessments.evidence.show`, action `downloadAssessmentEvidence`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/CompetencyController.php:162-176`.
5. Invoke only the owning control for `POST hr/performance/competencies` (`hr.competencies.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CompetencyController.php:54-80`; `name`.
6. Invoke only the owning control for `PUT hr/performance/competencies/{competency}` (`hr.competencies.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/CompetencyController.php:85-103`; `name`.
7. Invoke only the owning control for `POST hr/performance/competencies/{competency}/deactivate` (`hr.competencies.deactivate`, action `deactivate`). Source category: **mutation outcome source gap (deactivate)**; controller `app/Http/Controllers/Hr/CompetencyController.php:108-116`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/performance/competencies/assess` (`hr.competencies.assess`, action `assess`). Source category: **mutation outcome source gap (assess)**; controller `app/Http/Controllers/Hr/CompetencyController.php:213-257`; `employee_user_id`.
9. Invoke only the owning control for `POST hr/performance/competencies/assessments/{assessment}/evidence` (`hr.competencies.assessments.evidence.store`, action `uploadAssessmentEvidence`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/CompetencyController.php:139-157`; `file`.
10. Invoke only the owning control for `POST hr/performance/competencies/assessments/{assessment}/sign-off` (`hr.competencies.assessments.sign-off`, action `signOffAssessment`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/CompetencyController.php:121-134`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1613` at `app/Http/Controllers/Hr/CompetencyController.php:26`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1614` at `app/Http/Controllers/Hr/CompetencyController.php:54`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1615` at `app/Http/Controllers/Hr/CompetencyController.php:85`; it is not runtime-observed.
- **mutation outcome source gap (deactivate)** is applicable only to `deactivate` / `ROUTE-1616` at `app/Http/Controllers/Hr/CompetencyController.php:108`; it is not runtime-observed.
- **information presented** is applicable only to `employeeProfile` / `ROUTE-1617` at `app/Http/Controllers/Hr/CompetencyController.php:262`; it is not runtime-observed.
- **information presented** is applicable only to `createAssessment` / `ROUTE-1618` at `app/Http/Controllers/Hr/CompetencyController.php:178`; it is not runtime-observed.
- **mutation outcome source gap (assess)** is applicable only to `assess` / `ROUTE-1619` at `app/Http/Controllers/Hr/CompetencyController.php:213`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAssessmentEvidence` / `ROUTE-1620` at `app/Http/Controllers/Hr/CompetencyController.php:162`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAssessmentEvidence` / `ROUTE-1621` at `app/Http/Controllers/Hr/CompetencyController.php:139`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `signOffAssessment` / `ROUTE-1622` at `app/Http/Controllers/Hr/CompetencyController.php:121`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/performance/competencies/assess.tsx`, `resources/js/pages/hr/performance/competencies/index.tsx`, `resources/js/pages/hr/performance/competencies/profile.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1614` / `store`: fields `name`; success app/Http/Controllers/Hr/CompetencyController.php:79 `return redirect()->back()->with('success', 'Competency created.');`.
- `ROUTE-1615` / `update`: fields `name`; success app/Http/Controllers/Hr/CompetencyController.php:102 `return redirect()->back()->with('success', 'Competency updated.');`.
- `ROUTE-1616` / `deactivate`: success app/Http/Controllers/Hr/CompetencyController.php:115 `return redirect()->back()->with('success', 'Competency deactivated.');`.
- `ROUTE-1619` / `assess`: fields `employee_user_id`; success app/Http/Controllers/Hr/CompetencyController.php:256 `return redirect()->back()->with('success', 'Competency assessment recorded.');`; failure app/Http/Controllers/Hr/CompetencyController.php:235 `throw ValidationException::withMessages([`.
- `ROUTE-1621` / `uploadAssessmentEvidence`: fields `file`; success app/Http/Controllers/Hr/CompetencyController.php:156 `return redirect()->back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-1622` / `signOffAssessment`: success app/Http/Controllers/Hr/CompetencyController.php:133 `return redirect()->back()->with('success', 'Assessment signed off.');`.

## Failure and recovery paths

- `assess`: app/Http/Controllers/Hr/CompetencyController.php:235 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/CompetencyController.php:68 `HrCompetency::create([`; app/Http/Controllers/Hr/CompetencyController.php:100 `$competency->update($data);`; app/Http/Controllers/Hr/CompetencyController.php:113 `$competency->update(['is_active' => false]);`; app/Http/Controllers/Hr/CompetencyController.php:242 `HrCompetencyAssessment::create([`; app/Http/Controllers/Hr/CompetencyController.php:150 `Storage::disk('private')->delete($assessment->evidence_path);`; app/Http/Controllers/Hr/CompetencyController.php:154 `$assessment->update(['evidence_path' => $path]);`; app/Http/Controllers/Hr/CompetencyController.php:128 `$assessment->update([`; responses app/Http/Controllers/Hr/CompetencyController.php:41 `return Inertia::render('hr/performance/competencies/index', [`; app/Http/Controllers/Hr/CompetencyController.php:79 `return redirect()->back()->with('success', 'Competency created.');`; app/Http/Controllers/Hr/CompetencyController.php:102 `return redirect()->back()->with('success', 'Competency updated.');`; app/Http/Controllers/Hr/CompetencyController.php:115 `return redirect()->back()->with('success', 'Competency deactivated.');`; app/Http/Controllers/Hr/CompetencyController.php:293 `return Inertia::render('hr/performance/competencies/profile', [`; app/Http/Controllers/Hr/CompetencyController.php:204 `return Inertia::render('hr/performance/competencies/assess', [`; app/Http/Controllers/Hr/CompetencyController.php:256 `return redirect()->back()->with('success', 'Competency assessment recorded.');`; app/Http/Controllers/Hr/CompetencyController.php:169 `return $this->streamPrivateAttachment(`; app/Http/Controllers/Hr/CompetencyController.php:156 `return redirect()->back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/Hr/CompetencyController.php:133 `return redirect()->back()->with('success', 'Assessment signed off.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/performance/competencies` — `hr.competencies.index` — `App\Http\Controllers\Hr\CompetencyController@index` — `app/Http/Controllers/Hr/CompetencyController.php:26` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/competencies` — `hr.competencies.store` — `App\Http\Controllers\Hr\CompetencyController@store` — `app/Http/Controllers/Hr/CompetencyController.php:54` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `PUT hr/performance/competencies/{competency}` — `hr.competencies.update` — `App\Http\Controllers\Hr\CompetencyController@update` — `app/Http/Controllers/Hr/CompetencyController.php:85` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/competencies/{competency}/deactivate` — `hr.competencies.deactivate` — `App\Http\Controllers\Hr\CompetencyController@deactivate` — `app/Http/Controllers/Hr/CompetencyController.php:108` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/competencies/{profile}` — `hr.competencies.profile` — `App\Http\Controllers\Hr\CompetencyController@employeeProfile` — `app/Http/Controllers/Hr/CompetencyController.php:262` — middleware `web, auth, permission:hr.performance.view`
- `GET|HEAD hr/performance/competencies/assess` — `hr.competencies.assess.create` — `App\Http\Controllers\Hr\CompetencyController@createAssessment` — `app/Http/Controllers/Hr/CompetencyController.php:178` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/competencies/assess` — `hr.competencies.assess` — `App\Http\Controllers\Hr\CompetencyController@assess` — `app/Http/Controllers/Hr/CompetencyController.php:213` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `GET|HEAD hr/performance/competencies/assessments/{assessment}/evidence` — `hr.competencies.assessments.evidence.show` — `App\Http\Controllers\Hr\CompetencyController@downloadAssessmentEvidence` — `app/Http/Controllers/Hr/CompetencyController.php:162` — middleware `web, auth, permission:hr.performance.view`
- `POST hr/performance/competencies/assessments/{assessment}/evidence` — `hr.competencies.assessments.evidence.store` — `App\Http\Controllers\Hr\CompetencyController@uploadAssessmentEvidence` — `app/Http/Controllers/Hr/CompetencyController.php:139` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`
- `POST hr/performance/competencies/assessments/{assessment}/sign-off` — `hr.competencies.assessments.sign-off` — `App\Http\Controllers\Hr\CompetencyController@signOffAssessment` — `app/Http/Controllers/Hr/CompetencyController.php:121` — middleware `web, auth, permission:hr.performance.view, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/CompetencyController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/performance/competencies/assess.tsx`, `resources/js/pages/hr/performance/competencies/index.tsx`, `resources/js/pages/hr/performance/competencies/profile.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
