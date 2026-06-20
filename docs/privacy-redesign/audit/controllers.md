The `NotifyUs` grep matches are all ControlRoom/HR `notify_us`-style false positives (no OPC/NotifyUs integration). The "ico_notifications" key in PrivacyReportController and GDPR Article references in the migration enum are confirmed non-NZ framing. I have everything. Here is the complete audit.

---

# PRIVACY CONTROLLERS — REQUEST CONTRACT AUDIT

## Cross-cutting facts (apply to all controllers)

- **Auth gate:** all use `abort_unless($request->user()?->canDo($permission), 403)`. `canDo` is on `User` (`app/Models/User.php:333`). Routes ALSO apply `permission:<key>` middleware (`routes/privacy.php`), so gating is double-enforced. `User::staff()` scope is at `app/Models/User.php:127`.
- **No Service classes anywhere.** Every controller is inline logic. Glob for `*{Privacy,Breach,DataSubject,LegalHold,Anonymiz,Retention}*Service*.php` → **no files**. (Only the deletion controller touches a domain concept, and it inlines anonymisation.)
- **No Privacy notifications.** Glob `app/Notifications/**/*rivacy*` → none. No mail/database notification is dispatched by any lifecycle verb. `notifyOPC` / `notifySubjects` only stamp timestamps — they do NOT send anything.
- **No OPC / NotifyUs integration.** Grep `NotifyUs|notify_us|opc|privacy.org.nz` in `app/` → only ControlRoom/HR false positives. Nothing posts to the OPC NotifyUs portal.
- **No working-days / NZ-public-holiday logic used in Privacy.** Grep `workingDays|addWorkingDays|businessDays|holiday` → 59 files, **all HR/payroll/finance**. A reusable holiday helper EXISTS — `App\Domain\Hr\Services\PublicHolidayCalendar` (`holidayFor()`, `isPublicHoliday(date, tenantId, region)`) — but it only answers "is this date a holiday", there is **no `addWorkingDays` helper**. The DSR due date is a naive `now()->addDays(30)` (calendar days, GDPR-flavoured) set in the **model**, not the +20-working-days NZ rule.
- **No file uploads anywhere.** Grep `hasFile|->file(|UploadedFile|storeAs|Storage::disk` across all 8 controllers → the ONLY hit is `DataSubjectRequestController:366` (`Storage::disk('local')->put(...)` writing the export JSON). No `store()` method accepts attachments. **No privacy attachments table exists** (grep `*attachment*` in migrations → none; the only related child table is `data_exports`, which is unused by code).
- **`AuditableChanges` trait** is on `DataSubjectRequest` and `DataBreachLog` only (not LegalHold/Policy/PIA/AnonymizationLog) — provides model-change audit, not an explicit governance audit log call.

---

## 1. `DataSubjectRequestController` (Privacy Requests / DSR)

Model `App\Models\DataSubjectRequest` — `received_at`/`due_date` are stamped in **model `boot()::creating`** (`app/Models/DataSubjectRequest.php:72-87`), NOT in the controller.

### index — `GET /privacy/requests` · `privacy.requests.index` · `permission:privacy.viewRequests`
- Perm check: `privacy.viewRequests`.
- Query `DataSubjectRequest::with(['assignedTo','client','user'])`; filters: `q` (LIKE reference_number / subject_name / subject_email), `request_type` (=), `status` (=), `overdue` (`==='1'` → `scopeOverdue`). `orderByDesc('created_at')`, `paginate(20)->withQueryString()`.
- **Renders `privacy/requests`** props: `requests` (paginator), `filters` {q,request_type,status,overdue}, `stats` {open, overdue, completed_30_days, pending_verification(=`received`+`identity_verification`)}.

### create — `GET /privacy/requests/create` · `privacy.requests.create` · `permission:privacy.processRequests`
- Renders `privacy/requests/create` props: `staff` (User::staff → id,name).

### store — `POST /privacy/requests` · `privacy.requests.store` · `permission:privacy.processRequests`
- **Validation (FE=BE contract):**
  | field | rule |
  |---|---|
  | `request_type` | `required|in:access,rectification,erasure,restriction,portability,objection,automated_decision` |
  | `subject_name` | `required|string|max:255` |
  | `subject_email` | `required|email|max:255` |
  | `request_details` | `nullable|string` |
  | `specific_data_requested` | `nullable|array` |
  | `assigned_to_user_id` | `nullable|exists:users,id` |
