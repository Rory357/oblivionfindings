# Safe Work Procedures — H&S gold-standard redesign

Rebuild `/health-safety/procedures` to the H&S gold standard (near-twin of Incidents / Safeguarding /
Lone-Workers): `HeroShell` hero (WorkflowRibbon + 2 clusters + NZ compliance badges + footer filter bar +
right-click), `TabStrip` with server counts, register table (left-click → detail modal, right-click →
`ShiftContextMenu`), **detail-as-modal** on `WizardShell`, and **create/edit = the "Add client" modal
wizard**. Plus the user's headline ask: a **premium controlled-document upload** feature, and deep
cross-module integration. NZ-only, web-only, modal-first.

Source: `Procedures Page.zip` design handoff (README + BACKEND_CHANGES + 3 screenshots). Audited by a
7-agent workflow (`wf_2767298b-5e8`) — backend gaps, React/modal contracts, document-upload reuse,
cross-module (HR/Sites/Client/Training/hub/analytics/ribbon), seeder/RBAC/tests/no-dup.

Worktree: `.claude/worktrees/cool-shirley-187cb8` · branch `claude/cool-shirley-187cb8`.

---

## §0 LOCKED DECISIONS (resolved open questions)

1. **No duplication — reuse everything.** Backend twin = `LoneWorkerController` (index/tabCounts/heroBlock/
   detail idioms). Frontend register = clone `events/index.tsx`. Detail modal = twin of `EventDetailDialog`
   on `WizardShell` (NOT `hs-detail-dialog.tsx` — that's a 1-section read-only shell). Wizard = `WizardShell`
   + `wizard/primitives` with the **driver logic** ported from `add-client-dialog.tsx` (do NOT clone its
   inline chrome — `WizardShell` IS that chrome, extracted from it). Model/migration already carry every
   lifecycle/version column → **no schema change for the core**.
2. **Categories → canonical 9** (the design tone map): manual_handling, challenging_behaviour, lone_working,
   medication, infection_control, fire_safety, emergency_procedures, equipment_use, personal_care. This is
   the `Rule::in()` allow-list + TilePicker + tone denominator. Display maps unknown→`neutral` (defensive for
   any legacy rows). Seeder uses only these 9.
3. **Premium document upload = REUSE polymorphic `HsAttachment`** (morphMany on SafeWorkProcedure) — **NO new
   table**. Add ONE nullable `version_at_upload` column to `hs_attachments` (version-stamps each doc). Disk =
   `private` (controlled docs). UI = the existing `AttachmentUploader`/`FileDropzone` kit + a `DocumentsSection`
   cloned from drill `EvidenceSection`. Upload/destroy gated `procedures.manage`, download `procedures.view`.
   **IDOR guard:** assert BOTH `attachable_id` AND `attachable_type === SafeWorkProcedure::class` (morph).
4. **Archive↔restore = exact round-trip** via a nullable `previous_status` column on `safe_work_procedures`
   (spec says "archived ↔ previous status"). Small migration.
5. **Permissions:** dedicated `procedures.{view,create,manage,approve}` in `RbacSeeder`; granted full to
   provider_manager + coordinator + health_safety_officer + team_lead; view-only to auditor +
   maintenance_coordinator (admin inherits via pluck). Expose a `procedures` block in `HandleInertiaRequests`
   + bump `PERMISSIONS_CACHE_VERSION` v2→v3. Migrate routes `hazards.*` → `procedures.*`. ⚠️ perms are SEEDED
   not migrated → **run RbacSeeder --force on the server post-deploy** or the routes 403.
6. **Entity resolution:** wizard pickers store IDs (sites, training) + role KEYS (`roles.name`). Reuse
   `MultiEntityFilter` (@/components/rostering) for the multi-selects; `EntityFilter` for the hero Site
   filter. `hazards_addressed` + `ppe_required` stay free-text `ChipMulti`. `hazards_addressed`↔SiteHazard FK
   = **deferred** (vocab mismatch; shown as free-text chips). Resolution is defensive (unknown id/key → shown
   as-is) for any legacy free-text data.
7. **Cross-module (IN SCOPE):** WorkflowRibbon `document` stage (shared, additive); H&S hub HeroClusterTile +
   controller datum; analytics `procedure_review_pct` scorecard item (HsAnalyticsService only, not HsKpi);
   Site profile read-only Procedures panel (site-scoped); `/hr/my` + HR staff profile read-only panels;
   Client risk tab panel. Real backend (a `SafeWorkProcedure` scope) — **no stub Acknowledge** buttons
   (per feedback_hide_unbuilt_actions): acknowledgement persistence (new `procedure_acknowledgements` table)
   only where wired end-to-end; otherwise omit the affordance.
8. **Retire** create/edit/show.tsx as the primary path → wizard + modal; keep the GET routes as thin
   deep-link fallback shells ("Open full page").
9. **Seizure/hoist topics** map onto canonical categories (seizure→emergency_procedures, hoist→equipment_use)
   — no new category.

---

## Steps

- [x] **Step 0 — Workbench + tracker.** Copied vendor (no junction) + .env + wayfinder TS + dump-autoload.
- [x] **Step 1 — Foundation:** migrations (`previous_status` on safe_work_procedures, `version_at_upload` on
      hs_attachments), model wiring (`attachments()` morphMany, `previous_status` fillable, HsAttachment
      `version_at_upload`), permissions (RbacSeeder + HandleInertiaRequests + cache bump), route middleware
      swap + 4 new transition routes + 3 attachment routes, canonical categories. Run migrate + seed locally.
      _(Also added `owner_id` + `review_frequency_months` — feature-complete wizard fields.)_
- [x] **Step 2 — Controller rewrite:** `index()` gold-standard payload (tabCounts, heroBlock, procedureDetail
      on `?procedure=`, can, filters {q,tab,category,status,site_id,review_state}, picker options
      sites/roles/trainingCourses/categories); in-place `back()->` for store/update/approve/submitForReview;
      add requestChanges/archive/restore/recordReview; add uploadAttachment/downloadAttachment/
      destroyAttachment; shared `reviewDue` scope + single `procedureDetail()` mapping (show() delegates).
- [x] **Step 3 — Register page:** `procedures/index.tsx` rebuilt (HeroShell + `WorkflowRibbon current="document"`
      + 2 clusters + HeroComplianceBadges + footer filters + TabStrip + `ProcedureTable` + ctx menu +
      `?procedure=` partial reload + wizard state + `?new`/`?edit` deep-link open). `document` ribbon stage added.
- [x] **Step 4 — ProcedureDetailDialog:** built on `WizardShell`. 6 sections (Overview/Steps/PPE & hazards/
      Applies to/**Documents**/History) + Options footer + lifecycle panes (submit/approve/request-changes/
      record-review/archive/restore) + `DocumentsSection` (AttachmentUploader + version chips).
- [x] **Step 5 — ProcedureWizardDialog:** built on `WizardShell` + success pane; 6 steps; create + edit
      (required `change_summary`); FreeChips/EntityChecklist/StepsEditor helpers. create/edit/show.tsx retired.
- [~] **Step 6 — Cross-module:** ✅ workflow ribbon stage · ✅ H&S hub HeroClusterTile (+ controller datum) ·
      ✅ analytics `procedure_review_pct` scorecard item. **DEFERRED** (documented): Site profile panel,
      `/hr/my` + HR staff profile (separate `PROCEDURES_HR_SURFACING_PROMPT.md` brief), Client risk tab
      (relevance rule undefined). hazards_addressed↔SiteHazard pivot deferred (vocab mismatch).
- [x] **Step 7 — Seeder + factory:** `seedSafeWorkProcedures()` (9 NZ procedures across statuses/review
      states/versions) + factory fixed (valid lifecycle states + categories). Seeder ran clean.
- [x] **Step 8 — Tests + verify:** `SafeWorkProcedureTest` **16 passing / 106 assertions** (payload/tabs/
      detail/filters/lifecycle/attachment IDOR/gating). Full `tests/Feature/HealthSafety` **298 passing /
      1169 assertions** (zero regressions from shared-file edits). tsc 0 · eslint 0 errors · prod
      `npm run build` clean (4m 2s, procedures chunk in manifest) · Dusk create-page test retargeted.
- [x] **Cross-module scopes** — `SafeWorkProcedure::scopeApplicableToSite/ToRoles` added (commit `6c2b5e07`)
      so the deferred Site/HR/Client panels are a trivial, conflict-free follow-up.
- [x] **Step 9 (FINAL) — merged → deployed → ✅ Chrome-verified LIVE on .com** (merge `d567601c`). Self-
      sufficient deploy via permissions migration (no 403). Verified register/hero/ribbon-stage/wizard live,
      0 console errors.
- [x] **Cross-module panels** (after "continue") — Site profile + `/hr/my` read-only `ApplicableProceduresPanel`
      via the scopes; merged `d89cd31c`, deployed (server build of the Risk-Assessments merge conflict
      resolution confirmed live). 18 tests/113 assert; tsc/eslint/build clean.

## FINAL STATE (2026-06-20) — ✅✅✅ DONE + LIVE
Core redesign + cross-module panels **all merged → origin/main → deployed**; core **Chrome-verified live on
.com** (commits `b2979d33`/`6c2b5e07`/`17cfb49a` → merge `d567601c`; cross-module `db549d25` → merge `d89cd31c`).
Still deferred (genuinely need input / separate brief): **HR staff-profile** panel (manager-viewing-employee —
the separate `PROCEDURES_HR_SURFACING_PROMPT.md` brief) and **Client risk-tab** panel (needs a product
relevance rule — no category→client-risk mapping exists). Both are a trivial follow-up via the model scopes +
the reusable `ApplicableProceduresPanel`. hazards↔SiteHazard pivot deferred (vocabulary mismatch).

---

## Reuse map (do NOT rebuild)
- Hero: `pages/health-safety/components/hs-hero-kit.tsx` — HeroShell/HeroMedallion/HeroStatusPill/HeroCluster/
  HeroClusterTile/HeroComplianceBadges(items override)/HeroSegmented/fmt/Tone.
- Rows: `pages/health-safety/components/register-row-kit.tsx` — RegisterTableHeader/FlagBadge/TONE_BG/TONE_DOT/
  titleCase/initials/entityTone.
- Tabs/ctx/filters: `@/components/rostering` — ShiftContextMenu/TabStrip/EntityFilter/MultiEntityFilter + types.
- Modal chrome: `@/components/wizard/shell` — WizardShell/WizardStepPane/WizardSuccessPane/ReviewCard/ReviewRow.
- Wizard fields: `@/components/wizard/primitives` — Field/FieldErr/StepHead/SubHead/InfoCard/SelectInput/
  Segmented/ChipMulti/TilePicker/Ring.
- Upload: `@/components/ui/file-dropzone` — FileDropzone/StagedFileCard/AttachmentUploader/formatFileSize.
- Copy templates: `event-detail-dialog.tsx` (detail panes/CauseListEditor), `lone-worker-detail-dialog.tsx`,
  `drill-detail-dialog.tsx` (EvidenceSection), `lone-worker-wizard.tsx`, `add-client-dialog.tsx` (driver).
- Backend twin: `LoneWorkerController`. Attachment backend: `EmergencyDrillController` upload/download/destroy.

## ⚠️ Gotchas
- Worktree: copied vendor + `composer dump-autoload -o` → worktree app/ is loaded (junction would load PARENT).
  No node_modules junction (npm resolves upward). Run PHP via `~/.config/herd/bin/php.bat` (PowerShell tool).
- Deploy: migrations run on deploy, **seeders do NOT** → run RbacSeeder --force for the new perms.
- `npm run build` needs the wayfinder vite plugin to hit a DB; for a pure FE build temporarily drop the
  plugin from vite.config.ts then `git checkout` it (don't commit).
- Shared-file edits (additive, flag in PR): workflow-ribbon.tsx (new stage → every H&S hero),
  command-centre-hero.tsx + dashboard.tsx (hub tile), HsAnalyticsService (scorecard item),
  HandleInertiaRequests (can block + cache bump), RbacSeeder, hs_attachments migration (shared morph table).
