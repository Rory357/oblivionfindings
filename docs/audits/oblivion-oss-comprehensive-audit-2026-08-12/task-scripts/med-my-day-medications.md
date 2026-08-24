# MED-MY-DAY-MEDICATIONS: My Day Medications

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`
- Owning module: eMAR and medications
- Legacy family: `MED-MY-DAY-MEDICATIONS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

ENTRY-GAP: no source-mapped human GET entry or production activator is established.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`.
- Exact middleware atoms: `web`, `auth`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. ENTRY-GAP: no source-mapped human GET entry exists; do not execute a mutation until a real UI entry/activator is established.
2. Invoke only the owning control for `POST my-day/medications/{medication}/administer` (`my-day.medications.administer`, action `administer`). Source category: **mutation outcome source gap (administer)**; controller `app/Http/Controllers/MyDayMedicationsController.php:36-85`; `scheduled_for`.
3. Invoke only the owning control for `POST my-day/medications/{medication}/refuse` (`my-day.medications.refuse`, action `refuse`). Source category: **mutation outcome source gap (refuse)**; controller `app/Http/Controllers/MyDayMedicationsController.php:93-140`; `scheduled_for`.
4. Invoke only the owning control for `POST my-day/medications/{medication}/snooze` (`my-day.medications.snooze`, action `snooze`). Source category: **mutation outcome source gap (snooze)**; controller `app/Http/Controllers/MyDayMedicationsController.php:150-178`; `minutes`.

## Source-applicable states and transitions

- **mutation outcome source gap (administer)** is applicable only to `administer` / `ROUTE-1887` at `app/Http/Controllers/MyDayMedicationsController.php:36`; it is not runtime-observed.
- **mutation outcome source gap (refuse)** is applicable only to `refuse` / `ROUTE-1888` at `app/Http/Controllers/MyDayMedicationsController.php:93`; it is not runtime-observed.
- **mutation outcome source gap (snooze)** is applicable only to `snooze` / `ROUTE-1889` at `app/Http/Controllers/MyDayMedicationsController.php:150`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1887` / `administer`: fields `scheduled_for`; success app/Http/Controllers/MyDayMedicationsController.php:84 `return back()->with('success', empty($result['duplicate']) ? 'Dose given.' : 'Dose already recorded.');`; failure app/Http/Controllers/MyDayMedicationsController.php:69 `return back()->withInput()->withErrors([`.
- `ROUTE-1888` / `refuse`: fields `scheduled_for`; success app/Http/Controllers/MyDayMedicationsController.php:139 `return back()->with('success', empty($result['duplicate']) ? 'Dose marked refused.' : 'Dose already recorded.');`; failure app/Http/Controllers/MyDayMedicationsController.php:122 `return back()->withInput()->withErrors([`.
- `ROUTE-1889` / `snooze`: fields `minutes`; success app/Http/Controllers/MyDayMedicationsController.php:177 `return back()->with('success', "Snoozed {$minutes}m.");`.

## Failure and recovery paths

- `administer`: app/Http/Controllers/MyDayMedicationsController.php:69 `return back()->withInput()->withErrors([`.
- `refuse`: app/Http/Controllers/MyDayMedicationsController.php:122 `return back()->withInput()->withErrors([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: no persistence call extracted; responses app/Http/Controllers/MyDayMedicationsController.php:69 `return back()->withInput()->withErrors([`; app/Http/Controllers/MyDayMedicationsController.php:84 `return back()->with('success', empty($result['duplicate']) ? 'Dose given.' : 'Dose already recorded.');`; app/Http/Controllers/MyDayMedicationsController.php:122 `return back()->withInput()->withErrors([`; app/Http/Controllers/MyDayMedicationsController.php:139 `return back()->with('success', empty($result['duplicate']) ? 'Dose marked refused.' : 'Dose already recorded.');`; app/Http/Controllers/MyDayMedicationsController.php:177 `return back()->with('success', "Snoozed {$minutes}m.");`; audit calls app/Http/Controllers/MyDayMedicationsController.php:77 `AuditLogger::log('meds.administer', $administration, [`; app/Http/Controllers/MyDayMedicationsController.php:130 `AuditLogger::log('meds.refuse', $administration, [`; app/Http/Controllers/MyDayMedicationsController.php:170 `AuditLogger::log('meds.snooze', $medication, [`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST my-day/medications/{medication}/administer` — `my-day.medications.administer` — `App\Http\Controllers\MyDayMedicationsController@administer` — `app/Http/Controllers/MyDayMedicationsController.php:36` — middleware `web, auth`
- `POST my-day/medications/{medication}/refuse` — `my-day.medications.refuse` — `App\Http\Controllers\MyDayMedicationsController@refuse` — `app/Http/Controllers/MyDayMedicationsController.php:93` — middleware `web, auth`
- `POST my-day/medications/{medication}/snooze` — `my-day.medications.snooze` — `App\Http\Controllers\MyDayMedicationsController@snooze` — `app/Http/Controllers/MyDayMedicationsController.php:150` — middleware `web, auth`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/MyDayMedicationsController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