- Sets `created_by = auth()->id()`, **`status = 'identity_verification'`** (NOT the migration default `received`).
- **`due_date`:** NOT set in controller. Set in model boot → **`now()->addDays(30)`** (calendar days). `received_at` → `now()` (model boot). `reference_number` → `DSR-{year}-{0000}` (model boot).
- ⚠️ **No `client_id` / `user_id` accepted** — store can NOT link a DSR to a client or user. Yet `export` and the model rely on that link. Modal/wizard has no way to attach a subject record.
- ⚠️ **Schema mismatch:** migration `request_details` is `text()` NOT NULL (line 71); validation says `nullable`. A null/omitted value will hit a DB not-null violation on MySQL strict.
- Returns: `redirect()->route('privacy.requests.show', $dsr)->with('success', 'Privacy request created with reference: '.$dsr->reference_number)`.

### show — `GET /privacy/requests/{dsRequest}` · `privacy.requests.show` · `permission:privacy.viewRequests`
- Loads `client,user,verifiedBy,assignedTo,completedBy`.
- Renders `privacy/requests/show` props: `request` (the DSR), `staff`.

### update — `PUT /privacy/requests/{dsRequest}` · `privacy.requests.update` · `permission:privacy.processRequests`
- Validation: `status` `sometimes|in:received,under_review,identity_verification,in_progress,completed,rejected,withdrawn`; `assigned_to_user_id` `nullable|exists:users,id`; `completion_notes` `nullable|string`.
- Sets `updated_by`; if `assigned_to_user_id` present and no prior `assigned_at` → stamps `assigned_at = now()`.
- Returns `back()->with('success', …)`.

### verifyIdentity — `POST /privacy/requests/{dsRequest}/verify-identity` · `privacy.requests.verify-identity` · `permission:privacy.processRequests`
- Validation: `verification_method` `required|string|max:255`.
- Sets: `identity_verified='verified'`, `identity_verified_at=now()`, `verified_by_user_id=auth id`, `verification_method`, **`status='in_progress'`**, `updated_by`.
- Returns `back()->with('success')`.

### extend — `POST /privacy/requests/{dsRequest}/extend` · `privacy.requests.extend` · `permission:privacy.processRequests`
- Validation: `extension_reason` `required|string`; `extended_due_date` `required|date|after:today`.
- Sets: `extension_requested=true`, `extension_reason`, `extended_due_date`, `updated_by`. (Does NOT change status.) ⚠️ extension date is operator-supplied, no working-day computation offered.
- Returns `back()->with('success')`.

### complete — `POST /privacy/requests/{dsRequest}/complete` · `privacy.requests.complete` · `permission:privacy.processRequests`
- Validation: `completion_notes` `nullable|string`.
- Sets: `status='completed'`, `completed_at=now()`, `completed_by_user_id`, `completion_notes`, `updated_by`.
- Returns `back()->with('success')`.

### refuse — `POST /privacy/requests/{dsRequest}/refuse` · `privacy.requests.refuse` · `permission:privacy.processRequests`
- Validation: `rejection_reason` `required|string`; `rejection_legal_basis` `required|string`.
- Sets: **`status='rejected'`** (note: status enum value is `rejected`, button label is "refuse"), `rejection_reason`, `rejection_legal_basis`, `updated_by`.
- Returns `back()->with('success')`.

### export — `GET /privacy/requests/{dsRequest}/export` · `privacy.requests.export` · `permission:privacy.viewRequests`
- **CONFIRMED: writes JSON to local disk and flashes — it is NOT a download.** No validation. Relevant lines (`DataSubjectRequestController.php:364-373`):
  ```php
  $filename = 'privacy-request-'.$dsRequest->reference_number.'-'.now()->format('Y-m-d').'.json';
  $path = 'private/privacy-request-exports/'.$filename;
  Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT));
  $dsRequest->update(['export_path' => $path, 'export_generated_at' => now()]);
  return back()->with('success', 'Data export generated successfully.');
  ```
- Assembles `$data`: `export_metadata`; then branches — if `client_id` & `client`: full `personal_information` (incl. `nhi_number`, address, ethnicity), `support_plan`, `notes`/`assessments`/`incidents`/`medications` (titles+dates only), `consent_records` (`ClientConsent`), `respite_records` (`respiteExportRecords()` → referrals/booking_requests/bookings/stays/handovers/communications). Elif `user_id` & `user`: name+email only. Else: subject_name/email + "no linked record" note. Plus `request_details` block (type, details, specific_data_requested, status, received_at, due_date).
- ⚠️ Returns `back()` (Inertia redirect) — the GET route name implies download but it does a redirect+flash. There is **no route to download/stream the written file**; `export_path` is stored but never served. `export_accessed_at` is declared but never written.
- Uses `RespiteReferral/RespiteBookingRequest/RespiteBooking/RespiteStay`, `ClientConsent` directly (inline).

---

## 2. `DataBreachController`

Model `App\Models\DataBreachLog`. Perm for ALL methods: **`privacy.reportBreaches`** (inline `abort_unless`).

