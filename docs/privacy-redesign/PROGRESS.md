# Privacy Dashboard — Command-Centre Redesign · PROGRESS

> Rebuild `/privacy/dashboard` into a **Privacy command centre** (H&S gold standard: hero → tabs →
> right-click rows → detail-as-modal → Add-client-style wizards). NZ-only (Privacy Act 2020, OPC,
> IPP 6/7, 20 working days, NHI, en-NZ, NZD). Web-only. Design source:
> `Privacy Dashboard.dc.html` handoff. Worktree `nervous-austin-66fe46`.
>
> Full audits in `docs/privacy-redesign/audit/{design,controllers,models,frontend,kits,crossmodule}.md`.

---

## §0 LOCKED DECISIONS

1. **Reuse, don't duplicate** (user mandate). The premium uploader ALREADY exists →
   `resources/js/components/ui/file-dropzone.tsx` (`FileDropzone`, `StagedFileCard`,
   `AttachmentUploader`: drag-drop, multi-file, per-file note + sensitive toggle, sequential
   upload-as-progress, remove, type/size hints). The mock has **NO upload anywhere** — we ADD it by
   reusing `AttachmentUploader`. Backend = clone the **Safeguarding attachment stack**
   (`SafeguardingAttachment` model + migration + `SafeguardingAttachmentController` + routes).
2. **Document upload = polymorphic** `privacy_attachments` (morphs `attachable`) so all 5 domains
   (request/breach/hold/dpia/retention) share ONE table + ONE controller + ONE `<PrivacyAttachmentsPane>`
   wrapper. Allow-list of attachable types in the controller (no IDOR). Cols mirror Safeguarding:
   `disk, original_name, path, mime, size, notes, is_sensitive, uploaded_by, softDeletes`.
3. **Create-route reconciliation**: the five `…/create` GET routes **redirect** to
   `/privacy/dashboard?new=<domain>` (wizard auto-opens, mirrors incidents `?report=`). Keep route
   ordering (create before wildcard) so deep-links don't 404.
4. **+20 working days (IPP 6)**: new `App\Domain\Privacy\Services\StatutoryDueDate::dueFrom($date)`
   wrapping the existing `App\Domain\Hr\Services\PublicHolidayCalendar::isPublicHoliday()` (adds 20
   working days, skipping weekends + NZ public holidays). Replaces `DataSubjectRequest::boot()`'s
   GDPR-style `now()->addDays(30)`. `extend` offers the computed suggestion (server authoritative).
5. **Status → tone maps** (canonical, single source `privacy-shared.ts`):
   - `REQUEST_STATUS`: received=warning, under_review=warning, identity_verification=info,
     in_progress=info, completed=success, rejected=critical("Refused"), withdrawn=neutral.
   - `REQUEST_TYPE`: access="Access · IPP 6"(info), rectification="Correction · IPP 7"(info),
     erasure="Deletion"(warning), restriction="Restriction"(warning), portability="Portability"(info),
     objection="Objection"(warning), automated_decision="Automated decision"(critical). **Enum values
     frozen; only labels re-skinned NZ/IPP.**
   - `BREACH_STATUS`: discovered=critical, under_investigation="Investigating"(warning),
     contained=info, notified="OPC notified"(info), resolved=success.
   - `RISK`: low=success, medium=warning, high=warning, very_high=critical.
   - `DPIA_OUTCOME`: approved=success, approved_with_conditions=info,
     requires_dpo_review="Requires Privacy Officer review"(warning), rejected=critical, null="In review"(info).
   - `HOLD_STATUS`: active=warning, released=neutral. `RETENTION`: active=success, inactive=neutral.
     `DELETION`: executed=neutral, scheduled=warning.
   - Tone union for pills = success|warning|critical|info|neutral → local `pillClass()`/`dotClass()`
     in `privacy-shared.ts` (TONE_BG/TONE_DOT only cover 4; we add `info`→`bg-status-info-bg text-status-info`).
