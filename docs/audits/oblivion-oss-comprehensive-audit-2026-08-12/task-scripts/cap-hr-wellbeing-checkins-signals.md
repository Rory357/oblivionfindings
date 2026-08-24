# CAP-HR-WELLBEING-CHECKINS-SIGNALS: Wellbeing check-ins flags and signal response

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hr.wellbeing.view`, `permission:hr.performance.manage`
- Owning module: Human resources
- Legacy family: `HR-WELLBEING`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `hr/wellbeing` (`hr.wellbeing.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hr.wellbeing.view`, `permission:hr.performance.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hr.wellbeing.view`, `permission:hr.performance.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD hr/wellbeing` (`hr.wellbeing.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST hr/wellbeing/checkins` (`hr.wellbeing.checkins.store`, action `storeCheckin`). Source category: **created/recorded**; controller `app/Http/Controllers/Hr/WellbeingController.php:756-776`; `staff_user_id`.
3. Invoke only the owning control for `PATCH hr/wellbeing/checkins/{checkin}` (`hr.wellbeing.checkins.update`, action `updateCheckin`). Source category: **updated/revised**; controller `app/Http/Controllers/Hr/WellbeingController.php:778-796`; `type`.
4. Invoke only the owning control for `POST hr/wellbeing/checkins/{checkin}/acknowledge` (`hr.wellbeing.checkins.acknowledge`, action `acknowledgeCheckin`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/WellbeingController.php:798-808`; no exact validation fields extracted.
5. Invoke only the owning control for `POST hr/wellbeing/signals/{user}/acknowledge` (`hr.wellbeing.signals.acknowledge`, action `acknowledgeFlag`). Source category: **approved/acknowledged/verified**; controller `app/Http/Controllers/Hr/WellbeingController.php:694-697`; no exact validation fields extracted.
6. Invoke only the owning control for `POST hr/wellbeing/signals/{user}/dismiss` (`hr.wellbeing.signals.dismiss`, action `dismissFlag`). Source category: **mutation outcome source gap (dismissFlag)**; controller `app/Http/Controllers/Hr/WellbeingController.php:704-707`; no exact validation fields extracted.
7. Invoke only the owning control for `POST hr/wellbeing/signals/{user}/snooze` (`hr.wellbeing.signals.snooze`, action `snoozeFlag`). Source category: **mutation outcome source gap (snoozeFlag)**; controller `app/Http/Controllers/Hr/WellbeingController.php:699-702`; no exact validation fields extracted.
8. Invoke only the owning control for `POST hr/wellbeing/signals/{user}/undo` (`hr.wellbeing.signals.undo`, action `undoFlag`). Source category: **mutation outcome source gap (undoFlag)**; controller `app/Http/Controllers/Hr/WellbeingController.php:733-743`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1807` at `app/Http/Controllers/Hr/WellbeingController.php:31`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeCheckin` / `ROUTE-1813` at `app/Http/Controllers/Hr/WellbeingController.php:756`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateCheckin` / `ROUTE-1814` at `app/Http/Controllers/Hr/WellbeingController.php:778`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeCheckin` / `ROUTE-1815` at `app/Http/Controllers/Hr/WellbeingController.php:798`; it is not runtime-observed.
- **approved/acknowledged/verified** is applicable only to `acknowledgeFlag` / `ROUTE-1817` at `app/Http/Controllers/Hr/WellbeingController.php:694`; it is not runtime-observed.
- **mutation outcome source gap (dismissFlag)** is applicable only to `dismissFlag` / `ROUTE-1818` at `app/Http/Controllers/Hr/WellbeingController.php:704`; it is not runtime-observed.
- **mutation outcome source gap (snoozeFlag)** is applicable only to `snoozeFlag` / `ROUTE-1819` at `app/Http/Controllers/Hr/WellbeingController.php:699`; it is not runtime-observed.
- **mutation outcome source gap (undoFlag)** is applicable only to `undoFlag` / `ROUTE-1820` at `app/Http/Controllers/Hr/WellbeingController.php:733`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/hr/wellbeing/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1813` / `storeCheckin`: fields `staff_user_id`; success app/Http/Controllers/Hr/WellbeingController.php:775 `return redirect()->back()->with('success', 'Check-in logged.');`.
- `ROUTE-1814` / `updateCheckin`: fields `type`; success app/Http/Controllers/Hr/WellbeingController.php:795 `return redirect()->back()->with('success', 'Check-in updated.');`.
- `ROUTE-1815` / `acknowledgeCheckin`: success app/Http/Controllers/Hr/WellbeingController.php:807 `return redirect()->back()->with('success', 'Check-in acknowledged.');`.
- `ROUTE-1820` / `undoFlag`: success app/Http/Controllers/Hr/WellbeingController.php:742 `return redirect()->back()->with('success', 'Action undone.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/Hr/WellbeingController.php:128 `return Inertia::render('hr/wellbeing/index', [`; app/Http/Controllers/Hr/WellbeingController.php:775 `return redirect()->back()->with('success', 'Check-in logged.');`; app/Http/Controllers/Hr/WellbeingController.php:795 `return redirect()->back()->with('success', 'Check-in updated.');`; app/Http/Controllers/Hr/WellbeingController.php:807 `return redirect()->back()->with('success', 'Check-in acknowledged.');`; app/Http/Controllers/Hr/WellbeingController.php:696 `return $this->storeFlagAction($request, $user, 'acknowledge');`; app/Http/Controllers/Hr/WellbeingController.php:706 `return $this->storeFlagAction($request, $user, 'dismiss');`; app/Http/Controllers/Hr/WellbeingController.php:701 `return $this->storeFlagAction($request, $user, 'snooze');`; app/Http/Controllers/Hr/WellbeingController.php:742 `return redirect()->back()->with('success', 'Action undone.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD hr/wellbeing` — `hr.wellbeing.index` — `App\Http\Controllers\Hr\WellbeingController@index` — `app/Http/Controllers/Hr/WellbeingController.php:31` — middleware `web, auth, permission:hr.wellbeing.view`
- `POST hr/wellbeing/checkins` — `hr.wellbeing.checkins.store` — `App\Http\Controllers\Hr\WellbeingController@storeCheckin` — `app/Http/Controllers/Hr/WellbeingController.php:756` — middleware `web, auth, permission:hr.performance.manage`
- `PATCH hr/wellbeing/checkins/{checkin}` — `hr.wellbeing.checkins.update` — `App\Http\Controllers\Hr\WellbeingController@updateCheckin` — `app/Http/Controllers/Hr/WellbeingController.php:778` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/checkins/{checkin}/acknowledge` — `hr.wellbeing.checkins.acknowledge` — `App\Http\Controllers\Hr\WellbeingController@acknowledgeCheckin` — `app/Http/Controllers/Hr/WellbeingController.php:798` — middleware `web, auth`
- `POST hr/wellbeing/signals/{user}/acknowledge` — `hr.wellbeing.signals.acknowledge` — `App\Http\Controllers\Hr\WellbeingController@acknowledgeFlag` — `app/Http/Controllers/Hr/WellbeingController.php:694` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/signals/{user}/dismiss` — `hr.wellbeing.signals.dismiss` — `App\Http\Controllers\Hr\WellbeingController@dismissFlag` — `app/Http/Controllers/Hr/WellbeingController.php:704` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/signals/{user}/snooze` — `hr.wellbeing.signals.snooze` — `App\Http\Controllers\Hr\WellbeingController@snoozeFlag` — `app/Http/Controllers/Hr/WellbeingController.php:699` — middleware `web, auth, permission:hr.performance.manage`
- `POST hr/wellbeing/signals/{user}/undo` — `hr.wellbeing.signals.undo` — `App\Http\Controllers\Hr\WellbeingController@undoFlag` — `app/Http/Controllers/Hr/WellbeingController.php:733` — middleware `web, auth, permission:hr.performance.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Hr/WellbeingController.php`.
- Exact render/action page relationships: `resources/js/pages/hr/wellbeing/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
