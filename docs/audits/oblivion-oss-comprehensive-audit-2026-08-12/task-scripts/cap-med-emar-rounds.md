# CAP-MED-EMAR-ROUNDS: Medication rounds templates generation assignment and completion

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar/mar` (`emar.mar`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.view`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar/mar` (`emar.mar`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD emar/rounds` (`emar.rounds`, action `rounds`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/Emar/EmarController.php:2440-2621`.
3. Invoke only the owning control for `PUT emar/rounds/{round}/assign` (`emar.rounds.assign`, action `assignRound`). Source category: **mutation outcome source gap (assignRound)**; controller `app/Http/Controllers/Emar/EmarController.php:3693-3702`; `assigned_to`.
4. Invoke only the owning control for `POST emar/rounds/{round}/complete` (`emar.rounds.complete`, action `completeRound`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/EmarController.php:3680-3691`; no exact validation fields extracted.
5. Invoke only the owning control for `POST emar/rounds/{round}/start` (`emar.rounds.start`, action `startRound`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3669-3678`; no exact validation fields extracted.
6. Invoke only the owning control for `POST emar/rounds/generate` (`emar.rounds.generate`, action `generateRounds`). Source category: **mutation outcome source gap (generateRounds)**; controller `app/Http/Controllers/Emar/EmarController.php:3621-3667`; `date`, `generate_all`.
7. Invoke only the owning control for `POST emar/rounds/templates` (`emar.rounds.templates.store`, action `storeRoundTemplate`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3575-3593`; `name`, `scheduled_time`, `window_minutes`, `days_of_week`, `site_id`, `service_context_id`, `default_assigned_to`.
8. Invoke only the owning control for `DELETE emar/rounds/templates/{template}` (`emar.rounds.templates.destroy`, action `destroyRoundTemplate`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/Emar/EmarController.php:3614-3619`; no exact validation fields extracted.
9. Invoke only the owning control for `PUT emar/rounds/templates/{template}` (`emar.rounds.templates.update`, action `updateRoundTemplate`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3595-3612`; `name`, `scheduled_time`, `window_minutes`, `days_of_week`, `site_id`, `service_context_id`, `active`, `default_assigned_to`.

## Source-applicable states and transitions

- **information presented** is applicable only to `mar` / `ROUTE-0383` at `app/Http/Controllers/Emar/EmarController.php:811`; it is not runtime-observed.
- **information presented** is applicable only to `rounds` / `ROUTE-0417` at `app/Http/Controllers/Emar/EmarController.php:2440`; it is not runtime-observed.
- **mutation outcome source gap (assignRound)** is applicable only to `assignRound` / `ROUTE-0418` at `app/Http/Controllers/Emar/EmarController.php:3693`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `completeRound` / `ROUTE-0419` at `app/Http/Controllers/Emar/EmarController.php:3680`; it is not runtime-observed.
- **created/recorded** is applicable only to `startRound` / `ROUTE-0423` at `app/Http/Controllers/Emar/EmarController.php:3669`; it is not runtime-observed.
- **mutation outcome source gap (generateRounds)** is applicable only to `generateRounds` / `ROUTE-0424` at `app/Http/Controllers/Emar/EmarController.php:3621`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeRoundTemplate` / `ROUTE-0425` at `app/Http/Controllers/Emar/EmarController.php:3575`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyRoundTemplate` / `ROUTE-0426` at `app/Http/Controllers/Emar/EmarController.php:3614`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateRoundTemplate` / `ROUTE-0427` at `app/Http/Controllers/Emar/EmarController.php:3595`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/emar/MarCharts.tsx`, `resources/js/pages/emar/Rounds.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-0418` / `assignRound`: fields `assigned_to`.
- `ROUTE-0424` / `generateRounds`: fields `date`, `generate_all`.
- `ROUTE-0425` / `storeRoundTemplate`: fields `name`, `scheduled_time`, `window_minutes`, `days_of_week`, `site_id`, `service_context_id`, `default_assigned_to`.
- `ROUTE-0427` / `updateRoundTemplate`: fields `name`, `scheduled_time`, `window_minutes`, `days_of_week`, `site_id`, `service_context_id`, `active`, `default_assigned_to`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3699 `$round->update($validated);`; app/Http/Controllers/Emar/EmarController.php:3682 `$round->update([`; app/Http/Controllers/Emar/EmarController.php:3671 `$round->update([`; app/Http/Controllers/Emar/EmarController.php:3649 `MedicationRound::create([`; app/Http/Controllers/Emar/EmarController.php:3590 `MedicationRoundTemplate::create($validated);`; app/Http/Controllers/Emar/EmarController.php:3616 `$template->delete();`; app/Http/Controllers/Emar/EmarController.php:3609 `$template->update($validated);`; responses app/Http/Controllers/Emar/EmarController.php:892 `return Inertia::render('emar/MarCharts', [`; app/Http/Controllers/Emar/EmarController.php:2472 `return [`; app/Http/Controllers/Emar/EmarController.php:2603 `return Inertia::render('emar/Rounds', [`; app/Http/Controllers/Emar/EmarController.php:3701 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3690 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3677 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3666 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3592 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3618 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3611 `return redirect()->back();`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD emar/mar` — `emar.mar` — `App\Http\Controllers\Emar\EmarController@mar` — `app/Http/Controllers/Emar/EmarController.php:811` — middleware `web, auth, permission:medications.view`
- `GET|HEAD emar/rounds` — `emar.rounds` — `App\Http\Controllers\Emar\EmarController@rounds` — `app/Http/Controllers/Emar/EmarController.php:2440` — middleware `web, auth, permission:medications.view`
- `PUT emar/rounds/{round}/assign` — `emar.rounds.assign` — `App\Http\Controllers\Emar\EmarController@assignRound` — `app/Http/Controllers/Emar/EmarController.php:3693` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/rounds/{round}/complete` — `emar.rounds.complete` — `App\Http\Controllers\Emar\EmarController@completeRound` — `app/Http/Controllers/Emar/EmarController.php:3680` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/rounds/{round}/start` — `emar.rounds.start` — `App\Http\Controllers\Emar\EmarController@startRound` — `app/Http/Controllers/Emar/EmarController.php:3669` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/rounds/generate` — `emar.rounds.generate` — `App\Http\Controllers\Emar\EmarController@generateRounds` — `app/Http/Controllers/Emar/EmarController.php:3621` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/rounds/templates` — `emar.rounds.templates.store` — `App\Http\Controllers\Emar\EmarController@storeRoundTemplate` — `app/Http/Controllers/Emar/EmarController.php:3575` — middleware `web, auth, permission:medications.orders.manage`
- `DELETE emar/rounds/templates/{template}` — `emar.rounds.templates.destroy` — `App\Http\Controllers\Emar\EmarController@destroyRoundTemplate` — `app/Http/Controllers/Emar/EmarController.php:3614` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/rounds/templates/{template}` — `emar.rounds.templates.update` — `App\Http\Controllers\Emar\EmarController@updateRoundTemplate` — `app/Http/Controllers/Emar/EmarController.php:3595` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: `resources/js/pages/emar/MarCharts.tsx`, `resources/js/pages/emar/Rounds.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