6. **Permissions**: all 6 seeded (`RbacSeeder.php:438-443`, admin/provider-manager at 590-591). NO new
   perms. `can.manage` in UI = per-domain write perm: requests→`processRequests`, breaches→`reportBreaches`,
   holds→`manageLegalHolds`, retention→`manageRetention` (also deletion), dpia→`conductDPIA`.
   Attachment upload/delete gated on the owning domain's write perm; download on `viewRequests`.
7. **Audit/History** = hand-built timeline from each record's own lifecycle timestamps (mirror
   Safeguarding `buildConcernDetail`/`TimelineSection`), optionally enriched from existing `audit_logs`
   rows (`AuditableChanges` already writes them for DSR + breach).
8. **No SoftDeletes / right-click-Delete** — the mock's row menus have NO delete; out of scope. Keeps
   migrations minimal.
9. **Detail dialog reference** = `resources/js/components/incidents/incident-detail-dialog.tsx`
   (multi-section rail + ReviewCards + footer status chips + Options bar + `initialAction`), NOT the
   lightweight `hs-detail-dialog.tsx`. Build `PrivacyRequestDetailDialog` + breach/hold/dpia/retention/
   deletion siblings on this pattern (all on `WizardShell`).
10. **Wizard style** = `add-client-dialog.tsx` exactly, via `WizardShell` + `wizard/primitives`
    (`Field/SelectInput/TilePicker/ChipMulti/Segmented/SubHead/StepHead/InfoCard`), per-step validation
    that jumps to first failing step, "Save & add another" (stay) + primary Create, `WizardSuccessPane`.

---

## §1 KEY DATA FACTS (source of truth = migrations; see audit/models.md)

- **No** privacy model uses SoftDeletes; DSR + DataBreachLog use `AuditableChanges` (→ `audit_logs`).
- **DSR** cols incl. client_id+client(), user_id+user(), received_at/due_date (boot-set; due=+30 CAL
  days NOW → change to +20 working), identity_verified ENUM(pending,verified,failed),
  verification_method(text), assigned_to_user_id, export_path. `request_details` **NOT NULL** (mismatch
  → make nullable). Scopes: `open`, `overdue` (honours extended_due_date). Ref auto-gen in boot (DSR-YYYY-NNNN).
- **DataBreachLog**: NO client link (org-level). cols incl. breach_type+severity (string, unconstrained,
  never captured), requires_authority_notification/authority_notified_at/authority_reference,
  requires_subject_notification/subjects_notified_at/notification_method, resolved_at. `nature_of_breach`
  NOT NULL; `likely_consequences`+`measures_taken` already relaxed nullable. **NO ref auto-gen** (controller
  makes BR-YYYY-NNNN inline). No `metadata` column (despite migration name).
- **LegalHold**: hold_type(string), reason, holdable morph (nullable), related_records(json), status(active,
  released), imposed_at/by, released_at/by/release_reason, review_date, legal_authority. NO created_by. NO
  AuditableChanges. NO ref auto-gen (controller LH-YYYY-NNNN).
- **DataRetentionPolicy**: model_type, policy_name, retention/archive/hard_delete_after_years, 3 exemption
  bools(default true), legal_basis, business_justification, active, last_applied_at. **NO `next_review_at`**
  (ADD), **NO scopeActive**.
- **PrivacyImpactAssessment**: assessment_type ENUM(4), overall_risk_level ENUM(4) NOT NULL,
  residual_risk_level ENUM(4) nullable, outcome ENUM(approved,approved_with_conditions,rejected,
  requires_dpo_review) nullable, assessor_id, assessment_date, review_date. **NO `review_notes`** (ADD;
  review verb validates but discards it). json arrays: personal_data_types, data_subjects, identified_risks,
  mitigation_measures.
- **AnonymizationLog** = the "deletion log" model (deletion-logs page reads it). cols: model_type,
  model_id, reason(string), fields_anonymized(json), anonymization_methods(json), data_subject_request_id,
  anonymized_at, anonymized_by_user_id, reversible, reversal_key_path. NO morphTo.
