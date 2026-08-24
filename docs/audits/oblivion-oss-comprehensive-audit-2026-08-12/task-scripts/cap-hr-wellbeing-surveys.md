# CAP-HR-WELLBEING-SURVEYS: Wellbeing surveys responses and publication

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-WELLBEING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/wellbeing/surveys/{survey}` (`hr.wellbeing.surveys.show`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/wellbeing/surveys/{survey}` (`hr.wellbeing.surveys.show`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD hr/wellbeing/surveys/{survey}/export` (`hr.wellbeing.surveys.export`, action `exportSurvey`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/Hr/WellbeingController.php:964-997`.
3. Invoke only the owning control for `POST hr/wellbeing/surveys` (`hr.wellbeing.surveys.store`, action `storeSurvey`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:495-531`; `title`.
4. Invoke only the owning control for `DELETE hr/wellbeing/surveys/{survey}` (`hr.wellbeing.surveys.destroy`, action `destroySurvey`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/WellbeingController.php:952-962`; no exact validation fields extracted.
5. Invoke only the owning control for `PUT hr/wellbeing/surveys/{survey}` (`hr.wellbeing.surveys.update`, action `updateSurvey`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/WellbeingController.php:533-561`; `title`.
6. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/archive` (`hr.wellbeing.surveys.archive`, action `archiveSurvey`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Hr/WellbeingController.php:940-950`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/close` (`hr.wellbeing.surveys.close`, action `closeSurvey`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Hr/WellbeingController.php:575-585`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/duplicate` (`hr.wellbeing.surveys.duplicate`, action `duplicateSurvey`). Source category: **mutation outcome source gap (duplicateSurvey)**; controller `app/Http/Controllers/Hr/WellbeingController.php:916-926`; no exact validation fields extracted.
9. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/nudge` (`hr.wellbeing.surveys.nudge`, action `nudgeSurvey`). Source category: **mutation outcome source gap (nudgeSurvey)**; controller `app/Http/Controllers/Hr/WellbeingController.php:928-938`; no exact validation fields extracted.
10. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/publish` (`hr.wellbeing.surveys.publish`, action `publishSurvey`). Source category: **mutation outcome source gap (publishSurvey)**; controller `app/Http/Controllers/Hr/WellbeingController.php:563-573`; no exact validation fields extracted.
11. Invoke only the owning control for `POST hr/wellbeing/surveys/{survey}/responses` (`hr.wellbeing.surveys.responses.store`, action `submitResponse`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:587-601`; `answers`.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `storeSurvey` / `ROUTE-1821` at `app/Http/Controllers/Hr/WellbeingController.php:495`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroySurvey` / `ROUTE-1822` at `app/Http/Controllers/Hr/WellbeingController.php:952`; it is not runtime-observed.
- **information presented** is applicable only to `showSurvey` / `ROUTE-1823` at `app/Http/Controllers/Hr/WellbeingController.php:405`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateSurvey` / `ROUTE-1824` at `app/Http/Controllers/Hr/WellbeingController.php:533`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `archiveSurvey` / `ROUTE-1826` at `app/Http/Controllers/Hr/WellbeingController.php:940`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `closeSurvey` / `ROUTE-1827` at `app/Http/Controllers/Hr/WellbeingController.php:575`; it is not runtime-observed.
- **mutation outcome source gap (duplicateSurvey)** is applicable only to `duplicateSurvey` / `ROUTE-1828` at `app/Http/Controllers/Hr/WellbeingController.php:916`; it is not runtime-observed.
- **file/report delivered** is applicable only to `exportSurvey` / `ROUTE-1829` at `app/Http/Controllers/Hr/WellbeingController.php:964`; it is not runtime-observed.
- **mutation outcome source gap (nudgeSurvey)** is applicable only to `nudgeSurvey` / `ROUTE-1830` at `app/Http/Controllers/Hr/WellbeingController.php:928`; it is not runtime-observed.
- **mutation outcome source gap (publishSurvey)** is applicable only to `publishSurvey` / `ROUTE-1831` at `app/Http/Controllers/Hr/WellbeingController.php:563`; it is not runtime-observed.
- **created/recorded** is applicable only to `submitResponse` / `ROUTE-1832` at `app/Http/Controllers/Hr/WellbeingController.php:587`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/wellbeing/survey.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1821` / `storeSurvey`: fields `title`; success app/Http/Controllers/Hr/WellbeingController.php:527 `return redirect()->route('hr.wellbeing.index')->with('success', 'Survey published — invitations sent.');`; app/Http/Controllers/Hr/WellbeingController.php:530 `return redirect()->route('hr.wellbeing.surveys.show', $survey->id)->with('success', 'Survey created.');`.
- `ROUTE-1822` / `destroySurvey`: success app/Http/Controllers/Hr/WellbeingController.php:961 `return redirect()->route('hr.wellbeing.index')->with('success', 'Draft survey deleted.');`.
- `ROUTE-1823` / `showSurvey`: failure app/Http/Controllers/Hr/WellbeingController.php:415 `abort(404);`.
- `ROUTE-1824` / `updateSurvey`: fields `title`; success app/Http/Controllers/Hr/WellbeingController.php:560 `return redirect()->back()->with('success', 'Survey updated.');`.
- `ROUTE-1826` / `archiveSurvey`: success app/Http/Controllers/Hr/WellbeingController.php:949 `return redirect()->back()->with('success', 'Survey archived.');`.
- `ROUTE-1827` / `closeSurvey`: success app/Http/Controllers/Hr/WellbeingController.php:584 `return redirect()->back()->with('success', 'Survey closed.');`.
- `ROUTE-1828` / `duplicateSurvey`: success app/Http/Controllers/Hr/WellbeingController.php:925 `return redirect()->route('hr.wellbeing.surveys.show', $copy->id)->with('success', 'Survey duplicated as a draft.');`.
- `ROUTE-1830` / `nudgeSurvey`: success app/Http/Controllers/Hr/WellbeingController.php:937 `return redirect()->back()->with('success', $count > 0 ? ('Nudged ' . $count . ' non-' . ($count === 1 ? 'responder' : 'responders') . '.') : 'Everyone has already responded.');`.
- `ROUTE-1831` / `publishSurvey`: success app/Http/Controllers/Hr/WellbeingController.php:572 `return redirect()->back()->with('success', 'Survey published.');`.
- `ROUTE-1832` / `submitResponse`: fields `answers`; success app/Http/Controllers/Hr/WellbeingController.php:600 `return redirect()->back()->with('success', 'Survey response submitted.');`.

## Failure and recovery paths

- `showSurvey`: app/Http/Controllers/Hr/WellbeingController.php:415 `abort(404);`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/WellbeingController.php:527 `return redirect()->route('hr.wellbeing.index')->with('success', 'Survey published — invitations sent.');`; app/Http/Controllers/Hr/WellbeingController.php:530 `return redirect()->route('hr.wellbeing.surveys.show', $survey->id)->with('success', 'Survey created.');`; app/Http/Controllers/Hr/WellbeingController.php:961 `return redirect()->route('hr.wellbeing.index')->with('success', 'Draft survey deleted.');`; app/Http/Controllers/Hr/WellbeingController.php:442 `return Inertia::render('hr/wellbeing/survey', [`; app/Http/Controllers/Hr/WellbeingController.php:560 `return redirect()->back()->with('success', 'Survey updated.');`; app/Http/Controllers/Hr/WellbeingController.php:949 `return redirect()->back()->with('success', 'Survey archived.');`; app/Http/Controllers/Hr/WellbeingController.php:584 `return redirect()->back()->with('success', 'Survey closed.');`; app/Http/Controllers/Hr/WellbeingController.php:925 `return redirect()->route('hr.wellbeing.surveys.show', $copy->id)->with('success', 'Survey duplicated as a draft.');`; app/Http/Controllers/Hr/WellbeingController.php:977 `return response()->streamDownload(function () use ($survey, $summary, $isAnonymous) {`; app/Http/Controllers/Hr/WellbeingController.php:937 `return redirect()->back()->with('success', $count > 0 ? ('Nudged ' . $count . ' non-' . ($count === 1 ? 'responder' : 'responders') . '.') : 'Everyone has already responded.');`; app/Http/Controllers/Hr/WellbeingController.php:572 `return redirect()->back()->with('success', 'Survey published.');`; app/Http/Controllers/Hr/WellbeingController.php:600 `return redirect()->back()->with('success', 'Survey response submitted.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST hr/wellbeing/surveys` — `hr.wellbeing.surveys.store` — `App\Http\Controllers\Hr\WellbeingController@storeSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:495` — middleware `web, auth, permission:hr.performance.manage`
- `DELETE hr/wellbeing/surveys/{survey}` — `hr.wellbeing.surveys.destroy` — `App\Http\Controllers\Hr\WellbeingController@destroySurvey` — `app/Http/Controllers/Hr/WellbeingController.php:952` — middleware `web, auth, permission:hr.performance.manage`
- `GET|HEAD hr/wellbeing/surveys/{survey}` — `hr.wellbeing.surveys.show` — `App\Http\Controllers\Hr\WellbeingController@showSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:405` — middleware `web, auth`
- `PUT hr/wellbeing/surveys/{survey}` — `hr.wellbeing.surveys.update` — `App\Http\Controllers\Hr\WellbeingController@updateSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:533` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/archive` — `hr.wellbeing.surveys.archive` — `App\Http\Controllers\Hr\WellbeingController@archiveSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:940` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/close` — `hr.wellbeing.surveys.close` — `App\Http\Controllers\Hr\WellbeingController@closeSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:575` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/duplicate` — `hr.wellbeing.surveys.duplicate` — `App\Http\Controllers\Hr\WellbeingController@duplicateSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:916` — middleware `web, auth, permission:hr.performance.manage`
- `GET|HEAD hr/wellbeing/surveys/{survey}/export` — `hr.wellbeing.surveys.export` — `App\Http\Controllers\Hr\WellbeingController@exportSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:964` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/nudge` — `hr.wellbeing.surveys.nudge` — `App\Http\Controllers\Hr\WellbeingController@nudgeSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:928` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/publish` — `hr.wellbeing.surveys.publish` — `App\Http\Controllers\Hr\WellbeingController@publishSurvey` — `app/Http/Controllers/Hr/WellbeingController.php:563` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/surveys/{survey}/responses` — `hr.wellbeing.surveys.responses.store` — `App\Http\Controllers\Hr\WellbeingController@submitResponse` — `app/Http/Controllers/Hr/WellbeingController.php:587` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/WellbeingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/wellbeing/survey.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