### index — `GET /privacy/breaches` · `privacy.breaches.index`
- `with(['discoveredBy','creator'])`; filters `q` (LIKE breach_reference / nature_of_breach), `status` (=), `requires_notification` (`==='1'` → `requires_authority_notification=true AND authority_notified_at IS NULL`). `orderByDesc('discovered_at')`, paginate 20.
- Renders `privacy/breaches` props: `breaches`, `filters`, `stats` {total, open(status≠resolved), requiring_notification, resolved_30_days}.

### create — `GET /privacy/breaches/create` · `privacy.breaches.create`
- Renders `privacy/breaches/create` props: `staff`.

### store — `POST /privacy/breaches` · `privacy.breaches.store`
- **Validation:**
  | field | rule |
  |---|---|
  | `nature_of_breach` | `required|string` |
  | `discovered_at` | `required|date` |
  | `affected_data_categories` | `nullable|array` |
  | `approximate_individuals_affected` | `nullable|integer|min:0` |
  | `likely_consequences` | `nullable|string` |
  | `measures_taken` | `nullable|string` |
  | `requires_authority_notification` | `boolean` |
  | `requires_subject_notification` | `boolean` |
- Sets `breach_reference='BR-{year}-{0000}'` (count+1, in controller), `discovered_by_user_id`, `created_by`, **`status='discovered'`**.
- ⚠️ **Schema mismatch:** migration `likely_consequences` and `measures_taken` are `text()` NOT NULL (lines 229-230); validation marks them `nullable`. Omitting them → DB not-null violation.
- ⚠️ `breach_type` and `severity` columns exist (added 2026-04-23 migration) but store does NOT accept them.
- Returns `redirect()->route('privacy.breaches.show', $breach)->with('success', …reference)`.

### show — `GET /privacy/breaches/{breach}` · `privacy.breaches.show`
- Loads `discoveredBy,creator`. Renders `privacy/breaches/show` props: `breach`.

### update — `PUT /privacy/breaches/{breach}` · `privacy.breaches.update`
- Validation: `nature_of_breach` `sometimes|string`; the four detail fields as in store; both `requires_*` booleans; `status` `sometimes|in:discovered,under_investigation,contained,notified,resolved`.
- Returns `back()->with('success')`.

### notifyOPC — `POST /privacy/breaches/{breach}/notify-opc` · `privacy.breaches.notify-opc`
- Validation: `authority_reference` `nullable|string|max:255`.
- Sets `authority_notified_at=now()`, `authority_reference`; if status≠resolved → `status='notified'`. **No notification sent; no NotifyUs call.** Pure timestamp.
- Returns `back()->with('success', 'OPC notification recorded.')`.

### notifySubjects — `POST /privacy/breaches/{breach}/notify-subjects` · `privacy.breaches.notify-subjects`
- Validation: `notification_method` `required|string|max:255`.
- Sets `subjects_notified_at=now()`, `notification_method`; if status≠resolved → `status='notified'`. No actual notification dispatched.
- Returns `back()->with('success')`.

### resolve — `POST /privacy/breaches/{breach}/resolve` · `privacy.breaches.resolve`
- Validation: `resolution_notes` `required|string`.
- Sets `status='resolved'`, `resolved_at=now()`, `resolution_notes`.
- Returns `back()->with('success')`.

> Note: there is **no `severity`/`breach_type` capture path, no "assessable harm" decision, no countdown**. NZ Privacy Act framing absent in payload (the only NZ-ish text is method docblocks).

---

## 3. `LegalHoldController`

Model `App\Models\LegalHold`. Perm ALL: **`privacy.manageLegalHolds`**.

### index — `GET /privacy/legal-holds` · `privacy.legal-holds.index`
- `with(['imposedBy','releasedBy'])`; filters `q` (LIKE hold_reference / reason), `status` (=), `hold_type` (=). `orderByDesc('imposed_at')`, paginate 20.
- Renders `privacy/legal-holds` props: `holds`, `filters`, `stats` {total, active(`scopeActive`)}.

### create — `GET /privacy/legal-holds/create` · `privacy.legal-holds.create`
- Renders `privacy/legal-holds/create` (**no props** — note: holdable target/related records have no picker data supplied).

### store — `POST /privacy/legal-holds` · `privacy.legal-holds.store`
- **Validation:**
  | field | rule |
  |---|---|
  | `hold_type` | `required|in:litigation,investigation,regulatory,audit,other` |
  | `reason` | `required|string` |
  | `holdable_type` | `nullable|required_with:holdable_id|string` |
  | `holdable_id` | `nullable|required_with:holdable_type|integer` |
  | `related_records` | `nullable|array` |
  | `legal_authority` | `nullable|string` |
  | `review_date` | `nullable|date` |
- Sets `hold_reference='LH-{year}-{0000}'`, `status='active'`, `imposed_at=now()`, `imposed_by_user_id`.
- ⚠️ `holdable_type` is free-string (no allow-list / morph-map validation) — IDOR-ish surface; modal must constrain.
- Returns `redirect()->route('privacy.legal-holds.index')->with('success', …reference)`.