- **DataExport**: table exists, **model MISSING** → `DataSubjectRequest::dataExports()` hasMany fatals if
  called. ADD minimal model.
- **NOT-NULL the wizards MUST always send**: DSR(request_type, request_details→making nullable),
  Breach(breach_reference[controller], discovered_at, nature_of_breach), Hold(hold_reference[controller],
  hold_type, reason, imposed_at), Retention(model_type, policy_name), PIA(assessment_name,
  project_or_process, assessment_type, assessment_date, processing_purpose, legal_basis, overall_risk_level).

---

## §2 ENDPOINT CONTRACTS (FE=BE; see audit/controllers.md for full table)

requests.store ← request_type*, subject_name*, subject_email*, request_details?, specific_data_requested?[],
assigned_to_user_id?, **+client_id? (ADD)**, **+attachments via separate endpoint**. Lifecycle:
verify-identity{verification_method*}, extend{extension_reason*, extended_due_date*}, complete{completion_notes?},
refuse{rejection_reason*, rejection_legal_basis*}, export{∅ → writes JSON+flash}.
breaches.store ← nature_of_breach*, discovered_at*, affected_data_categories?[], approximate_individuals_affected?,
likely_consequences?, measures_taken?, requires_authority_notification(bool), requires_subject_notification(bool),
**+breach_type?, +severity? (ADD capture)**. Lifecycle: notify-opc{authority_reference?}, notify-subjects
{notification_method*}, resolve{resolution_notes*}.
legal-holds.store ← hold_type*, reason*, holdable_type?+holdable_id?, related_records?[], legal_authority?,
review_date?. release{release_reason*}.
retention.store ← model_type*, policy_name*, retention_period_years*, description?, archive_after_years?,
hard_delete_after_years?, legal_basis?, business_justification?, 4 bools, **+next_review_at? (ADD)**.
dpia.store ← assessment_name*, project_or_process*, assessment_type*, processing_purpose*, legal_basis*,
overall_risk_level*, description?, 4 json arrays?, residual_risk_level?, review_date?. approve{∅},
review{review_notes* → ADD column + persist}.
deletion.execute ← policy_id*, confirm*(accepted). (critical confirm modal)

---

## §3 BUILD STEPS (each = one commit; tests after)

- [x] **Step 0** — Deep audit (6 agents) + manual kit reads + this plan.
- [x] **Step 1** — Backend data layer. ✅ DONE. `privacy_attachments` polymorphic table +
  `PrivacyAttachment` model + `attachments()` morphMany on all 5 domain models; migration added
  `review_notes`(PIA) + `next_review_at`(retention, date) + `request_details` nullable(DSR);
  `DataExport` model (fixes orphaned `dataExports()` hasMany); `App\Domain\Privacy\Services\StatutoryDueDate`
  (+20 working days via `PublicHolidayCalendar`) wired into `DataSubjectRequest::boot()` (replaced
  GDPR-style +30 calendar days); `DataRetentionPolicy::scopeActive`; `Client::dataSubjectRequests()`.
  Migrations RAN green on shared DB; all files php-lint clean; schema verified.
- [x] **Step 2** — Rebuilt `PrivacyDashboardController@index`. ✅ DONE. Emits hero (live + attention +
  badges), tabCounts (7), per-tab paginated worklist (request/breach/hold/retention/dpia/deletion, shaped
  rows + active-first ordering), detail per `?request|?breach|?hold|?dpia` (full fields + attachments
  [need-to-know locked shell] + lifecycle timeline), can map, filters (q/period/site_id), sites/staff/
  clients, `?new` passthrough. `requests.store` now accepts `client_id`/`received_at`/`verification_method`
  (links the subject so export works). Report controller: `ico_notifications`→`opc_notifications`,
  high-risk includes very_high, inline perm checks, real streamed-CSV `export` (opc_register/sla/retention/
  full) replacing the "coming soon" stub. compliance.tsx prop renamed. All php-lint clean.
  NOTE: deletion "Execute" is policy-scoped (`deletion.execute` takes policy_id) — lives on Retention, not
  a fake per-log "scheduled" state; deletion-logs tab is read-only AnonymizationLog history.
