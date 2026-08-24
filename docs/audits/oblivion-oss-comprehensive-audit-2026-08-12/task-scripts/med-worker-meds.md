# MED-WORKER-MEDS: Worker Meds

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-WORKER-MEDS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `meds/today` (`meds.today`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.administer.record|clients.update|medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD meds/today` (`meds.today`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST meds/today/prn` (`meds.today.prn`, action `recordPrn`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/WorkerMedsController.php:275-349`; `client_medication_id`.
3. Invoke only the owning control for `POST meds/today/prn/effect` (`meds.today.prn_effect`, action `recordPrnEffect`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/WorkerMedsController.php:356-424`; `client_medication_administration_id`.
4. Invoke only the owning control for `POST meds/today/record` (`meds.today.record`, action `recordDose`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/WorkerMedsController.php:161-264`; `client_medication_id`.

## Source-applicable states and transitions

- **information presented** is applicable only to `today` / `ROUTE-1878` at `app/Http/Controllers/Emar/WorkerMedsController.php:63`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordPrn` / `ROUTE-1879` at `app/Http/Controllers/Emar/WorkerMedsController.php:275`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordPrnEffect` / `ROUTE-1880` at `app/Http/Controllers/Emar/WorkerMedsController.php:356`; it is not runtime-observed.
- **created/recorded** is applicable only to `recordDose` / `ROUTE-1881` at `app/Http/Controllers/Emar/WorkerMedsController.php:161`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/meds/today/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1879` / `recordPrn`: fields `client_medication_id`; failure app/Http/Controllers/Emar/WorkerMedsController.php:337 `return back()->withErrors([`.
- `ROUTE-1880` / `recordPrnEffect`: fields `client_medication_administration_id`.
- `ROUTE-1881` / `recordDose`: fields `client_medication_id`; success app/Http/Controllers/Emar/WorkerMedsController.php:262 `return back()->with('success', $medication->name.' '.$outcome.' for '.$clientName);`; failure app/Http/Controllers/Emar/WorkerMedsController.php:233 `return back()->withErrors([`.

## Failure and recovery paths

- `recordPrn`: app/Http/Controllers/Emar/WorkerMedsController.php:337 `return back()->withErrors([`.
- `recordDose`: app/Http/Controllers/Emar/WorkerMedsController.php:233 `return back()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/WorkerMedsController.php:403 `MedicationPrnEffectiveness::updateOrCreate(`; responses app/Http/Controllers/Emar/WorkerMedsController.php:94 `return false;`; app/Http/Controllers/Emar/WorkerMedsController.php:98 `return $scheduled->gte($windowStart) && $scheduled->lte($windowEnd);`; app/Http/Controllers/Emar/WorkerMedsController.php:114 `return Inertia::render('meds/today/index', [`; app/Http/Controllers/Emar/WorkerMedsController.php:302 `return $this->runOfflineSubmissionOnce('prn', $data, function () use ($user, $data) {`; app/Http/Controllers/Emar/WorkerMedsController.php:337 `return back()->withErrors([`; app/Http/Controllers/Emar/WorkerMedsController.php:344 `return back()->with(`; app/Http/Controllers/Emar/WorkerMedsController.php:418 `return back()->with(`; app/Http/Controllers/Emar/WorkerMedsController.php:191 `return $this->runOfflineSubmissionOnce('dose', $data, function () use ($user, $data) {`; app/Http/Controllers/Emar/WorkerMedsController.php:233 `return back()->withErrors([`; app/Http/Controllers/Emar/WorkerMedsController.php:241 `return back()->with('warning', 'This dose was already recorded — no changes made.');`; app/Http/Controllers/Emar/WorkerMedsController.php:262 `return back()->with('success', $medication->name.' '.$outcome.' for '.$clientName);`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD meds/today` — `meds.today` — `App\Http\Controllers\Emar\WorkerMedsController@today` — `app/Http/Controllers/Emar/WorkerMedsController.php:63` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `POST meds/today/prn` — `meds.today.prn` — `App\Http\Controllers\Emar\WorkerMedsController@recordPrn` — `app/Http/Controllers/Emar/WorkerMedsController.php:275` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `POST meds/today/prn/effect` — `meds.today.prn_effect` — `App\Http\Controllers\Emar\WorkerMedsController@recordPrnEffect` — `app/Http/Controllers/Emar/WorkerMedsController.php:356` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`
- `POST meds/today/record` — `meds.today.record` — `App\Http\Controllers\Emar\WorkerMedsController@recordDose` — `app/Http/Controllers/Emar/WorkerMedsController.php:161` — middleware `web, auth, permission:medications.administer.record|clients.update|medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/WorkerMedsController.php`.
- Exact render/action page relationships: `resources/js/pages/meds/today/index.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