### edit — `GET /privacy/legal-holds/{hold}/edit` · `privacy.legal-holds.edit`
- Renders `privacy/legal-holds/edit` props: `hold`.

### update — `PUT /privacy/legal-holds/{hold}` · `privacy.legal-holds.update`
- Validation: `reason` `sometimes|string`; `related_records` `nullable|array`; `legal_authority` `nullable|string`; `review_date` `nullable|date`. (Cannot change hold_type/holdable.)
- Returns `back()->with('success')`.

### release — `POST /privacy/legal-holds/{hold}/release` · `privacy.legal-holds.release`
- Validation: `release_reason` `required|string`.
- Sets `status='released'`, `released_at=now()`, `released_by_user_id`, `release_reason`.
- Returns `back()->with('success')`.

> No `show` method/route (detail is the `edit` page). For a modal-first rebuild, detail data currently only comes via `edit`.

---

## 4. `DataRetentionPolicyController`

Model `App\Models\DataRetentionPolicy`. Perm ALL (private `authorizePermission`): **`privacy.manageRetention`**.

### index — `GET /privacy/retention` · `privacy.retention.index`
- `with(['creator','updater'])`; filters `q` (LIKE policy_name / model_type), `active` (`==='1'`). `orderBy('model_type')`, paginate 20.
- Renders `privacy/retention` props: `policies`, `filters`, `stats` {total, active}.

### create — `GET /privacy/retention/create` · `privacy.retention.create`
- Renders `privacy/retention/create` (no props — `model_type` is free-typed by user).

### store — `POST /privacy/retention` · `privacy.retention.store`
- **Validation:**
  | field | rule |
  |---|---|
  | `model_type` | `required|string|max:255` |
  | `policy_name` | `required|string|max:255` |
  | `description` | `nullable|string` |
  | `retention_period_years` | `required|integer|min:1|max:100` |
  | `archive_after_years` | `nullable|integer|min:1|max:100` |
  | `hard_delete_after_years` | `nullable|integer|min:1|max:100` |
  | `retention_conditions` | `nullable|array` |
  | `applies_to_soft_deleted` | `boolean` |
  | `legal_hold_exemption` | `boolean` |
  | `active_case_exemption` | `boolean` |
  | `legal_basis` | `nullable|string` |
  | `business_justification` | `nullable|string` |
  | `active` | `boolean` |
- Sets `created_by`. Returns `redirect()->route('privacy.retention.index')->with('success')`.
- ⚠️ `model_type` is a free string used later as a class name in `deletion.execute` (`class_exists($modelClass)`) — no class allow-list.

### edit — `GET /privacy/retention/{policy}/edit` · `privacy.retention.edit`
- Renders `privacy/retention/edit` props: `policy`.

### update — `PUT /privacy/retention/{policy}` · `privacy.retention.update`
- Validation: same fields as store but `policy_name`/`retention_period_years` are `sometimes`; `model_type` NOT updatable. Sets `updated_by`.
- Returns `redirect()->route('privacy.retention.index')->with('success')`.

### review — `GET /privacy/retention/review` · `privacy.retention.review`
- Renders `privacy/retention/review` props: `policies` (all `active=true`). Read-only preview page; the actual destructive run is `deletion.execute`.

> No `show` route (detail = `edit`).

---

## 5. `DPIAController` (PIA / Privacy Impact Assessment)

Model `App\Models\PrivacyImpactAssessment`. Perm ALL (private `authorizePermission`): **`privacy.conductDPIA`**.

### index — `GET /privacy/pia` · `privacy.dpia.index`
- `with(['assessor','approvedBy'])`; filters `q` (LIKE assessment_name / project_or_process), `outcome` (=), `risk_level` (→ `overall_risk_level` =). `orderByDesc('assessment_date')`, paginate 20.
- Renders `privacy/dpia` props: `dpias`, `filters`, `stats` {total, pending_review(`outcome IS NULL`), high_risk(overall in high/very_high), approved}.

### create — `GET /privacy/pia/create` · `privacy.dpia.create`
- Renders `privacy/dpia/create` props: `staff`.

### store — `POST /privacy/pia` · `privacy.dpia.store`
- **Validation:**
  | field | rule |
  |---|---|
  | `assessment_name` | `required|string|max:255` |
  | `project_or_process` | `required|string|max:255` |
  | `description` | `nullable|string` |
  | `assessment_type` | `required|in:new_project,process_change,system_upgrade,periodic_review` |
  | `personal_data_types` | `nullable|array` |
  | `data_subjects` | `nullable|array` |
  | `processing_purpose` | `required|string` |
  | `legal_basis` | `required|string` |
  | `identified_risks` | `nullable|array` |
  | `overall_risk_level` | `required|in:low,medium,high,very_high` |
  | `mitigation_measures` | `nullable|array` |
  | `residual_risk_level` | `nullable|in:low,medium,high,very_high` |
  | `review_date` | `nullable|date` |
