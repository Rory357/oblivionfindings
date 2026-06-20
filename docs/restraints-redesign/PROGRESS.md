# Restraints & Behaviour Support — Redesign Progress

Self-paced `/loop` rebuild of `/health-safety/restraints` to the **H&S gold standard**
(near-twin of Incidents / Safeguarding / Fleet Incidents / Hazards). Source design drop:
`Downloads/Restraints Page.zip` (prototype `Restraints Register.dc.html` + handover MDs).
Worktree: `.claude/worktrees/dazzling-bell-cb5a50` (branch `claude/dazzling-bell-cb5a50`).

NZ-only · web-only · Ngā Paerewa NZS 8134:2021 · least-restrictive-care framing.

---

## §0 LOCKED DECISIONS (do not relitigate)

1. **Canonical models** = `App\Models\RestraintEvent` + `App\Models\BehaviourSupportPlan`
   (driven by `RestraintController` under HealthSafety). The Health & Clinical redesign's
   "Restraint lens" is a *separate analytical view* that reads the same data — do NOT duplicate
   the register there. This loop owns the operational register only.
2. **Reuse, never duplicate** the gold-standard kits:
   - `@/pages/health-safety/components/hs-hero-kit` (HeroShell/Medallion/StatusPill/Cluster/ClusterTile/Segmented/ComplianceBadges/SummaryStrip + Tone/DOT_CLASS/fmt)
   - `@/pages/health-safety/components/register-row-kit` (FlagBadge/RegisterTableHeader/TONE_BG/TONE_DOT/titleCase/initials/entityTone)
   - `@/components/rostering/{tab-strip,entity-filter,shift-context-menu}`
   - `@/components/wizard/shell` (WizardShell **already has** stepper-rail + `pct` meter + footerStart/footerEnd + success; ReviewCard/ReviewRow/WizardStepPane/WizardSuccessPane)
   - `@/components/wizard/primitives` (Field/FieldErr/SubHead/StepHead/InfoCard/SelectInput/Segmented/ChipMulti/TilePicker/Ring)
   - `@/components/ui/file-dropzone` → **AttachmentUploader** = the "premium document upload" (drag-drop, image preview, staged sequential posts). Used by incident detail.
3. **Modal idiom = `add-client-dialog.tsx`** (stepper rail + completeness ring + footer Back/Cancel/Continue/**Save & add another**/Save; `stepForError` maps server errors → owning step). Carry the `eslint-disable no-restricted-syntax` header on bespoke modal surfaces.
4. **Permissions**: introduce dedicated `restraints.view|create|manage|review`; seed to roles
   currently holding `hazards.manage|create|view`; reconcile route + controller + sidebar.
   (Permissions are SEEDED not migrated — must run seeder; deploys skip seeders → 403 until run.)
5. **Interventions storage**: keep TEXT `approved_interventions`/`prohibited_interventions`;
   treat as newline-delimited lists in UI (split on read → chips, join on save). Non-destructive.
6. **Preserve** `RestraintEventObserver` (HsEvent `restraint` bridge + `ComprehensiveAlertBridgeService`
   alert on injury/out-of-plan) and respite `recordRestraint` active-BSP auto-link. Both keep firing.
7. **Detail-as-modal**: `?event={id}` / `?plan={id}` Inertia partial reloads (`only:['detail']`);
   closing drops the param → `detail` null.
8. Semantic tokens only; app-primary gradient on hero only; en-NZ dates.
9. **Premium upload gap**: new `restraint_event_attachments` table + endpoints + `AttachmentUploader`
   wired into the event detail dialog (restraints had no attachments before — feature gap).

---

## Ground-truth audit (verified 2026-06-20, see chat)

- **Models** exist & solid: `RestraintEvent` (29 fillable, `staff_involved` array cast, 8 rels incl
  `relatedIncident`/`behaviourSupportPlan`/`authorisedBy`/`reviewedBy`, SoftDeletes, AuditableChanges);
  `BehaviourSupportPlan` (status draft/active/under_review/archived, `restraintEvents` hasMany).
- **Migration** `2026_03_28_300002_create_restraint_register_tables.php`. ❌ no `behaviour_support_plan_reviews`,
  ❌ no `status_changed_at/by`, ❌ no attachments table.
