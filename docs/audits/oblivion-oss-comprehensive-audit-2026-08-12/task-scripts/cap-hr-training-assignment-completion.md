# CAP-HR-TRAINING-ASSIGNMENT-COMPLETION: Training assignment enrolment completion and certificates

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.training.manage|training.manageCourses|training.enrol`, `permission:hr.training.manage|training.manageCourses|training.record`, `permission:hr.training.view|training.viewAny`
- Owning module: Human resources
- Legacy family: `HR-TRAINING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/training/assignments/preview` (`hr.training.assignments.preview`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.training.manage|training.manageCourses|training.enrol`, `permission:hr.training.manage|training.manageCourses|training.record`, `permission:hr.training.view|training.viewAny`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.training.manage|training.manageCourses|training.enrol`, `permission:hr.training.manage|training.manageCourses|training.record`, `permission:hr.training.view|training.viewAny`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/training/assignments/preview` (`hr.training.assignments.preview`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/training/enrollments/{enrollment}/certificate` (`hr.training.certificate`, action `downloadCertificate`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/TrainingController.php:657-673`.
3. Invoke only the owning control for `POST hr/training/assignments` (`hr.training.assignments.store`, action `storeAssignments`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TrainingController.php:472-494`; `course_ids`.
4. Invoke only the owning control for `POST hr/training/assignments/{assignment}/remind` (`hr.training.assignments.remind`, action `remindAssignment`). Source category: **mutation outcome source gap (remindAssignment)**; controller `app/Http/Controllers/Hr/TrainingController.php:515-525`; no exact validation fields extracted.
5. Invoke only the owning control for `PATCH hr/training/assignments/{assignment}/waive` (`hr.training.assignments.waive`, action `waiveAssignment`). Source category: **mutation outcome source gap (waiveAssignment)**; controller `app/Http/Controllers/Hr/TrainingController.php:527-538`; `reason`.
6. Invoke only the owning control for `POST hr/training/enroll` (`hr.training.enroll`, action `enroll`). Source category: **mutation outcome source gap (enroll)**; controller `app/Http/Controllers/Hr/TrainingController.php:380-399`; `user_id`.
7. Invoke only the owning control for `PUT hr/training/enrollments/{enrollment}/complete` (`hr.training.enrollments.complete`, action `completeEnrollment`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/TrainingController.php:401-423`; `score`.
8. Invoke only the owning control for `POST hr/training/record` (`hr.training.record`, action `recordCompletion`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TrainingController.php:429-466`; `course_id`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeAssignments` / `ROUTE-1787` at `app/Http/Controllers/Hr/TrainingController.php:472`; it is not runtime-observed.
- **mutation outcome source gap (remindAssignment)** is applicable only to `remindAssignment` / `ROUTE-1788` at `app/Http/Controllers/Hr/TrainingController.php:515`; it is not runtime-observed.
- **mutation outcome source gap (waiveAssignment)** is applicable only to `waiveAssignment` / `ROUTE-1789` at `app/Http/Controllers/Hr/TrainingController.php:527`; it is not runtime-observed.
- **information presented** is applicable only to `previewAssignments` / `ROUTE-1790` at `app/Http/Controllers/Hr/TrainingController.php:496`; it is not runtime-observed.
- **mutation outcome source gap (enroll)** is applicable only to `enroll` / `ROUTE-1800` at `app/Http/Controllers/Hr/TrainingController.php:380`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadCertificate` / `ROUTE-1801` at `app/Http/Controllers/Hr/TrainingController.php:657`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeEnrollment` / `ROUTE-1802` at `app/Http/Controllers/Hr/TrainingController.php:401`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordCompletion` / `ROUTE-1804` at `app/Http/Controllers/Hr/TrainingController.php:429`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1787` / `storeAssignments`: fields `course_ids`; success app/Http/Controllers/Hr/TrainingController.php:493 `return redirect()->back()->with('success', $count > 0 ? "Training assigned to {$count} record(s)." : 'No matching people for that audience.');`.
- `ROUTE-1788` / `remindAssignment`: success app/Http/Controllers/Hr/TrainingController.php:524 `return redirect()->back()->with('success', 'Reminder sent.');`.
- `ROUTE-1789` / `waiveAssignment`: fields `reason`; success app/Http/Controllers/Hr/TrainingController.php:537 `return redirect()->back()->with('success', 'Assignment waived.');`.
- `ROUTE-1790` / `previewAssignments`: fields `course_ids`.
- `ROUTE-1800` / `enroll`: fields `user_id`; success app/Http/Controllers/Hr/TrainingController.php:398 `return redirect()->back()->with('success', count($userIds) > 1 ? 'Employees enrolled.' : 'Employee enrolled in course.');`.
- `ROUTE-1802` / `completeEnrollment`: fields `score`; success app/Http/Controllers/Hr/TrainingController.php:422 `return redirect()->back()->with('success', 'Completion recorded.');`.
- `ROUTE-1804` / `recordCompletion`: fields `course_id`; success app/Http/Controllers/Hr/TrainingController.php:465 `return redirect()->back()->with('success', 'Completion recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/TrainingController.php:452 `$enrollment = HrCourseEnrollment::firstOrCreate(`; responses app/Http/Controllers/Hr/TrainingController.php:493 `return redirect()->back()->with('success', $count > 0 ? "Training assigned to {$count} record(s)." : 'No matching people for that audience.');`; app/Http/Controllers/Hr/TrainingController.php:524 `return redirect()->back()->with('success', 'Reminder sent.');`; app/Http/Controllers/Hr/TrainingController.php:537 `return redirect()->back()->with('success', 'Assignment waived.');`; app/Http/Controllers/Hr/TrainingController.php:512 `return response()->json($this->trainingService->previewAudience($tenantId, $data));`; app/Http/Controllers/Hr/TrainingController.php:398 `return redirect()->back()->with('success', count($userIds) > 1 ? 'Employees enrolled.' : 'Employee enrolled in course.');`; app/Http/Controllers/Hr/TrainingController.php:672 `return Storage::disk('private')->download($path, $filename);`; app/Http/Controllers/Hr/TrainingController.php:422 `return redirect()->back()->with('success', 'Completion recorded.');`; app/Http/Controllers/Hr/TrainingController.php:465 `return redirect()->back()->with('success', 'Completion recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/training/assignments` — `hr.training.assignments.store` — `App\Http\Controllers\Hr\TrainingController@storeAssignments` — `app/Http/Controllers/Hr/TrainingController.php:472` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.enrol`
- `POST hr/training/assignments/{assignment}/remind` — `hr.training.assignments.remind` — `App\Http\Controllers\Hr\TrainingController@remindAssignment` — `app/Http/Controllers/Hr/TrainingController.php:515` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.enrol`
- `PATCH hr/training/assignments/{assignment}/waive` — `hr.training.assignments.waive` — `App\Http\Controllers\Hr\TrainingController@waiveAssignment` — `app/Http/Controllers/Hr/TrainingController.php:527` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.record`
- `GET|HEAD hr/training/assignments/preview` — `hr.training.assignments.preview` — `App\Http\Controllers\Hr\TrainingController@previewAssignments` — `app/Http/Controllers/Hr/TrainingController.php:496` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.enrol`
- `POST hr/training/enroll` — `hr.training.enroll` — `App\Http\Controllers\Hr\TrainingController@enroll` — `app/Http/Controllers/Hr/TrainingController.php:380` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.enrol`
- `GET|HEAD hr/training/enrollments/{enrollment}/certificate` — `hr.training.certificate` — `App\Http\Controllers\Hr\TrainingController@downloadCertificate` — `app/Http/Controllers/Hr/TrainingController.php:657` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `PUT hr/training/enrollments/{enrollment}/complete` — `hr.training.enrollments.complete` — `App\Http\Controllers\Hr\TrainingController@completeEnrollment` — `app/Http/Controllers/Hr/TrainingController.php:401` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.record`
- `POST hr/training/record` — `hr.training.record` — `App\Http\Controllers\Hr\TrainingController@recordCompletion` — `app/Http/Controllers/Hr/TrainingController.php:429` — middleware `web, auth, permission:hr.training.manage|training.manageCourses|training.record`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TrainingController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
