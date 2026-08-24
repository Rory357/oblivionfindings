# CAP-HS-HS-RISK-ASSESSMENT-DRAFT-EVIDENCE: Risk assessment drafting residual risk and evidence

- Projection status: **Provisional route-derived capability; accepted denominator blocked**
- Actor/job owner: Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`
- Owning module: Health and safety
- Legacy family: `HS-HS-RISK-ASSESSMENT`
- Audited source: `081ef198f9f992f224e8c0c9fba33df33dde40be`
- Validation: **Blocked—static source-specific script; not runtime-executed or independently semantically validated**
- Benchmark: Pass 3 pending separate capability-level adjudication

## Start condition and entry

Start at `health-safety/risk-assessments` (`health-safety.risk-assessments.index`). Route existence is exact; visibility and access are unexecuted.

## Goal and completion state

Complete only the exact routed mutation(s) and verify only source-defined persistence/response evidence.

## Prerequisites

- Actor satisfying exact route middleware `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Exact middleware atoms: `web`, `auth`, `permission:hazards.view`, `permission:hazards.manage`.
- Required route-bound objects/relationships must exist; site, ownership, privacy, parent binding and direct-object denial are unexecuted unless explicitly shown in the method evidence below.
- A resettable synthetic prerequisite record is required; this read-only audit mutated nothing.

## Ordered task and decisions