- **Controller** `RestraintController`: `index()` events `paginate(25)` + **plans `->get()` unbounded** +
  stats(events_30d/active_plans/reviews_due) + clients/sites/staff + can_create/can_review.
  storeEvent/updateEvent/storePlan/updatePlan. **BUG #13: `updateEvent` severity `nullable|in:low,medium,high`
  drops `critical`** (storeEvent allows critical).
- **Observer** `RestraintEventObserver` → HsEvent `CATEGORY_RESTRAINT` + bridge alert when injury OR out-of-plan. PRESERVE.
- **Routes** `routes/health-safety.php` ~L90-106 gate on `hazards.*`. Respite `routes/respite.php` ~L87
  `POST /respite/stays/{stay}/restraints` → `RespiteStayController@recordRestraint` (auto-links active BSP).
- **Permissions**: NO `restraints.*` (all `hazards.*`). Seeder `database/seeders/RbacSeeder.php` (hazards.* ~L446-451).
- **Sidebar** `app-sidebar.tsx` ~L1267 gate `hazards.view || safeguarding.viewAny`, title "Restraint Register", icon Clipboard.
- **Current page** `pages/health-safety/restraints/index.tsx` ~1333 lines, PageHero + basic Dialog (to be replaced).
- **Respite create surface** `components/respite/modals/stay-actions.tsx` `RestraintModal` (off-pattern, to swap).
- **Client profile**: tabs in `pages/operations/clients/show.tsx` (~L766 'observations'→BehaviourAbcTab); panels in
  `components/clients/profile/tabs/`. Add read-only restraints panel near behaviour-abc.
- **Analytics/dashboard**: `HsKpiService`/`HsAnalyticsService`; `pages/health-safety/{analytics,dashboard}.tsx` (recharts).

---

## Steps (status)

- [x] **1. Schema & backend spine** — ✅ DONE+migrated+verified. Migrations `2026_06_20_000001` (plan `status_changed_at/by`,
      `behaviour_support_plan_reviews`, `restraint_event_attachments`+category) & `2026_06_20_000002` (restraints.* perms +
      dynamic grant from hazards.*). Models: BSP `reviews()`/`statusChangedBy()`+fillable/cast; RestraintEvent `attachments()`;
      new `BehaviourSupportPlanReview` + `RestraintEventAttachment`. RbacSeeder defs+dynamic grant block. BSP factory added.
      Verified: HSO→manage+review, view-only role→view only, admin→all.
- [x] **2. Controller rebuild** — ✅ DONE. index payload (lens/tab/both paginated/tabCounts/hero/filters/clients+sites+staff/
      incidents picker/lazy detail/can); event+plan detail serializers; storeEvent (server duration) + updateEvent **(critical
      fix)**; storePlan/updatePlan; plan lifecycle (activate/submit-review/archive) + reviewPlan(+history); attachments
      store/destroy/download; CSV export. Gates → restraints.* (canView/Create/Review/Manage).
- [x] **3. Nav + routes** — ✅ DONE. routes/health-safety.php 13 routes on restraints.* gates; HandleInertiaRequests `can.restraints`;
      sidebar gate `can.restraints.view` (title "Restraints & Behaviour Support"). route:list clean.
- [x] **Backend test** — ✅ `tests/Feature/HealthSafety/RestraintRegisterTest.php` **11/11 green** (payload, perms, detail,
      store+duration, critical-severity fix, plan lifecycle, review history, attachment up/remove, export CSV).
- [x] **4. Register page** — ✅ DONE. `pages/health-safety/restraints/index.tsx` rebuilt (hero+WorkflowRibbon current="report"+2
      clusters+4 compliance badges+filter footer w/ period pills→`period` param; Events/Plans **lens toggle** + per-lens TabStrip;
      events table + plans card grid; ctx menus (event/plan/hero); deep-link open via ?event=/?plan= only:['detail']; Export popover).
      Foundation `shared.tsx` + controller `plansForPicker` + controller `period` resolution. tsc-clean.
