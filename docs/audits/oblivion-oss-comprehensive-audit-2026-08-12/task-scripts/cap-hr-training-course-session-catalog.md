# CAP-HR-TRAINING-COURSE-SESSION-CATALOG: Training course session and catalogue management

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.training.view|training.viewAny`, `permission:hr.training.manage|training.manageCourses`
- Owning module: Human resources
- Legacy family: `HR-TRAINING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/training` (`hr.training.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.training.view|training.viewAny`, `permission:hr.training.manage|training.manageCourses`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.training.view|training.viewAny`, `permission:hr.training.manage|training.manageCourses`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/training` (`hr.training.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/training/catalog` (`hr.training.catalog`, action `catalog`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/TrainingController.php:44-145`.
3. Use `GET|HEAD hr/training/courses/{course}` (`hr.training.courses.show`, action `showCourse`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/TrainingController.php:203-208`.
4. Use `GET|HEAD hr/training/courses/{course}/detail` (`hr.training.courses.detail`, action `courseDetail`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Hr/TrainingController.php:213-260`.
5. Invoke only the owning control for `POST hr/training/courses` (`hr.training.courses.store`, action `storeCourse`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TrainingController.php:266-277`; FormRequest `app/Http/Requests/Hr/StoreTrainingCourseRequest.php:15`; no exact validation fields extracted.
6. Invoke only the owning control for `PUT hr/training/courses/{course}` (`hr.training.courses.update`, action `updateCourse`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/TrainingController.php:279-287`; FormRequest `app/Http/Requests/Hr/UpdateTrainingCourseRequest.php:15`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/training/courses/{course}/sessions` (`hr.training.sessions.store`, action `storeSession`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/TrainingController.php:323-334`; no exact validation fields extracted.
8. Invoke only the owning control for `PATCH hr/training/courses/{course}/toggle` (`hr.training.courses.toggle`, action `toggleCourse`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/TrainingController.php:289-299`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/training/courses/bulk-archive` (`hr.training.courses.bulk-archive`, action `bulkArchiveCourses`). Source category: **mutation outcome source gap (bulkArchiveCourses)**; controller `app/Http/Controllers/Hr/TrainingController.php:301-317`; `course_ids`.
10. Invoke only the owning control for `DELETE hr/training/sessions/{session}` (`hr.training.sessions.cancel`, action `cancelSession`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/TrainingController.php:348-359`; `reason`.
11. Invoke only the owning control for `PUT hr/training/sessions/{session}` (`hr.training.sessions.update`, action `updateSession`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/TrainingController.php:336-346`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `catalog` / `ROUTE-1786` at `app/Http/Controllers/Hr/TrainingController.php:44`; it is not runtime-observed.
- **information presented** is applicable only to `catalog` / `ROUTE-1791` at `app/Http/Controllers/Hr/TrainingController.php:44`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCourse` / `ROUTE-1793` at `app/Http/Controllers/Hr/TrainingController.php:266`; it is not runtime-observed.
- **information presented** is applicable only to `showCourse` / `ROUTE-1794` at `app/Http/Controllers/Hr/TrainingController.php:203`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCourse` / `ROUTE-1795` at `app/Http/Controllers/Hr/TrainingController.php:279`; it is not runtime-observed.
- **information presented** is applicable only to `courseDetail` / `ROUTE-1796` at `app/Http/Controllers/Hr/TrainingController.php:213`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeSession` / `ROUTE-1797` at `app/Http/Controllers/Hr/TrainingController.php:323`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleCourse` / `ROUTE-1798` at `app/Http/Controllers/Hr/TrainingController.php:289`; it is not runtime-observed.
- **mutation outcome source gap (bulkArchiveCourses)** is applicable only to `bulkArchiveCourses` / `ROUTE-1799` at `app/Http/Controllers/Hr/TrainingController.php:301`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `cancelSession` / `ROUTE-1805` at `app/Http/Controllers/Hr/TrainingController.php:348`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSession` / `ROUTE-1806` at `app/Http/Controllers/Hr/TrainingController.php:336`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/training/catalog.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1793` / `storeCourse`: FormRequest `app/Http/Requests/Hr/StoreTrainingCourseRequest.php:15`; success app/Http/Controllers/Hr/TrainingController.php:276 `return redirect()->back()->with('success', 'Course created.');`.
- `ROUTE-1795` / `updateCourse`: FormRequest `app/Http/Requests/Hr/UpdateTrainingCourseRequest.php:15`; success app/Http/Controllers/Hr/TrainingController.php:286 `return redirect()->back()->with('success', 'Course saved.');`.
- `ROUTE-1797` / `storeSession`: success app/Http/Controllers/Hr/TrainingController.php:333 `return redirect()->back()->with('success', 'Session scheduled.');`.
- `ROUTE-1798` / `toggleCourse`: success app/Http/Controllers/Hr/TrainingController.php:298 `return redirect()->back()->with('success', $course->is_active ? 'Course archived.' : 'Course activated.');`.
- `ROUTE-1799` / `bulkArchiveCourses`: fields `course_ids`; success app/Http/Controllers/Hr/TrainingController.php:316 `return redirect()->back()->with('success', $active ? 'Courses activated.' : 'Courses archived.');`.
- `ROUTE-1805` / `cancelSession`: fields `reason`; success app/Http/Controllers/Hr/TrainingController.php:358 `return redirect()->back()->with('success', 'Session cancelled — enrolled staff notified.');`.
- `ROUTE-1806` / `updateSession`: success app/Http/Controllers/Hr/TrainingController.php:345 `return redirect()->back()->with('success', 'Session updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Hr/TrainingController.php:314 `HrCourse::forTenant($tenantId)->whereIn('id', $data['course_ids'])->update(['is_active' => $active]);`; responses app/Http/Controllers/Hr/TrainingController.php:75 `return [`; app/Http/Controllers/Hr/TrainingController.php:114 `return Inertia::render('hr/training/catalog', [`; app/Http/Controllers/Hr/TrainingController.php:276 `return redirect()->back()->with('success', 'Course created.');`; app/Http/Controllers/Hr/TrainingController.php:207 `return redirect()->route('hr.training.catalog', ['course' => $course->id]);`; app/Http/Controllers/Hr/TrainingController.php:286 `return redirect()->back()->with('success', 'Course saved.');`; app/Http/Controllers/Hr/TrainingController.php:229 `return response()->json([`; app/Http/Controllers/Hr/TrainingController.php:333 `return redirect()->back()->with('success', 'Session scheduled.');`; app/Http/Controllers/Hr/TrainingController.php:298 `return redirect()->back()->with('success', $course->is_active ? 'Course archived.' : 'Course activated.');`; app/Http/Controllers/Hr/TrainingController.php:316 `return redirect()->back()->with('success', $active ? 'Courses activated.' : 'Courses archived.');`; app/Http/Controllers/Hr/TrainingController.php:358 `return redirect()->back()->with('success', 'Session cancelled — enrolled staff notified.');`; app/Http/Controllers/Hr/TrainingController.php:345 `return redirect()->back()->with('success', 'Session updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/training` — `hr.training.index` — `App\Http\Controllers\Hr\TrainingController@catalog` — `app/Http/Controllers/Hr/TrainingController.php:44` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `GET|HEAD hr/training/catalog` — `hr.training.catalog` — `App\Http\Controllers\Hr\TrainingController@catalog` — `app/Http/Controllers/Hr/TrainingController.php:44` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `POST hr/training/courses` — `hr.training.courses.store` — `App\Http\Controllers\Hr\TrainingController@storeCourse` — `app/Http/Controllers/Hr/TrainingController.php:266` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `GET|HEAD hr/training/courses/{course}` — `hr.training.courses.show` — `App\Http\Controllers\Hr\TrainingController@showCourse` — `app/Http/Controllers/Hr/TrainingController.php:203` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `PUT hr/training/courses/{course}` — `hr.training.courses.update` — `App\Http\Controllers\Hr\TrainingController@updateCourse` — `app/Http/Controllers/Hr/TrainingController.php:279` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `GET|HEAD hr/training/courses/{course}/detail` — `hr.training.courses.detail` — `App\Http\Controllers\Hr\TrainingController@courseDetail` — `app/Http/Controllers/Hr/TrainingController.php:213` — middleware `web, auth, permission:hr.training.view|training.viewAny`
- `POST hr/training/courses/{course}/sessions` — `hr.training.sessions.store` — `App\Http\Controllers\Hr\TrainingController@storeSession` — `app/Http/Controllers/Hr/TrainingController.php:323` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `PATCH hr/training/courses/{course}/toggle` — `hr.training.courses.toggle` — `App\Http\Controllers\Hr\TrainingController@toggleCourse` — `app/Http/Controllers/Hr/TrainingController.php:289` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `POST hr/training/courses/bulk-archive` — `hr.training.courses.bulk-archive` — `App\Http\Controllers\Hr\TrainingController@bulkArchiveCourses` — `app/Http/Controllers/Hr/TrainingController.php:301` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `DELETE hr/training/sessions/{session}` — `hr.training.sessions.cancel` — `App\Http\Controllers\Hr\TrainingController@cancelSession` — `app/Http/Controllers/Hr/TrainingController.php:348` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`
- `PUT hr/training/sessions/{session}` — `hr.training.sessions.update` — `App\Http\Controllers\Hr\TrainingController@updateSession` — `app/Http/Controllers/Hr/TrainingController.php:336` — middleware `web, auth, permission:hr.training.manage|training.manageCourses`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/TrainingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/training/catalog.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
