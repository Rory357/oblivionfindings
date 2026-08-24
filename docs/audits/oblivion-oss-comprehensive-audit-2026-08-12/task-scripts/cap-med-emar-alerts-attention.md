# CAP-MED-EMAR-ALERTS-ATTENTION: Medication alerts attention records and suppression

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:medications.orders.manage`
- Owning module: eMAR and medications
- Legacy family: `MED-EMAR`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `emar` (`emar.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:medications.orders.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:medications.orders.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD emar` (`emar.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST emar/alerts/{alert}/dismiss` (`emar.alerts.dismiss`, action `dismissAlert`). Source category: **mutation outcome source gap (dismissAlert)**; controller `app/Http/Controllers/Emar/EmarController.php:4875-4880`; no exact validation fields extracted.
3. Invoke only the owning control for `PUT emar/attention-alerts/{alert}` (`emar.attention_alerts.update`, action `updateAttentionAlert`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3206-3220`; `type`.
4. Invoke only the owning control for `POST emar/attention-alerts/{alert}/resolve` (`emar.attention_alerts.resolve`, action `resolveAttentionAlert`). Source category: **completed/closed/released**; controller `app/Http/Controllers/Emar/EmarController.php:3222-3227`; no exact validation fields extracted.
5. Invoke only the owning control for `POST emar/clients/{client}/alert-suppression` (`emar.clients.alert_suppression`, action `toggleMedicationAlertSuppression`). Source category: **updated/revised**; controller `app/Http/Controllers/Emar/EmarController.php:3229-3272`; `suppress_med_admin_alerts`.
6. Invoke only the owning control for `POST emar/clients/{client}/attention-alerts` (`emar.clients.attention_alerts.store`, action `storeAttentionAlert`). Source category: **created/recorded**; controller `app/Http/Controllers/Emar/EmarController.php:3184-3204`; `type`.

## Source-applicable states and transitions

- **mutation outcome source gap (dismissAlert)** is applicable only to `dismissAlert` / `ROUTE-0328` at `app/Http/Controllers/Emar/EmarController.php:4875`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateAttentionAlert` / `ROUTE-0329` at `app/Http/Controllers/Emar/EmarController.php:3206`; it is not runtime-observed.
- **completed/closed/released** is applicable only to `resolveAttentionAlert` / `ROUTE-0330` at `app/Http/Controllers/Emar/EmarController.php:3222`; it is not runtime-observed.
- **updated/revised** is applicable only to `toggleMedicationAlertSuppression` / `ROUTE-0338` at `app/Http/Controllers/Emar/EmarController.php:3229`; it is not runtime-observed.
- **created/recorded** is applicable only to `storeAttentionAlert` / `ROUTE-0339` at `app/Http/Controllers/Emar/EmarController.php:3184`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-0329` / `updateAttentionAlert`: fields `type`; success app/Http/Controllers/Emar/EmarController.php:3219 `return redirect()->back()->with('success', 'Medication chart alert updated.');`.
- `ROUTE-0330` / `resolveAttentionAlert`: success app/Http/Controllers/Emar/EmarController.php:3226 `return redirect()->back()->with('success', 'Medication chart alert resolved.');`.
- `ROUTE-0338` / `toggleMedicationAlertSuppression`: fields `suppress_med_admin_alerts`; success app/Http/Controllers/Emar/EmarController.php:3271 `return redirect()->back()->with('success', 'Medication alert settings updated.');`; failure app/Http/Controllers/Emar/EmarController.php:3241 `throw ValidationException::withMessages([`; app/Http/Controllers/Emar/EmarController.php:3247 `throw ValidationException::withMessages([`.
- `ROUTE-0339` / `storeAttentionAlert`: fields `type`; success app/Http/Controllers/Emar/EmarController.php:3203 `return redirect()->back()->with('success', 'Medication chart alert added.');`.

## Failure and recovery paths

- `toggleMedicationAlertSuppression`: app/Http/Controllers/Emar/EmarController.php:3241 `throw ValidationException::withMessages([`; app/Http/Controllers/Emar/EmarController.php:3247 `throw ValidationException::withMessages([`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/Emar/EmarController.php:3216 `$alert->update($validated);`; app/Http/Controllers/Emar/EmarController.php:3267 `])->save();`; app/Http/Controllers/Emar/EmarController.php:3194 `$client->medicationAlerts()->create([`; responses app/Http/Controllers/Emar/EmarController.php:4879 `return redirect()->back();`; app/Http/Controllers/Emar/EmarController.php:3219 `return redirect()->back()->with('success', 'Medication chart alert updated.');`; app/Http/Controllers/Emar/EmarController.php:3226 `return redirect()->back()->with('success', 'Medication chart alert resolved.');`; app/Http/Controllers/Emar/EmarController.php:3271 `return redirect()->back()->with('success', 'Medication alert settings updated.');`; app/Http/Controllers/Emar/EmarController.php:3203 `return redirect()->back()->with('success', 'Medication chart alert added.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST emar/alerts/{alert}/dismiss` — `emar.alerts.dismiss` — `App\Http\Controllers\Emar\EmarController@dismissAlert` — `app/Http/Controllers/Emar/EmarController.php:4875` — middleware `web, auth, permission:medications.orders.manage`
- `PUT emar/attention-alerts/{alert}` — `emar.attention_alerts.update` — `App\Http\Controllers\Emar\EmarController@updateAttentionAlert` — `app/Http/Controllers/Emar/EmarController.php:3206` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/attention-alerts/{alert}/resolve` — `emar.attention_alerts.resolve` — `App\Http\Controllers\Emar\EmarController@resolveAttentionAlert` — `app/Http/Controllers/Emar/EmarController.php:3222` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/clients/{client}/alert-suppression` — `emar.clients.alert_suppression` — `App\Http\Controllers\Emar\EmarController@toggleMedicationAlertSuppression` — `app/Http/Controllers/Emar/EmarController.php:3229` — middleware `web, auth, permission:medications.orders.manage`
- `POST emar/clients/{client}/attention-alerts` — `emar.clients.attention_alerts.store` — `App\Http\Controllers\Emar\EmarController@storeAttentionAlert` — `app/Http/Controllers/Emar/EmarController.php:3184` — middleware `web, auth, permission:medications.orders.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/Emar/EmarController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