- Sets `assessor_id`, `assessment_date=now()`. `outcome` left null (= pending review).
- ⚠️ **Schema mismatch:** migration `description` (text), `residual_risk_level` (enum, NOT NULL line 283) are required at DB but validation has `description` nullable and `residual_risk_level` nullable. Omitting `residual_risk_level` → DB not-null violation.
- Returns `redirect()->route('privacy.dpia.show', $dpia)->with('success')`.

### show — `GET /privacy/pia/{dpia}` · `privacy.dpia.show`
- Loads `assessor,approvedBy`. Renders `privacy/dpia/show` props: `dpia`.

### edit — `GET /privacy/pia/{dpia}/edit` · `privacy.dpia.edit`
- Renders `privacy/dpia/edit` props: `dpia`, `staff`.

### update — `PUT /privacy/pia/{dpia}` · `privacy.dpia.update`
- Validation: same field set, all `sometimes`/`nullable` (name/project/purpose/legal_basis/overall_risk_level `sometimes`; arrays + review_date `nullable`). No `assessment_type` update.
- Returns `back()->with('success')`.

### approve — `POST /privacy/pia/{dpia}/approve` · `privacy.dpia.approve`
- **No validation.** Sets `outcome='approved'`, `approved_by_user_id`, `approved_at=now()`.
- Returns `back()->with('success')`.

### review — `POST /privacy/pia/{dpia}/review` · `privacy.dpia.review`
- Validation: `review_notes` `required|string`.
- ⚠️ Sets only `outcome='requires_dpo_review'`. **`review_notes` is validated but discarded** (no column written — there is no review_notes field). Modal sends it, BE drops it. Enum value `requires_dpo_review` is GDPR-flavoured ("DPO").
- Returns `back()->with('success')`.

---

## 6. `DataDeletionLogController`

Models `App\Models\AnonymizationLog`, `DataRetentionPolicy`, `Client`. Perm ALL (private `authorizePermission`): **`privacy.manageRetention`**.

### index — `GET /privacy/deletion-logs` · `privacy.deletion-logs.index`
- `AnonymizationLog::with('anonymizedBy')`; filters `q` (LIKE reason / model_type), `model_type` (=). `orderByDesc('anonymized_at')`, then **`->get()`** (NOT paginated) and `->map()` to a flat shape: `{id, model_type: class_basename, model_id, reason, fields_anonymized, deleted_at: anonymized_at ISO, deleted_by_name, policy_name: reason}`.
- Renders `privacy/deletion-logs` props: `logs` (array, not paginator), `filters`.

### execute — `POST /privacy/deletion/execute` · `privacy.deletion.execute` · `permission:privacy.manageRetention`
- **CONFIRMED guarded** — validation: `policy_id` `required|exists:data_retention_policies,id`; `confirm` `required|accepted`.
- Further guards (each returns `back()->with('error'|'info')`): policy must be `active`; `class_exists($modelClass)`; `retention_period_years` must be set; record set non-empty.
- **What it does:** `$cutoffDate = now()->subYears(retention_period_years)`; selects `$modelClass::where('created_at','<',$cutoff)`; applies `retention_conditions` (`where($field,$value)` per pair); if `applies_to_soft_deleted` & model supports → `withTrashed()`; if `legal_hold_exemption` → excludes rows with an `active` row in `legal_holds` matching `holdable_type=$modelClass` + `holdable_id=id` (`whereNotExists`).
- In a `DB::transaction`, per record: if soft-deletes & not trashed → `$record->delete()` (soft) + count; then **anonymises personal fields** from a hardcoded map `getPersonalDataFields()` — strategies `redact`→`'REDACTED'`, `clear`→`null` — via `forceFill(...)->saveQuietly()`; writes `AnonymizationLog` (`reason='retention_period_expired - Policy: …'`, fields/methods, `reversible=false`). Then `policy->update(last_applied_at, updated_by)`.
- **`getPersonalDataFields()` map is hardcoded for only 3 classes:** `Client` (first_name/last_name redact; preferred_name/email/phone/nhi_number/address_* /life_story/interests_hobbies/strengths_abilities/profile_photo_path clear), `ClientNote` (subject/body redact), `ClientDocument` (title redact, description clear). **Any other `model_type` anonymises nothing** (only soft-delete fires, or no-op) — silent partial behaviour.
- ⚠️ It does **soft-delete + field anonymisation**, never a hard delete (despite `hard_delete_after_years` existing). `active_case_exemption` flag is never honoured in the query.
- Returns `back()->with('success', 'Data deletion executed successfully. {n} soft-deleted, {n} anonymized.')` (or `'info'`/`'error'`).