- [x] **5. Event detail dialog** — ✅ DONE. `restraint-event-detail-dialog.tsx` (WizardShell-as-detail; sections overview/response/
      injury/evidence/review; Options-bar footer; **Review pane w/ critical-severity fix**; **AttachmentUploader w/ category**
      body_map/injury_photo/authorisation/debrief — extended shared file-dropzone w/ optional `categoryField`; linked plan/incident deep-links).
- [x] **6. Plan detail dialog** — ✅ DONE. `bsp-detail-dialog.tsx` (overview / content w/ approved✓ vs prohibited✗ chips /
      lifecycle stepper+transitions / reviews history + record-review pane). tsc-clean.
- **All 6 frontend files tsc-clean** (88 pre-existing tsc errors elsewhere are NOT mine — Governance/clinical/auth).
- [x] **7. Wizards** — ✅ DONE. `restraint-event-wizard.tsx` (7 steps, add-client idiom, completeness ring, Save & add another,
      stepForError jump, prescope prop for respite reuse, staff multi-select, posts /events) + `bsp-wizard.tsx` (5 steps, tag-list
      interventions w/ suggestions→newline-delimited, posts /plans). Both **typecheck clean**. Templates: fleet report wizard + wizard shell/primitives.
- [x] **8. Cross-module** — DONE the high-value/low-risk set; deferred the churny/high-plumbing ones (per review recommendation):
      - ✅ **E1** ClientIncident `restraintEvents()` reverse rel + **surfaced on incident detail** (IncidentController eager-load + `restraint_events` payload; incident-detail-dialog LinkedRow + empty-state guard) — one change lights up incidents index+show.
      - ✅ **E2-backend** active-BSP auto-link mirrored into `storeEvent` (the data-integrity piece). ⏳ **E2-frontend DEFERRED** (respite RestraintModal→wizard swap needs respite-page lookup plumbing; existing modal works + recordRestraint still auto-links — low incremental value).
      - ✅ **E3** client-profile read-only panel: `RestraintController@clientSummary` JSON endpoint (gated restraints.view) + route `/clients/{client}/summary` + `components/clients/profile/restraints-bsp-panel.tsx` (self-fetch, active BSP + recent events, deep-links) injected at top of `behaviour-abc.tsx`. Isolated — NO show.tsx/ClientController churn.
      - ✅ **D1** "Record event under this plan" (wizard Prescope +behaviour_support_plan_id; plan ctx item + BSP detail footer button).
      - ⏳ **E4 DEFERRED** analytics/dashboard restraint KPI tiles (churny shared files; hero already shows the numbers — land RestraintKpiService extraction when those loops settle).
      - ⏳ **D3 DEFERRED** post-hoc "Link incident" on existing events (backend handover §4 excludes related_incident_id from updateEvent; prototype's linker is inert; captured at create-time; would violate hide-unbuilt-actions).
      - Reuse decision held: bespoke `restraint_event_attachments` (matches 4 gold siblings) over polymorphic HsAttachment.
- [x] **9. Adversarial review (/workflows)** — ✅ DONE. `restraints-adversarial-review` workflow (20 agents, 6 dims→verify→synthesize):
      13 raw → **12 confirmed**. **FIXED**: A1 (updateEvent re-stamp guard — audit integrity), B1 (period pill vs all-time default),
      B2 (filter id int cast), B3 (plans export from/to), C1 (review→status documented), E1/E2-backend/E3/D1 (above).
      Deferred w/ rationale: E2-frontend, E4, D3, D2 (event-detail rail consolidation — doc-only, accept as built). +4 new tests.
- [ ] **10. (user) commit / merge / deploy / Chrome-verify** on .com.

## Verification recipe (worktree)
- Frontend: `npx tsc --noEmit`, eslint, `npx vite build` — run with cwd=worktree (node_modules junctioned).
- Wayfinder (route TS): `php84 -d memory_limit=1024M artisan wayfinder:generate` after adding routes.
- Migrations: run local autonomously against shared dev DB.
- Backend tests: vendor is a **real robocopy** (not junction) so worktree `app/` IS exercised — run scoped:
  `& "C:\Users\steph\.config\herd\bin\php84\php.exe" artisan test --filter=Restraint`.
- php binary: `C:\Users\steph\.config\herd\bin\php84\php.exe` (avoid Node-spawned php.bat arg mangling).
