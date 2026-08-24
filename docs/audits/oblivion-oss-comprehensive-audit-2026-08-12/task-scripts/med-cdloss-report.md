# MED-CDLOSS-REPORT: CDLoss Report

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.controlled.view|clients.update`, `permission:medications.controlled.record|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-CDLOSS-REPORT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/controlled/loss-reports` (`emar.cd_loss.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.controlled.view|clients.update`, `permission:medications.controlled.record|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.controlled.view|clients.update`, `permission:medications.controlled.record|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/controlled/loss-reports` (`emar.cd_loss.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/controlled/loss-reports` (`emar.cd_loss.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/CDLossReportController.php:27-113`; `client_id`.
3. Invoke only the owning control for `POST emar/controlled/loss-reports/{report}/investigate` (`emar.cd_loss.investigate`, action `investigate`). Source category: **mutation outcome source gap (investigate)**; controller `app/Http/Controllers/Emar/CDLossReportController.php:115-127`; `investigation_notes`.
4. Invoke only the owning control for `POST emar/controlled/loss-reports/{report}/resolve` (`emar.cd_loss.resolve`, action `resolve`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/CDLossReportController.php:129-149`; `resolution_outcome`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0355` at `app/Http/Controllers/Emar/CDLossReportController.php:15`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0356` at `app/Http/Controllers/Emar/CDLossReportController.php:27`; it is not runtime-observed.
- **mutation outcome source gap (investigate)** is applicable only to `investigate` / `ROUTE-0357` at `app/Http/Controllers/Emar/CDLossReportController.php:115`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolve` / `ROUTE-0358` at `app/Http/Controllers/Emar/CDLossReportController.php:129`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0356` / `store`: fields `client_id`; success app/Http/Controllers/Emar/CDLossReportController.php:112 `return redirect()->back()->with('success', 'Controlled drug loss report submitted.');`.
- `ROUTE-0357` / `investigate`: fields `investigation_notes`; success app/Http/Controllers/Emar/CDLossReportController.php:126 `return redirect()->back()->with('success', 'Investigation notes updated.');`.
- `ROUTE-0358` / `resolve`: fields `resolution_outcome`; success app/Http/Controllers/Emar/CDLossReportController.php:148 `return redirect()->back()->with('success', 'Loss report resolved.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/CDLossReportController.php:74 `$report = ControlledDrugLossReport::create($validated);`; app/Http/Controllers/Emar/CDLossReportController.php:81 `$report->forceFill(['incident_id' => $incident->id])->save();`; app/Http/Controllers/Emar/CDLossReportController.php:121 `$report->update([`; app/Http/Controllers/Emar/CDLossReportController.php:135 `$report->update([`; responses app/Http/Controllers/Emar/CDLossReportController.php:24 `return response()->json($reports);`; app/Http/Controllers/Emar/CDLossReportController.php:56 `return response()->json($cached);`; app/Http/Controllers/Emar/CDLossReportController.php:99 `return response()->json(`; app/Http/Controllers/Emar/CDLossReportController.php:112 `return redirect()->back()->with('success', 'Controlled drug loss report submitted.');`; app/Http/Controllers/Emar/CDLossReportController.php:126 `return redirect()->back()->with('success', 'Investigation notes updated.');`; app/Http/Controllers/Emar/CDLossReportController.php:148 `return redirect()->back()->with('success', 'Loss report resolved.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/controlled/loss-reports` — `emar.cd_loss.index` — `App\Http\Controllers\Emar\CDLossReportController@index` — `app/Http/Controllers/Emar/CDLossReportController.php:15` — middleware `web, auth, permission:medications.controlled.view|clients.update`
- `POST emar/controlled/loss-reports` — `emar.cd_loss.store` — `App\Http\Controllers\Emar\CDLossReportController@store` — `app/Http/Controllers/Emar/CDLossReportController.php:27` — middleware `web, auth, permission:medications.controlled.record|clients.update`
- `POST emar/controlled/loss-reports/{report}/investigate` — `emar.cd_loss.investigate` — `App\Http\Controllers\Emar\CDLossReportController@investigate` — `app/Http/Controllers/Emar/CDLossReportController.php:115` — middleware `web, auth, permission:medications.controlled.record|clients.update`
- `POST emar/controlled/loss-reports/{report}/resolve` — `emar.cd_loss.resolve` — `App\Http\Controllers\Emar\CDLossReportController@resolve` — `app/Http/Controllers/Emar/CDLossReportController.php:129` — middleware `web, auth, permission:medications.controlled.record|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/CDLossReportController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
