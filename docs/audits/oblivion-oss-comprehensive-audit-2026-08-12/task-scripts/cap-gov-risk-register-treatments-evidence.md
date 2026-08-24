# CAP-GOV-RISK-REGISTER-TREATMENTS-EVIDENCE: Risk treatment actions and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`
- Owning module: Governance
- Legacy family: `GOV-RISK-REGISTER`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `governance/risks/{risk}/treatments/{treatment}/attachments/{attachment}/download` (`governance.risks.treatments.attachments.download`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:governance.risks.view`, `permission:governance.risks.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD governance/risks/{risk}/treatments/{treatment}/attachments/{attachment}/download` (`governance.risks.treatments.attachments.download`); the route is exact, but menu visibility and runtime access were not executed.
2. Invoke only the owning control for `POST governance/risks/{risk}/treatments` (`governance.risks.treatments.add`, action `addTreatment`). Source category: **created/recorded**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:208-229`; `action_description`, `assigned_to`, `due_date`, `expected_score_reduction`, `evidence_required`.
3. Invoke only the owning control for `POST governance/risks/{risk}/treatments/{treatment}/attachments` (`governance.risks.treatments.attachments.store`, action `attachTreatmentFiles`). Source category: **mutation outcome source gap (attachTreatmentFiles)**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:319-366`; `files`.
4. Invoke only the owning control for `DELETE governance/risks/{risk}/treatments/{treatment}/attachments/{attachment}` (`governance.risks.treatments.attachments.destroy`, action `deleteTreatmentAttachment`). Source category: **cancelled/removed/archived**; controller `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:368-404`; no exact validation fields extracted.

## Source-applicable states and transitions

- **created/recorded** is applicable only to `addTreatment` / `ROUTE-1009` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:208`; it is not runtime-observed.
- **mutation outcome source gap (attachTreatmentFiles)** is applicable only to `attachTreatmentFiles` / `ROUTE-1010` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:319`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `deleteTreatmentAttachment` / `ROUTE-1011` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:368`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadTreatmentAttachment` / `ROUTE-1012` at `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:406`; it is not runtime-observed.
- No mapped render/action page exists; presentation states are not applicable from current evidence.

## Validation and source-visible errors

- `ROUTE-1009` / `addTreatment`: fields `action_description`, `assigned_to`, `due_date`, `expected_score_reduction`, `evidence_required`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:228 `return redirect()->back()->with('success', 'Treatment action added.');`.
- `ROUTE-1010` / `attachTreatmentFiles`: fields `files`; success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:365 `: redirect()->back()->with('success', 'Evidence attached to treatment.');`.
- `ROUTE-1011` / `deleteTreatmentAttachment`: success app/Domain/Governance/Http/Controllers/RiskRegisterController.php:403 `: redirect()->back()->with('success', 'Evidence removed.');`; failure app/Domain/Governance/Http/Controllers/RiskRegisterController.php:381 `abort(404, 'Attachment not found.');`.
- `ROUTE-1012` / `downloadTreatmentAttachment`: failure app/Domain/Governance/Http/Controllers/RiskRegisterController.php:418 `abort(404, 'Attachment not found.');`.

## Failure and recovery paths

- `deleteTreatmentAttachment`: app/Domain/Governance/Http/Controllers/RiskRegisterController.php:381 `abort(404, 'Attachment not found.');`.
- `downloadTreatmentAttachment`: app/Domain/Governance/Http/Controllers/RiskRegisterController.php:418 `abort(404, 'Attachment not found.');`.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Domain/Governance/Http/Controllers/RiskRegisterController.php:354 `$treatment->update(['evidence_attachments' => $existing]);`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:385 `Storage::disk('local')->delete($target['path']);`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:392 `$treatment->update(['evidence_attachments' => $remaining]);`; responses app/Domain/Governance/Http/Controllers/RiskRegisterController.php:228 `return redirect()->back()->with('success', 'Treatment action added.');`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:363 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:364 `? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:401 `return $request->wantsJson()`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:402 `? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])`; app/Domain/Governance/Http/Controllers/RiskRegisterController.php:421 `return Storage::disk('local')->download(`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `POST governance/risks/{risk}/treatments` — `governance.risks.treatments.add` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@addTreatment` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:208` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `POST governance/risks/{risk}/treatments/{treatment}/attachments` — `governance.risks.treatments.attachments.store` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@attachTreatmentFiles` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:319` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `DELETE governance/risks/{risk}/treatments/{treatment}/attachments/{attachment}` — `governance.risks.treatments.attachments.destroy` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@deleteTreatmentAttachment` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:368` — middleware `web, auth, permission:governance.risks.view, permission:governance.risks.manage`
- `GET|HEAD governance/risks/{risk}/treatments/{treatment}/attachments/{attachment}/download` — `governance.risks.treatments.attachments.download` — `App\Domain\Governance\Http\Controllers\RiskRegisterController@downloadTreatmentAttachment` — `app/Domain/Governance/Http/Controllers/RiskRegisterController.php:406` — middleware `web, auth, permission:governance.risks.view`

## Source anchors and limits

- Backend anchor: `app/Domain/Governance/Http/Controllers/RiskRegisterController.php`.
- Exact render/action page relationships: none mapped.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