---

## 7. `PrivacyReportController`

No perm check inside methods; gated only by route middleware **`privacy.viewRequests`**. No models written; read-only aggregates.

### compliance — `GET /privacy/reports` AND `GET /privacy/reports/compliance` · `privacy.reports.index` / `privacy.reports.compliance`
- Reads `period` query (`month|quarter|year`, default year) → `$startDate`.
- Renders `privacy/reports/compliance` props: `period`; `dsrStats` {total, completed, average_response_days (`calculateAverageResponseDays` = mean `received_at→completed_at` `diffInDays`, **calendar days**), by_type (groupBy request_type)}; `breachStats` {total, resolved, **`ico_notifications`** (count `authority_notified_at` in range — ⚠️ prop key is GDPR/ICO-named, not OPC)}; `dpiaStats` {total, approved, high_risk}; `retentionStats` {total_policies, active_policies}; `legalHoldStats` {total, active}.

### export — `GET /privacy/reports/export` · `privacy.reports.export`
- **STUB.** Body: `return back()->with('info', 'Export functionality coming soon.');` (literal `// TODO: Implement export functionality`). Violates the "no coming-soon stubs" rule — should be removed or built.

---

## 8. `PrivacyDashboardController` — THE REBUILD TARGET

### index — `GET /privacy/dashboard` · `privacy.dashboard` · `permission:privacy.viewRequests`
- Perm: inline `privacy.viewRequests`.
- Renders **`privacy/dashboard`** with props (current shape — minimal, NOT command-centre):
  - `dsrStats` {total, pending(received/under_review/identity_verification/in_progress), overdue(`due_date<now` AND status not completed/rejected/withdrawn — ⚠️ ignores `extended_due_date`, unlike `scopeOverdue`), completed_this_month}.
  - `recentRequests` — 5 latest by `received_at`, `with(['client','user','assignedTo'])`.
  - `breachStats` {total, open(status≠resolved), requiring_notification}.
  - `activeHolds` (int).
  - `retentionStats` {total_policies, active_policies}.
  - `dpiaStats` {total, pending_review, high_risk}.
- **No `hero` object, no `tabCounts`, no `worklist`, no `detail`, no `can` map.** No service. This is the method the rebuild must extend to feed the hero + tabs + worklists + right-click rows + modal-detail + permission gates.

---

## ENDPOINT CONTRACT TABLE

One row per store/lifecycle endpoint the modal/wizard must drive. "Returns" = redirect target + flash. All flashes are `success` unless noted.