- [ ] **Step 3** — `PrivacyAttachmentController` (store/download/destroy, allow-list + perm gating) +
  routes; serialize `attachments[]` into detail payloads (Safeguarding shape, sensitive→locked shell).
- [x] **Step 4** — `privacy-shared.ts` ✅ DONE. PrivacyTone (5, incl. info) + PRIVACY_PILL/PRIVACY_DOT;
  REQUEST_STATUS/REQUEST_TYPE/BREACH_STATUS/RISK/DPIA_OUTCOME/HOLD maps + safe-fallback lookups; en-NZ
  fmtDate/fmtDateTime/fmtNum; wizard option sets (tiles/chips) lifted + NZ/IPP re-skinned.
- [x] **Step 5** — Rebuilt `dashboard.tsx` ✅ DONE. HeroShell (medallion + 2 pills + h1 + desc + New-request
  CTA + Compliance-reports popover [4 streamed-CSV links] + right-click hero create menu), 2 HeroClusters
  + 5 NZ HeroComplianceBadges, segmented period + Site EntityFilter + search + clear footer, TabStrip (7),
  `PrivacyWorklist` (6 per-tab layouts, right-click rows + a11y, empty states) + LaravelPagination.
  router.get wiring (tab/period/site/search/detail partial-reload/?new strip).
- [x] **Step 6** — `PrivacyDetailDialog` ✅ DONE. Config-driven per kind (request/breach/hold/dpia) on
  WizardShell: section rail + ReviewCards + Documents pane + History timeline; footer status chips +
  Options bar; `initialAction` deep-link from ctx menu.
- [x] **Step 7** — Five wizards ✅ DONE via ONE config-driven `PrivacyWizard` engine + `privacy-wizard-
  configs.tsx` (no duplication). Add-client style on WizardShell, per-step validation jump-to-first-fail,
  Save & add another + success pane. Reconciliation: 5 `create()` routes → `redirect('?new=domain')`;
  5 stores return `back()` when `_modal` (success pane stays). DPIA `review` now persists review_notes;
  retention store accepts next_review_at.
- [x] **Step 8** — `PrivacyActionModal` ✅ DONE. Config-driven small modal: verify/extend/complete/refuse/
  export, notify-opc/notify-subjects/resolve, release, approve/review, execute-deletion (critical confirm
  with irreversible checkbox). Premium document upload (`PrivacyAttachmentsPane` reusing `AttachmentUploader`)
  lives in the detail Documents section (record-exists pattern).
  ✅ `npx tsc` clean on all privacy files; ✅ `npm run build` green (3m15s, Wayfinder routes generated).
- [ ] **Step 9** — Client-profile Privacy panel (tab + read-only DSR list); GDPR→NZ/IPP re-skin of legacy
  list/show pages (labels only).
- [ ] **Step 10** — Polish + verify: lint, typecheck, vite build, tests (dashboard controller, attachments,
  working-days). NZ/IPP sweep (no GDPR/Article/72h/DSAR/ICO/DPO labels).
- [ ] **Step 11 (optional)** — Reminders: request-overdue + breach-OPC-due notifications + scheduled command.

## §4 RISKS / WATCH-OUTS
- Worktree has NO vendor/node_modules/.env → backend tests load the PARENT app (junctioned). Verify backend
  by merging or running in parent; migrations + frontend build DO use the worktree. (See memory
  reference_worktree_junction_tests_load_parent_app.)
- Route ordering: keep `…/create` BEFORE wildcard `{model}` routes.
- Permissions seeded but deploys skip seeders — these 6 are presumably already live (no new perms added).
- Shared-file edits (cross-module risk): `app-sidebar.tsx` (none needed), `operations/clients/show.tsx`
  (+privacy tab), `Client.php` (+relation), `routes/privacy.php` (+attachment routes, +create redirects).
- `request_details` NOT NULL vs nullable validation → make column nullable (additive align migration).
- Pint not enforced; keep NEW files pint-clean, don't reformat shared files.