1. Enter through `GET|HEAD health-safety/risk-assessments` (`health-safety.risk-assessments.index`); the route is exact, but menu visibility and runtime access were not executed.
2. Use `GET|HEAD health-safety/risk-assessments/{assessment}` (`health-safety.risk-assessments.show`, action `show`) only from its authorised surface; inspect the returned information. Source: `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:108-115`.
3. Use `GET|HEAD health-safety/risk-assessments/{assessment}/attachments/{attachment}/download` (`health-safety.risk-assessments.attachments.download`, action `downloadAttachment`) only from its authorised surface; inspect the returned file/report. Source: `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:262-273`.
4. Invoke only the owning control for `POST health-safety/risk-assessments` (`health-safety.risk-assessments.store`, action `store`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:121-128`; FormRequest `app/Http/Requests/HealthSafety/StoreHsRiskAssessmentRequest.php:23`; `title`, `risk_description`, `attach_type`, `attach_id`, `likelihood`, `consequence`, `existing_controls`, `additional_controls`, `residual_likelihood`, `residual_consequence`, `risk_acceptable`, `review_frequency_days`, `review_due_at`.
5. Invoke only the owning control for `PUT health-safety/risk-assessments/{assessment}` (`health-safety.risk-assessments.update`, action `update`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:130-165`; FormRequest `app/Http/Requests/HealthSafety/UpdateHsRiskAssessmentRequest.php:line unresolved`; no exact validation fields extracted.
6. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/attachments` (`health-safety.risk-assessments.attachments.store`, action `uploadAttachment`). Source category: **created/recorded**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:234-260`; `file`.
7. Invoke only the owning control for `DELETE health-safety/risk-assessments/{assessment}/attachments/{attachment}` (`health-safety.risk-assessments.attachments.destroy`, action `destroyAttachment`). Source category: **cancelled/removed/archived**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:275-286`; no exact validation fields extracted.
8. Invoke only the owning control for `POST health-safety/risk-assessments/{assessment}/residual` (`health-safety.risk-assessments.residual`, action `updateResidual`). Source category: **updated/revised**; controller `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:193-208`; FormRequest `app/Http/Requests/HealthSafety/UpdateResidualRiskRequest.php:18`; `residual_likelihood`, `residual_consequence`, `risk_acceptable`, `review_note`.

## Source-applicable states and transitions

- **information presented** is applicable only to `index` / `ROUTE-1214` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:41`; it is not runtime-observed.
- **created/recorded** is applicable only to `store` / `ROUTE-1215` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:121`; it is not runtime-observed.
- **information presented** is applicable only to `show` / `ROUTE-1216` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:108`; it is not runtime-observed.
- **updated/revised** is applicable only to `update` / `ROUTE-1217` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:130`; it is not runtime-observed.
- **created/recorded** is applicable only to `uploadAttachment` / `ROUTE-1220` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:234`; it is not runtime-observed.
- **cancelled/removed/archived** is applicable only to `destroyAttachment` / `ROUTE-1221` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:275`; it is not runtime-observed.
- **file/report delivered** is applicable only to `downloadAttachment` / `ROUTE-1222` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:262`; it is not runtime-observed.
- **updated/revised** is applicable only to `updateResidual` / `ROUTE-1223` at `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:193`; it is not runtime-observed.
- Presentation-state applicability must be determined from exact signals in `resources/js/pages/health-safety/risk-assessments/index.tsx`; page presence alone is not loading/empty/error proof.

## Validation and source-visible errors

- `ROUTE-1215` / `store`: FormRequest `app/Http/Requests/HealthSafety/StoreHsRiskAssessmentRequest.php:23`; fields `title`, `risk_description`, `attach_type`, `attach_id`, `likelihood`, `consequence`, `existing_controls`, `additional_controls`, `residual_likelihood`, `residual_consequence`, `risk_acceptable`, `review_frequency_days`, `review_due_at`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:126 `->with('success', 'Risk assessment created as a draft.')`.
- `ROUTE-1217` / `update`: FormRequest `app/Http/Requests/HealthSafety/UpdateHsRiskAssessmentRequest.php:line unresolved`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:164 `return back()->with('success', 'Draft updated.');`.
- `ROUTE-1220` / `uploadAttachment`: fields `file`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:259 `return back()->with('success', 'Evidence uploaded.');`.
- `ROUTE-1221` / `destroyAttachment`: success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:285 `return back()->with('success', 'Evidence removed.');`.
- `ROUTE-1223` / `updateResidual`: FormRequest `app/Http/Requests/HealthSafety/UpdateResidualRiskRequest.php:18`; fields `residual_likelihood`, `residual_consequence`, `risk_acceptable`, `review_note`; success app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:207 `return back()->with('success', 'Residual risk recorded.');`.

## Failure and recovery paths

- No source-defined failure/recovery branch was extracted from the assigned methods; no retry, correction, denial rendering, offline, concurrency, or queue recovery is claimed.

## Completion evidence and next handoff

- Completion evidence is limited to exact extracted source signals: persistence calls app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:123 `$assessment = $this->service->create($this->mapAssessable($request->validated()));`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:142 `$assessment->update([`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:247 `$assessment->attachments()->create([`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:281 `Storage::disk($disk)->delete($attachment->path);`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:283 `$attachment->delete();`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:204 `$assessment->update(['last_review_note' => $request->input('review_note')]);`; responses app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:93 `return Inertia::render('health-safety/risk-assessments/index', [`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:125 `return back()`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:112 `return response()->json([`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:133 `return back()->with('error', 'Only draft assessments can be edited.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:164 `return back()->with('success', 'Draft updated.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:259 `return back()->with('success', 'Evidence uploaded.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:285 `return back()->with('success', 'Evidence removed.');`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:267 `return $this->streamPrivateAttachment(`; app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:207 `return back()->with('success', 'Residual risk recorded.');`. Runtime persistence and user comprehension were not executed.
- No source-defined downstream handoff/event/job/notification was extracted from the assigned methods; no next owner or worklist is invented.

## Route/action evidence

- `GET|HEAD health-safety/risk-assessments` — `health-safety.risk-assessments.index` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@index` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:41` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/risk-assessments` — `health-safety.risk-assessments.store` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@store` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:121` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/risk-assessments/{assessment}` — `health-safety.risk-assessments.show` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@show` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:108` — middleware `web, auth, permission:hazards.view`
- `PUT health-safety/risk-assessments/{assessment}` — `health-safety.risk-assessments.update` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@update` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:130` — middleware `web, auth, permission:hazards.manage`
- `POST health-safety/risk-assessments/{assessment}/attachments` — `health-safety.risk-assessments.attachments.store` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@uploadAttachment` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:234` — middleware `web, auth, permission:hazards.manage`
- `DELETE health-safety/risk-assessments/{assessment}/attachments/{attachment}` — `health-safety.risk-assessments.attachments.destroy` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@destroyAttachment` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:275` — middleware `web, auth, permission:hazards.manage`
- `GET|HEAD health-safety/risk-assessments/{assessment}/attachments/{attachment}/download` — `health-safety.risk-assessments.attachments.download` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@downloadAttachment` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:262` — middleware `web, auth, permission:hazards.view`
- `POST health-safety/risk-assessments/{assessment}/residual` — `health-safety.risk-assessments.residual` — `App\Http\Controllers\HealthSafety\HsRiskAssessmentController@updateResidual` — `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php:193` — middleware `web, auth, permission:hazards.manage`

## Source anchors and limits

- Backend anchor: `app/Http/Controllers/HealthSafety/HsRiskAssessmentController.php`.
- Exact render/action page relationships: `resources/js/pages/health-safety/risk-assessments/index.tsx`.
- Capability basis: Separated from sibling routes by user goal and completion boundary, with the listed route permission boundary; controller ownership and URI prefix alone were not treated as capability identity.
- No task success, accessibility, recovery, notification delivery, handoff ownership, or comprehension is claimed. Independent review remains blocked.