| Route name | Verb / path | Permission | Body fields the modal MUST send | What it sets / returns |
|---|---|---|---|---|
| `privacy.requests.store` | POST `/privacy/requests` | processRequests | `request_type`*(enum7), `subject_name`*, `subject_email`*(email), `request_details`?, `specific_data_requested`?(array), `assigned_to_user_id`? | status→`identity_verification`; created_by; ref+received_at+due_date(+30d) via model boot → redirect `requests.show`, flash ref |
| `privacy.requests.update` | PUT `/privacy/requests/{dsRequest}` | processRequests | `status`?(enum7), `assigned_to_user_id`?, `completion_notes`? | updated_by; assigned_at if first assign → `back()` |
| `privacy.requests.verify-identity` | POST `/{dsRequest}/verify-identity` | processRequests | `verification_method`* | identity_verified=verified, identity_verified_at, verified_by, status→`in_progress` → `back()` |
| `privacy.requests.extend` | POST `/{dsRequest}/extend` | processRequests | `extension_reason`*, `extended_due_date`*(date>today) | extension_requested=true, extended_due_date → `back()` |
| `privacy.requests.complete` | POST `/{dsRequest}/complete` | processRequests | `completion_notes`? | status→`completed`, completed_at, completed_by → `back()` |
| `privacy.requests.refuse` | POST `/{dsRequest}/refuse` | processRequests | `rejection_reason`*, `rejection_legal_basis`* | status→`rejected` → `back()` |
| `privacy.requests.export` | GET `/{dsRequest}/export` | viewRequests | none | writes JSON to `private/privacy-request-exports/…`, stamps export_path/export_generated_at → `back()` flash (NOT a download) |
| `privacy.breaches.store` | POST `/privacy/breaches` | reportBreaches | `nature_of_breach`*, `discovered_at`*(date), `affected_data_categories`?(array), `approximate_individuals_affected`?(int≥0), `likely_consequences`?, `measures_taken`?, `requires_authority_notification`(bool), `requires_subject_notification`(bool) | ref=`BR-…`, discovered_by, created_by, status→`discovered` → redirect `breaches.show` |
| `privacy.breaches.update` | PUT `/privacy/breaches/{breach}` | reportBreaches | `nature_of_breach`?, 4 detail fields?, 2 bools, `status`?(enum5) | update → `back()` |
| `privacy.breaches.notify-opc` | POST `/{breach}/notify-opc` | reportBreaches | `authority_reference`? | authority_notified_at=now, status→`notified` (if not resolved); **no send** → `back()` |
| `privacy.breaches.notify-subjects` | POST `/{breach}/notify-subjects` | reportBreaches | `notification_method`* | subjects_notified_at=now, status→`notified`; **no send** → `back()` |
| `privacy.breaches.resolve` | POST `/{breach}/resolve` | reportBreaches | `resolution_notes`* | status→`resolved`, resolved_at → `back()` |
| `privacy.legal-holds.store` | POST `/privacy/legal-holds` | manageLegalHolds | `hold_type`*(enum5), `reason`*, `holdable_type`?+`holdable_id`?(paired), `related_records`?(array), `legal_authority`?, `review_date`? | ref=`LH-…`, status=active, imposed_at, imposed_by → redirect `legal-holds.index` |
| `privacy.legal-holds.update` | PUT `/privacy/legal-holds/{hold}` | manageLegalHolds | `reason`?, `related_records`?, `legal_authority`?, `review_date`? | update → `back()` |
| `privacy.legal-holds.release` | POST `/{hold}/release` | manageLegalHolds | `release_reason`* | status→released, released_at, released_by → `back()` |
| `privacy.retention.store` | POST `/privacy/retention` | manageRetention | `model_type`*, `policy_name`*, `retention_period_years`*(1-100), `description`?, `archive_after_years`?, `hard_delete_after_years`?, `retention_conditions`?(array), `applies_to_soft_deleted`(bool), `legal_hold_exemption`(bool), `active_case_exemption`(bool), `legal_basis`?, `business_justification`?, `active`(bool) | created_by → redirect `retention.index` |
| `privacy.retention.update` | PUT `/privacy/retention/{policy}` | manageRetention | as store minus `model_type`; name/years `sometimes` | updated_by → redirect `retention.index` |
| `privacy.deletion.execute` | POST `/privacy/deletion/execute` | manageRetention | `policy_id`*(exists), `confirm`*(accepted=true) | soft-deletes + anonymises matching records, writes AnonymizationLog, stamps policy.last_applied_at → `back()` success/info/error |
| `privacy.dpia.store` | POST `/privacy/pia` | conductDPIA | `assessment_name`*, `project_or_process`*, `assessment_type`*(enum4), `processing_purpose`*, `legal_basis`*, `overall_risk_level`*(enum4), `description`?, `personal_data_types`?(array), `data_subjects`?(array), `identified_risks`?(array), `mitigation_measures`?(array), `residual_risk_level`?(enum4), `review_date`? | assessor_id, assessment_date=now → redirect `dpia.show` |
| `privacy.dpia.update` | PUT `/privacy/pia/{dpia}` | conductDPIA | as store, all `sometimes`/`nullable`, no `assessment_type` | update → `back()` |
| `privacy.dpia.approve` | POST `/{dpia}/approve` | conductDPIA | none | outcome=approved, approved_by, approved_at → `back()` |
| `privacy.dpia.review` | POST `/{dpia}/review` | conductDPIA | `review_notes`* (⚠️ **discarded — no column**) | outcome→`requires_dpo_review` → `back()` |

\* = required. `?` = nullable/optional.

---

## BACKEND GAPS (methods needing extension for the rebuild)

**Dashboard (primary):**
1. `PrivacyDashboardController::index` must be rebuilt to emit the H&S command-centre shape: a `hero` block (leading/lagging KPI clusters with deltas, e.g. open DSRs, DSRs due ≤5 working days, overdue DSRs, breaches awaiting OPC notification, active legal holds, high-risk PIAs), `tabCounts` per worklist tab, `worklist`/queue rows (DSRs/breaches/holds/PIAs needing action), `detail` payload for the modal, and a `can` permission map (viewRequests/processRequests/reportBreaches/manageRetention/manageLegalHolds/conductDPIA). Currently emits 6 flat stat objects + `recentRequests` only. Consider extracting a `PrivacyKpiService` (none exists) to mirror `HsKpiService`.
2. Dashboard `overdue` calc ignores `extended_due_date` — should reuse `DataSubjectRequest::scopeOverdue` for parity with the list page.

**NZ statutory correctness (cross-cutting):**
3. **+20-working-days rule not implemented.** DSR `due_date` is `now()->addDays(30)` (calendar, GDPR-style) in `DataSubjectRequest::boot()`. Need an `addWorkingDays(20)` helper honouring NZ public holidays. `PublicHolidayCalendar` (`app/Domain/Hr/Services/PublicHolidayCalendar.php`) provides `isPublicHoliday()` but no working-day adder — wrap it (or add `addWorkingDays`) and call it in store/model. `extend` and `calculateAverageResponseDays` also use calendar days.
4. **GDPR framing to purge** (FE+BE): request_type enum includes `rectification/restriction/portability/objection/automated_decision` with "Article 16-22" comments (migration lines 54-62); PIA outcome enum `requires_dpo_review` + the `review` verb's "DPO" language; report prop key `ico_notifications` (should be `opc_notifications`); the migration comment "30 days … Privacy Act 2020 requirement" (should be 20 working days). Decide which request types are in-scope for NZ IPP 6/7 (access + correction) and gate/relabel the rest.

