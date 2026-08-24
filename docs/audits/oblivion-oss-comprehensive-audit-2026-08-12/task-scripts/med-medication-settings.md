# MED-MEDICATION-SETTINGS: Medication Settings

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.settings.manage|medications.orders.manage|clients.update`
- Owning module: eMAR and medications
- Legacy family: `MED-MEDICATION-SETTINGS`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/settings` (`emar.settings`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.settings.manage|medications.orders.manage|clients.update`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.settings.manage|medications.orders.manage|clients.update`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/settings` (`emar.settings`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/settings/rules` (`emar.settings.rules.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/MedicationSettingsController.php:71-83`; no exact validation fields extracted.
3. Invoke only the owning control for `DELETE emar/settings/rules/{rule}` (`emar.settings.rules.destroy`, action `destroy`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/MedicationSettingsController.php:94-101`; no exact validation fields extracted.
4. Invoke only the owning control for `PUT emar/settings/rules/{rule}` (`emar.settings.rules.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/MedicationSettingsController.php:85-92`; no exact validation fields extracted.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-0432` at `app/Http/Controllers/Emar/MedicationSettingsController.php:39`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-0433` at `app/Http/Controllers/Emar/MedicationSettingsController.php:71`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroy` / `ROUTE-0434` at `app/Http/Controllers/Emar/MedicationSettingsController.php:94`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-0435` at `app/Http/Controllers/Emar/MedicationSettingsController.php:85`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/Settings.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0433` / `store`: success app/Http/Controllers/Emar/MedicationSettingsController.php:82 `return redirect()->back()->with('success', 'Medication administration rule added.');`.
- `ROUTE-0434` / `destroy`: success app/Http/Controllers/Emar/MedicationSettingsController.php:100 `return redirect()->back()->with('success', 'Medication administration rule removed.');`.
- `ROUTE-0435` / `update`: success app/Http/Controllers/Emar/MedicationSettingsController.php:91 `return redirect()->back()->with('success', 'Medication administration rule updated.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/MedicationSettingsController.php:77 `MedicationAdminRule::create([`; app/Http/Controllers/Emar/MedicationSettingsController.php:98 `$rule->delete();`; app/Http/Controllers/Emar/MedicationSettingsController.php:89 `$rule->update($this->validateRule($request));`; responses app/Http/Controllers/Emar/MedicationSettingsController.php:60 `return Inertia::render('emar/Settings', [`; app/Http/Controllers/Emar/MedicationSettingsController.php:82 `return redirect()->back()->with('success', 'Medication administration rule added.');`; app/Http/Controllers/Emar/MedicationSettingsController.php:100 `return redirect()->back()->with('success', 'Medication administration rule removed.');`; app/Http/Controllers/Emar/MedicationSettingsController.php:91 `return redirect()->back()->with('success', 'Medication administration rule updated.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/settings` — `emar.settings` — `App\Http\Controllers\Emar\MedicationSettingsController@index` — `app/Http/Controllers/Emar/MedicationSettingsController.php:39` — middleware `web, auth, permission:medications.settings.manage|medications.orders.manage|clients.update`
- `POST emar/settings/rules` — `emar.settings.rules.store` — `App\Http\Controllers\Emar\MedicationSettingsController@store` — `app/Http/Controllers/Emar/MedicationSettingsController.php:71` — middleware `web, auth, permission:medications.settings.manage|medications.orders.manage|clients.update`
- `DELETE emar/settings/rules/{rule}` — `emar.settings.rules.destroy` — `App\Http\Controllers\Emar\MedicationSettingsController@destroy` — `app/Http/Controllers/Emar/MedicationSettingsController.php:94` — middleware `web, auth, permission:medications.settings.manage|medications.orders.manage|clients.update`
- `PUT emar/settings/rules/{rule}` — `emar.settings.rules.update` — `App\Http\Controllers\Emar\MedicationSettingsController@update` — `app/Http/Controllers/Emar/MedicationSettingsController.php:85` — middleware `web, auth, permission:medications.settings.manage|medications.orders.manage|clients.update`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/MedicationSettingsController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/Settings.tsx`.
- Capability basis: unchanged current family; no >6-write umbrella split triggered
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