**Store contracts — schema vs validation mismatches (will 500 on strict MySQL):**
5. `requests.store`: migration `request_details` is NOT NULL but validation `nullable` → make required, or migrate column nullable. Also **store cannot link `client_id`/`user_id`** — add optional client/user picker + validation so `export` actually has a subject; without it every DSR exports the "no linked record" stub.
6. `breaches.store`: `likely_consequences` + `measures_taken` are NOT NULL in DB but `nullable` in validation → align. Also accept `breach_type` + `severity` (columns exist, never captured) — needed for any hero "severity" badge.
7. `dpia.store`: `description` + `residual_risk_level` NOT NULL in DB but optional in validation → align.
8. `dpia.review`: persist `review_notes` (no column today) — add a `review_notes`/governance-note column or drop the field from the modal.

**Attachments / evidence (H&S gold standard expects evidence chips):**
9. **No attachments anywhere.** No privacy attachments table, no `store()` accepts files. To match H&S evidence pattern, add a polymorphic privacy-attachment table + upload handling on DSR/breach/PIA/legal-hold (e.g. `requests.store`/a new `requests.attachments.store`). `data_exports` table exists but is entirely unused — could host export records but currently dead.

**Lifecycle side-effects (currently inert):**
10. `breaches.notifyOPC` / `notifySubjects` only stamp timestamps — **no notification dispatched, no OPC NotifyUs integration**. If the design wants a real OPC notification log/digest or an in-app alert to the Privacy Officer, build a `BreachNotifiedNotification` (none exists) and/or NotifyUs record. Mirror the Safeguarding `SafeguardingReviewDueNotification` pattern.
11. **No review/reminder scheduler** for PIA `review_date`, legal-hold `review_date`, or DSR due dates (no console command). H&S/Safeguarding ship reminder commands; privacy has none.
12. `export` writes a file but there is **no download route** and `export_accessed_at` is never set — add a `requests.download` (streamed, logged) endpoint if the modal needs an actual download button.

**Stubs / dead surfaces to remove or build:**
13. `PrivacyReportController::export` is a literal "coming soon" stub (`reports.export` route) — build it (CSV/JSON) or delete the route+button (per "hide unbuilt actions" rule).
14. `deletion.execute` honours neither `hard_delete_after_years` nor `active_case_exemption`, and `getPersonalDataFields()` only covers `Client`/`ClientNote`/`ClientDocument` (any other policy `model_type` silently anonymises nothing). Either document this clearly in the confirm modal or extend the field map / add active-case exclusion.

**Detail surfaces for modal-first:**
15. `LegalHold` and `DataRetentionPolicy` have **no `show` method** — detail data is only available via their `edit` pages. A modal-first rebuild needs either a `show` (JSON/Inertia partial) or to source detail from the index payload.

**Permissions:** all six keys (`privacy.viewRequests/processRequests/manageRetention/manageLegalHolds/reportBreaches/conductDPIA`) are already seeded (`RbacSeeder.php:438-443`, granted to admin-tier at 590-591) and double-gated (route middleware + inline `canDo`). **No new permissions required** for the rebuild — but note seeders don't run on deploy, so these are presumably already live.

**Relevant file paths:**
- Controllers: `C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/nervous-austin-66fe46/app/Http/Controllers/{DataSubjectRequest,DataBreach,LegalHold,DataRetentionPolicy,DPIA,DataDeletionLog,PrivacyReport,PrivacyDashboard}Controller.php`
- Routes: `…/routes/privacy.php`
- Models: `…/app/Models/{DataSubjectRequest,DataBreachLog,LegalHold,DataRetentionPolicy,PrivacyImpactAssessment,AnonymizationLog}.php`
- Schema: `…/database/migrations/2026_01_28_000004_create_data_retention_privacy_tables.php` (+ `2026_04_23_235500_add_metadata_to_data_breach_logs_table.php`)
- Permissions: `…/database/seeders/RbacSeeder.php:438-443, 590-591`
- Reusable holiday helper: `…/app/Domain/Hr/Services/PublicHolidayCalendar.php`
- Existing FE pages (20): `…/resources/js/pages/privacy/**` (dashboard.tsx, requests.tsx + requests/{create,show}.tsx, breaches*, legal-holds*, retention*, dpia*, deletion-logs.tsx, reports/compliance.tsx)