---

# Health & Clinical — Redesign Build Plan & PROGRESS.md Tracker

**Module:** `/health-clinical` (route `health-clinical.dashboard`)
**Primary user:** Registered Nurses & clinical leads · NZ supported-living · web-only · need-to-know
**Design source:** `C:/Users/steph/Downloads/health-clinical-redesign/design_handoff_health_clinical/` (`README.md` + `GAP_ANALYSIS_AND_BACKEND.md` + `Health & Clinical.dc.html`)
**Shape:** near-twin of the Incidents / Safeguarding / Fleet-incidents redesigns (hero + two-tier tabs, modal-first, register lenses, cross-module auto-link).
**Detailed per-subsystem maps:** `docs/health-clinical-redesign/AUDIT_MAPS.json` (8 maps, file/column/prop-level).
**Loop status:** self-paced `/loop`, multiple loops running concurrently → re-check `origin/main` + grep dead links before each step.

---

## 0. Decisions (LOCKED — following the audit's own recommendations; revisit only if a step proves them wrong)

1. **NEWS2 storage = JSON + derived columns on the canonical `clinical_observations`** (compute-on-write). NO parallel `clinical_vital_signs` model. *(Risk Q1)*
2. **Vitals keys: KEEP existing JSON keys** (`respiration_rate`, `o2_saturation`) and map them inside `News2Scorer`; **ADD** new keys `consciousness` (ACVPU) + `on_oxygen` (bool). **No destructive data-backfill migration.** *(Risk Q2 — chose the non-destructive option)*
3. **Health Monitoring rollup = read BOTH stores side-by-side for v1** (`Client{Fluid,Bowel,Seizure,Sleep}Entry` + clinical observations); DO NOT migrate/unify capture (would lose fluid in/out direction + sleep hours). **Seizure stays an Event** (Log Clinical Event), not an observation. *(Risk Q3)*
4. **Health-monitoring write permissions UNCHANGED** (existing capture keeps its `medications.*`/profile gates); only the NEW cross-client READ rollup is gated `clinical.monitoring.viewAny`. *(Risk Q4 — follows from #3)*
5. **Assessments & Risk gets dedicated `clinical.assessments.{viewAny,record}`** (net-new register deserves its own ability). Lowest priority; behind in-tab banner until B8 lands. *(Risk Q5)*

**Migration policy:** additive migrations only (B1 columns, B4 `clinical_attachments`, B8 `clinical_risk_assessments`); run locally autonomously. NZ / web-only / need-to-know throughout.

---

> **One-line verdict from the audit:** almost everything the design asks for already exists in the **canonical `App\Domain\Clinical\*` stack** and the shared chrome kits. The redesign is **~80% composition + de-duplication, ~20% genuinely new backend** (NEWS2 vitals, clinical_attachments, clinical_risk_assessments, a handful of endpoints/permissions). The single largest landmine is a **dead parallel stack** (`App\Models\Clinical*` + `App\Services\HealthClinical\*` + `HealthClinical\HealthClinicalController`) that the two module-level write routes currently point at — fix that first.

---

## 1. De-duplication register

The most important section. Every row is a place the implementer could accidentally build a second copy. **Use the canonical thing; never rebuild.**

### 1A. The split-brain clinical stack (CRITICAL — verified by 4 separate audit maps)

There are **two parallel clinical stacks**. The physical DB tables carry the **Domain** columns because, at the identical `2026_04_13_100000` timestamp, `create_clinical_observations_table` sorts alphabetically *before* `create_health_clinical_tables`, so the latter early-returns (`if (Schema::hasTable($t)) return;`) and **never creates anything**. The legacy models therefore reference columns that do not exist.

| Concern | ✅ CANONICAL (reuse) | ❌ DEAD (retire / never touch) |
|---|---|---|
| Observation model | `app/Domain/Clinical/Models/ClinicalObservation.php` | `app/Models/ClinicalObservation.php` (divergent `TYPES`: food_fluid/seizure/mood/skin_integrity) |
| Event model | `app/Domain/Clinical/Models/ClinicalEvent.php` | `app/Models/ClinicalEvent.php` (uses `metadata`/`follow_up_required`/`linked_observation_id`) |
| Protocol model | `app/Domain/Clinical/Models/ClinicalProtocol.php` | `app/Models/ClinicalProtocol.php` (uses `status`/`next_due_at`/`custom_interval_days`) |
| Schedule model | `app/Domain/Clinical/Models/ClinicalProtocolSchedule.php` | `app/Models/ClinicalProtocolSchedule.php` (`day_of_week`/`preferred_time`) |
| Observation write+validation | `app/Domain/Clinical/Services/ClinicalObservationService.php` (`validateDataForType`) | `app/Services/HealthClinical/ClinicalObservationService.php` |
| Event write (HS-link + signals) | `app/Domain/Clinical/Services/ClinicalEventService.php` | `app/Services/HealthClinical/ClinicalEventService.php` |
| Protocol schedule engine | `app/Domain/Clinical/Services/ClinicalProtocolService.php` | `app/Services/HealthClinical/ProtocolService.php` |
| Per-client summary | `app/Domain/Clinical/Services/ClinicalHealthSummaryService.php` | — (note: `app/Services/HealthClinical/HealthSummaryService.php` was *already migrated to Domain models* — **safe to keep** for `/clients/{client}/summary` + wizard rail) |
| Dashboard/registers/KPIs | `app/Domain/Clinical/Services/ClinicalDashboardService.php` | — |
| Controller | `Clinical\HealthClinicalDashboardController` + `Clinical\ClientClinicalController` + `Clinical\HealthClinicalProtocolController` | `HealthClinical\HealthClinicalController` (dashboard/observations/events/protocols/storeProtocol/updateProtocol are **unrouted dead methods**; only storeObservation/storeEvent/clientSummary are wired, and the first two write to the **phantom schema**) |

**The load-bearing fix:** routes `health-clinical.observations.store` and `health-clinical.events.store` (routes/health-clinical.php) currently point at the **dead** `HealthClinical\HealthClinicalController`. Repoint them at the Domain stack (Step 1). Then retire the entire `App\Models\Clinical*` + `App\Services\HealthClinical\{ClinicalObservationService,ClinicalEventService,ProtocolService}` + dead `HealthClinicalController` methods + dead `health-clinical/Dashboard.tsx`.

**Enum canon:** `app/Domain/Clinical/Enums/{ObservationType,ClinicalEventType,ProtocolFrequency,BehaviourFunction}.php` — NOT the `App\Models\Clinical*::TYPES` constants.

### 1B. Read-only lenses — do NOT build second registers (§11)

| Surface | ✅ System of record to link OUT to | Correct URL / route (handoff is WRONG on these) |
|---|---|---|
| **Care Plans** lens | `Operations\CarePlanController` | `/operations/care-plans` (route `operations.care_plans.index`) — **NOT `/care-plans`**. The handoff repeatedly says `/care-plans`; it is mounted under `prefix('operations')`. Per-plan "View" → `operations.care_plans.show` or client profile `?tab=care_plans` (that Index page is itself a stats-only "coming soon" dashboard). |
| **Restraint register** lens | H&S `RestraintController` | `/health-safety/restraints` (route `health-safety.restraints.index`), gated `hazards.view`. |
| Care-plan model reads | `app/Models/CarePlan.php` (`scopeReviewDue`, `scopeActive`, `signOffs()`, `goals()`) | filter `whereIn('plan_type',['health_plan','behaviour_plan'])` |
| Restraint model reads | `app/Models/RestraintEvent.php` + `app/Models/BehaviourSupportPlan.php` | ⚠️ NO `organization_id` — scope the clinical lens query through `client.organization_id` to avoid a cross-tenant leak |

The clinical module currently has **zero** awareness of these tables (`ClinicalDashboardService` never imports them), so the *only* duplication risk is the implementer building a second editor. Lens reads ride existing permissions (`care_plans.viewAny` / `hazards.view`) — no new abilities.

### 1C. Shared chrome — every primitive already ships (do NOT rebuild)

| Design element | ✅ Canonical component + path |
|---|---|
| Wizard shell (rail + body + footer + progress + Esc/backdrop) | `WizardShell` — `resources/js/components/wizard/shell.tsx` (also `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`) |
| Wizard form fields | `resources/js/components/wizard/primitives.tsx` (`StepHead`, `SubHead`, `Field`, `Segmented`, `ChipMulti`, `TilePicker`, `InfoCard`, `SelectInput`, `Ring`) |
| Wizard LOGIC reference (gating / jump-to-error) | `resources/js/components/clients/add-client-dialog.tsx` (copy `validateStep`/`stepForError`/`StepCtx`/`submit` — but render via `WizardShell`, do NOT copy its inlined rail) |
| File upload | `resources/js/components/ui/file-dropzone.tsx` (`FileDropzone`, `StagedFileCard`, `AttachmentUploader`, `formatFileSize`) |
| Command-centre hero | `resources/js/pages/health-safety/components/hs-hero-kit.tsx` (`HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`, `HeroClusterTile`, `HeroSegmented`, `fmt`, `Tone`) — **NOT** the generic `PageHero`; the design's two-cluster + NZ-chip layout = hs-hero-kit. ⚠️ Do NOT reuse `HeroComplianceBadges` (H&S-specific labels, shared by both H&S heroes) — build a bespoke clinical chip row reusing only its `CHIP_CLASS`/`CHIP_ICON` token maps. |
| Row context menu | `ShiftContextMenu` via barrel `@/components/rostering` (`ShiftCtxItem`/`ShiftCtxState`) — ⚠️ NAME COLLISION: `pages/operations/shifts/components/shift-context-menu.tsx` and `pages/my-day/components/stream-context-menu.tsx` are **incompatible** different components. Import ONLY from `@/components/rostering`. |
| Sub-tab strip | `TabStrip` — `resources/js/components/rostering/tab-strip.tsx` (`RosterTabItem`={id,label,icon,tone,badge}) |
| Two-tier group registry (pattern to mirror) | `resources/js/pages/operations/clients/tabs/_groups.ts` (data only — copy the `CLIENT_TAB_GROUPS` + `groupForTab()` shape into a new `HC_TAB_GROUPS`; there is NO off-the-shelf group-pill widget) |
| Full-page composition exemplar | `resources/js/pages/incidents/index.tsx` (hero + TabStrip + `openRowCtx` + detail-over-list `router.get(only:['detail'])` + `IncidentReportDialog`) — copy wholesale |
| Detail/create modal exemplar | `resources/js/components/incidents/incident-detail-dialog.tsx` (read-only `ReviewCard`/`ReviewRow` + actions-route-out + `AttachmentUploader`) |
| Protocol form (reuse inside modal) | `resources/js/components/clinical/protocol-form.tsx` (already consumed by `protocols/Create.tsx`/`Edit.tsx`) |
| Pager (keep contract) | the `PaginatedData<T>` + `dangerouslySetInnerHTML` pager already in `resources/js/pages/health-clinical/observations.tsx` |

### 1D. Backend reuse precedents

| New thing to build | ✅ Mirror this exact precedent |
|---|---|
| `clinical_attachments` table + model + controller | `app/Models/SafeguardingAttachment.php` + `database/migrations/2026_06_18_000001_create_safeguarding_attachments.php` + `app/Http/Controllers/SafeguardingAttachmentController.php` + routes `routes/safeguarding.php:80-85` (freshest near-twin: sensitive flag + soft-delete + sequential single-file POST + sensitive-download gate). **Single `mime` column** (follow safeguarding, not incident's redundant `mime`+`mime_type`). Swap the single FK → `nullableMorphs('attachable')`. |
| New cross-client read methods | the `getEventRegister`/`getProtocolRegister` pattern in `ClinicalDashboardService` (when()-guarded filters → `paginate($perPage)->withQueryString()`; legacy-site fallback via `whereHas('client', site_id)`) |
| Behaviour analytics aggregation | `app/Services/Client/BehaviourPatternsService.php` (lift `functionBreakdown`/`intensityMix`/`topValues`) |
| Production permissions | `database/seeders/RbacSeeder.php` (the ONLY seeder in `DatabaseSeeder` → reaches the server) + mirror into `database/seeders/ClinicalPermissionsSeeder.php` (test-only, used by ~14 Feature tests) |

---

## 2. Backend work, ordered

> Convention: each item tags the **handoff §** it closes and flags **destructive migration**. Validation is **inline in controllers** (no FormRequest classes exist) — extend the controller rules, not a FormRequest.

### B0 — Repoint the broken write routes (closes the §1A landmine; no migration)
- Extract a shared store action (e.g. a `RecordsClinicalObservation`/`RecordsClinicalEvent` action or a thin module controller) from `Clinical\ClientClinicalController@store`/`@storeEvent` that takes an **optional client** (powers §8 "one wizard, two entry points").
- Repoint `health-clinical.observations.store` + `health-clinical.events.store` off `HealthClinical\HealthClinicalController` onto that Domain-backed action.
- **Closes:** §2.6 / §7 write path. Map notes: A-map gap "critical", B-map gap "critical", C-map gaps "critical".

### B1 — Structured vitals + NEWS2 (§3.1, the biggest item) — ⚠️ **migration (additive, non-destructive)**
- **Decision needed first (see Risks):** JSON-on-`clinical_observations` vs a `clinical_vital_signs` table. Audit recommends **extending the canonical `ClinicalObservation` + its service (compute-on-write)** so registers/trends read a stored score+band — do NOT add a parallel model.
- **Migration:** add `news2_score` (int, nullable) + `news2_band` (enum/string, nullable: Low/Low-med/Medium/High) columns to `clinical_observations` (additive). Reconcile vitals key names: existing JSON uses `respiration_rate` + `o2_saturation`; design/NEWS2 uses `respiratory_rate` + `spo2` — **pick one and migrate**; add `on_oxygen` (bool) + `consciousness` (ACVPU) which have NO representation today.
- **Service:** new `News2Scorer` (RCP NEWS2 thresholds incl. SpO₂ Scale-2 / on-oxygen modifier). Add an `Acvpu` enum.
- **Validation:** extend `ClinicalObservationService::validateDataForType()` / `validateVitals` to accept `consciousness` + `on_oxygen`; call `News2Scorer` on write; persist score+band.
- **Escalation hook:** when `news2_band ∈ {Medium,High}`, emit via existing `ClinicalSignalService` (add a `clinical_deterioration` emit from the observation path — do NOT add a new service); add a `Health & Clinical` group to `config/notification_events.php`.
- **Closes:** §3.1, §7.1 (NEWS2/ACVPU). Unblocks watchlist KPI, wizard live NEWS2, Trends NEWS2 chart.

### B2 — Event review / follow-up / escalate endpoints (§3.2; no migration — columns + permission already exist)
- `PATCH /health-clinical/events/{event}/review` → set `reviewed_at`+`reviewed_by`; gate **`clinical.events.review`** (⚠️ **already seeded** — handoff §5 wrongly calls it new; wire it, don't re-add).
- `PATCH /health-clinical/events/{event}/follow-up/complete` → set `followup_completed_at`+`followup_completed_by`.
- `POST /health-clinical/events/{event}/escalate` → notify on-call manager + clinical lead; gate **`clinical.events.escalate`** (NEW). Add `escalate()` to `ClinicalEventPolicy`. Hang escalation logic off `ClinicalEventService`, reusing its HS-link plumbing.
- Add `review()`/`completeFollowup()`/`escalate()` to `ClinicalEventService`. Use `->whereNumber('event')`.
- **Closes:** §3.2, §2.4, §7.2. Data layer (columns + reviewer/followupCompleter relations + register `review_status`/`follow_up_status` filters) is already complete.

### B3 — Add `witnesses` to the event validator (§7.2; no migration)
- Add `witnesses => ['nullable','array']` (+ `witnesses.* => exists:users,id`) to the canonical `storeEvent` validator. The model casts it to array and `ClinicalEventService::record()` already persists it — only the validator drops it.

### B4 — `clinical_attachments` polymorphic table + endpoints (§10) — ⚠️ **migration (additive)**
- **Migration:** mirror `create_safeguarding_attachments` but with `nullableMorphs('attachable')` (so `ClinicalEvent` + `BehaviourAbcEntry` share one table) + single `mime` + `is_sensitive` + optional `kind` enum (photo/document/body_map) + soft-deletes.
- **Model:** `ClinicalAttachment` with `morphTo() attachable()`. Add `attachments(): MorphMany` to `ClinicalEvent` + `BehaviourAbcEntry` (optionally `ClinicalObservation`).
- **Endpoints:** `POST/DELETE /health-clinical/events/{event}/attachments` (gate `clinical.events.record`) + `…/behaviour/{entry}/attachments` (gate **`clinical.behaviour.record`** — NEW). Validate `file` (`image/*,.pdf,.doc,.docx`, max 10 MB); read `is_sensitive` via `$request->boolean()` with a **non-strict `['nullable']`** rule (multipart sends `'1'`/`'0'`); gate sensitive download on a clinical permission.
- **Closes:** §10. Mirror `SafeguardingAttachmentController` store/download/destroy almost verbatim.

### B5 — Cross-client Behaviour (ABC) index (§3.4; no migration)
- `GET /health-clinical/behaviour` → `getBehaviourRegister()`/`getBehaviourRegisterStats()` on `ClinicalDashboardService` (mirror `getEventRegister`); filters `client_id` + `behaviour_function`; aggregates most-common-function + escalation/harm counts (lift from `BehaviourPatternsService`). Gate **`clinical.behaviour.viewAny`** (NEW). Use `BehaviourAbcEntry` (already has `site_id` + scopes). Writes still POST per-client to `clients.behaviour.abc.store` (reuse `clinical.events.record`, no change).
- **Closes:** §3.4, §2.5.

### B6 — Cross-client Health-Monitoring rollup (§3.3; no migration unless §8 unify chosen)
- Add `getMonitoringRollup()`/`getMonitoringStats()` to `ClinicalDashboardService` aggregating the four `Client{Fluid,Bowel,Seizure,Sleep}Entry` models by site + date range. ⚠️ none has `site_id` → join `client.site_id`; `ClientSleepEntry` keys on `slept_at` not `occurred_at`. Seizure `escalated` is precomputed → simple `where('escalated',true)`. Gate **`clinical.monitoring.viewAny`** (NEW).
- **Reconcile permissions** (see Risks): the four ChartControllers gate on `medications.view`/`medications.administer.record`, not `clinical.*`.
- **Closes:** §3.3, §2.3.

### B7 — Hero KPIs (§3.5; no migration)
- Extend `ClinicalDashboardService::getKpis()`: promote `events_unreviewed`/`events_pending_followup` from `getEventRegisterStats()`; add `clients_on_watch` (latest NEWS2 band ≥ Medium — depends on B1); add `restraint_register_current` (bool, from H&S restraints/PBS) + `nga_paerewa_certified` (bool, from governance) for the compliance chips; add clinical care-plan `review_due` + `awaiting_sign_off` counts (lens reads, §1B).
- **Closes:** §3.5.

### B8 — Assessments & Risk backend (§11.2, the only net-new register) — ⚠️ **migration (new table)**
- **Migration:** `clinical_risk_assessments` (client_id, site_id, tool enum [FRAT/Waterlow/Braden/MUST/IDDSI], inputs JSON, score, risk_band, next_review_at, reviewed_*, recorded_by, soft-deletes).
- **Services:** FRAT (falls), Waterlow/Braden (pressure), MUST (nutrition), IDDSI (dysphagia) scorers.
- Endpoints: index + store + detail; gate on a new `clinical.assessments.*` ability (or reuse `clinical.observations.record` per design — decide). Until shipped, the tab shows the existing per-domain assessments read-only behind an in-tab banner.
- **Closes:** §11.2. Lowest priority — design explicitly gates it behind a banner.

### B9 — Permissions + seeder (§5) — run `--force` on deploy
- Add to **`RbacSeeder.php`** (production) AND **`ClinicalPermissionsSeeder.php`** (tests), `group:'clinical'`, `module:'Health & Clinical'`, assign to clinical_lead/coordinator/provider_manager/admin:
  - `clinical.events.escalate`, `clinical.behaviour.viewAny`, `clinical.behaviour.record`, `clinical.monitoring.viewAny` (and `clinical.assessments.*` if B8 lands).
  - **DO NOT re-add `clinical.events.review`** (already present in both seeders + `ClinicalEventPolicy`).
- ⚠️ Permissions are **seeded, not migrated**, and deploys skip seeders → new perm-gated routes 403 on the server until `RbacSeeder` is run `--force` (per `reference_deploy_seeders`).

### B10 — `GET /clients/search?q=` (§9 cross-cutting) — no migration
- Debounced server search (id, name, preferred name, NHI, site) backing the shared client picker. Scope to caseload with an "all clients" toggle.
- **Closes:** §9 #1.

---

## 3. Frontend work, ordered

> Build the **shell first** (independently shippable, wraps existing pages as panels), then wizards, then enrich panels. Follow `pages/incidents/index.tsx` architecture verbatim.

### F1 — Hero + two-tier tab shell (the central FE task)
- New shell page (replaces the body of `resources/js/pages/health-clinical/index.tsx`; **keep the Inertia component name `health-clinical/index`** — `HealthClinicalTest.php`/`HealthClinicalDashboardTest.php` assert it, or update those assertions).
- **Hero:** compose `HeroShell` + `HeroStatusPill` ("Clinical command centre · synced just now") + `HeroMedallion({icon: HeartPulse})` + two `HeroCluster`s ("Compliance & coverage", "Clinical risk & events") of 4 `HeroClusterTile`s + `HeroSegmented` 7d/30d/90d + primary "Record observation" / ghost "Log clinical event" + a **bespoke clinical chip row** (Ngā Paerewa · deterioration watch · sign-off backlog · restraint register) reusing only the `CHIP_*` token maps.
- **Two-tier nav:** group pills (Monitor/Plan/Analyse) above a `TabStrip` whose `items` swap per group; mirror `_groups.ts` → new `HC_TAB_GROUPS` + `groupForTab()`; sync `?tab=` deep-link → activate the owning group on load. Group pill shows aggregate badge.
  - Monitor: Overview · Observations · Clinical Events · Health Monitoring
  - Plan: Care Plans · Protocols · Assessments & Risk
  - Analyse: Behaviour (incl. Restraint sub-view) · Trends

### F2 — The 9 sub-tab panels (which existing page becomes which)

| Sub-tab | Source → panel | Reuse / net-new |
|---|---|---|
| **Overview** | `health-clinical/index.tsx` body → panel | reuse KpiData/overdue/recent props; **add** NEWS2 deterioration-watch card (sparkline + band pill, depends B1) + "View all →" links to filtered registers |
| **Observations** | `health-clinical/observations.tsx` → panel | reuse register + pager; **add** NEWS2 score pill column (B1), ctx menu, toolbar rework (§9) |
| **Clinical Events** | `health-clinical/Events.tsx` → panel | rework table → **card list** (severity left-border); **add** ctx-menu review/sign-off/follow-up/escalate (B2), search toolbar |
| **Protocols** | `health-clinical/Protocols.tsx` → panel | reuse register; **add** search + row-click **detail modal** (with "Record observation now" → Obs wizard) + promote Create/Edit to modal (`protocol-form.tsx` inside Dialog) |
| **Health Monitoring** | net-new panel (data was profile-only) | new; reads B6 rollup; searchable client picker; reuse recharts conventions from `tabs/health-monitoring/index.tsx`; **seizure stays read from `ClientSeizureEntry`** |
| **Care Plans** | net-new **read-only lens** | new; reads B7/lens; links OUT to `/operations/care-plans`; reuse `RestraintController`-style badge helpers; NO create/edit here |
| **Assessments & Risk** | net-new (genuinely new) | new; behind in-tab banner until B8; row detail + "New assessment" modal |
| **Behaviour** | net-new module panel (ABC was per-client) | new; `Segmented` ABC ↔ Restraint; ABC = A·B·C three-column cards from B5; Restraint = **read-only lens** → `/health-safety/restraints` |
| **Trends** | `health-clinical/ClientTrends.tsx` → module panel | reuse recharts; **add** searchable client picker (replaces per-client route), NEWS2 14-day bar trend (B1), cross-module signal cards (§4); ⚠️ **de-hardcode** the hex line colours (`#059669` etc.) → `--status-*`/`--primary` tokens |

### F3 — Three record wizards sharing ONE lifted component (§8)
- Build each on `WizardShell` + primitives; copy the **gating LOGIC** from `add-client-dialog.tsx` (`validateStep`/`stepForError`/`StepCtx`).
- Each accepts an **optional `client` prop**: when supplied, skip/lock step 1 (read-only client chip in the rail) and seed `clientId`. Module page passes none (full searchable picker); client profile passes the current client — guarantees module/profile never drift.
- **Record Observation (4 steps):** Client&type (NHI search via B10) → type-aware Measurements (mirror `validateDataForType` exactly; vitals show **live NEWS2** via B1) → Context (`recorded_at` back-date, notes, `is_flagged` toggle, follow-up) → Review. Rail shows **live clinical card** (allergies/baseline vitals/active protocols/resus) from `ClinicalHealthSummaryService`. Hide Vitals/Pain types for users lacking `clinical.observations.recordClinical`. Seed `protocol_schedule_id` when opened from an overdue-protocol row.
- **Log Clinical Event (4 steps):** Client+type+severity → description/occurred-at/witnesses → immediate_action/outcome/follow-up + **Evidence dropzone** + cross-module note (falls/seizures/choking auto-link HS) → Review (with attachment count).
- **Record ABC (4 steps):** Context → A·B·C (+ tags/function/intensity[required]/duration; `BehaviourFunction::options()` feeds `TilePicker`) → Response (strategies/harm/escalation/follow-up/`linked_care_plan_id` + **Evidence dropzone**) → Review. POSTs per-client to `clients.behaviour.abc.store`.
- **Attachments wiring (§10 decision):** `AttachmentUploader` POSTs immediately, but new-record wizards have no record id yet → **two-phase**: submit the record, then upload; OR stage `File[]` with `FileDropzone`+`StagedFileCard` in form state and send with the create payload. (Backend table = B4.)

### F4 — Detail / Create modal shell
- Reuse `incident-detail-dialog.tsx` pattern on `WizardShell`: read-only `ReviewCard`/`ReviewRow` + action buttons routing to the system of record + `AttachmentUploader`. Powers Protocol detail/create, Care-Plans + Assessments read-only detail lenses.

### F5 — Context menus (every register row)
- `ShiftContextMenu` from `@/components/rostering`. `const [ctx,setCtx]=useState<ShiftCtxState|null>(null)`; on `onContextMenu` build `ShiftCtxItem[]` (View → detail; Record observation now → Obs wizard pre-bound; Record ABC; Review & sign-off / Mark follow-up complete / Escalate / Create H&S incident on events). Render `{ctx && <ShiftContextMenu ctx={ctx} onClose={()=>setCtx(null)} />}`.

### F6 — Client search, filters & pagination (§9)
- One shared debounced client-search/select component (backed by B10) used by wizard step 1, Trends/Monitoring pickers, and register client-filter dropdowns.
- Per-register toolbar rework: free-text search + filter chips → real query params + "N shown" + empty/skeleton states; keep the existing `PaginatedData` pager; persist filters in URL (`withQueryString` already server-side); default to caseload + "all clients" toggle.

---

## 4. Cross-module integration checklist

- [ ] **Governance query-param stability (§4) — DO NOT BREAK.** `ClinicalGovernanceAutomationService` deep-links into `/health-clinical/events?event_type={fall|skin_integrity|infection_sign}&date_from=…&date_to=…` (HCG-002/003/004). The redesign MUST keep: route name `health-clinical.events.index`, path `/health-clinical/events`, the `event_type` validator (`in:` ClinicalEventType cases), and `date_from`/`date_to` honoured. The three enum backing values in `ClinicalEventType.php` are load-bearing. **Add a regression test that the deep-link actually filters** (existing governance test asserts counts, not the link round-trip).
- [ ] **High-severity event → H&S incident auto-link.** `ClinicalEventService::record()` already auto-creates an `HsEvent` for fall/seizure/choking (`shouldLinkToHs()`/`hsEventCategory()`) and emits a Control Room signal (≥HIGH) via `ClinicalSignalService`. Surface this as the wizard step-3 cross-module note + an event ctx-menu "Create H&S incident" action. **Do not reinvent HS linking** — hang the escalate endpoint off this service.
- [ ] **Attachment copy on auto-link (§10.5).** When a high-severity event auto-creates an H&S incident, copy/reference its `clinical_attachments` to that incident so evidence isn't duplicated.
- [ ] **eMAR PRN ↔ behaviour (§4).** Trends signal card correlates `prn_administrations` (`pages/emar/PrnRecords`) with ABC escalations — joined read across PRN + `behaviour_abc_entries`. Last (depends on cross-module joins).
- [ ] **Catering/nutrition ↔ weight (§4).** Trends signal card links weight trend (already a `weight` observation) to reduced fluid intake → dietitian referral note.
- [ ] **Deterioration → My Day / notifications (§4).** Watchlist entries + overdue observations push to the nurse's My Day stream and notification fanout (via `ClinicalSignalService` + a new `Health & Clinical` group in `config/notification_events.php`).
- [ ] **Care Plans + Restraint lenses link out** (not editors) — §1B URLs.

---

## 5. Step-by-step build order (PROGRESS.md checklist)

Grouped into 8 independently shippable/testable steps. **NZ / web-only / need-to-know throughout.** Run `artisan test` change-scoped & non-parallel; if working in a junctioned-vendor worktree, **backend behaviour is exercised against the PARENT app** (merge then test in parent) and **permissions are seeded not migrated**.

### ✅ Step 1 — De-dup + repoint write path (foundation; no UI) — **DONE + VERIFIED**
- [x] Extracted shared validation/gating into `app/Http/Controllers/Clinical/Concerns/RecordsClinicalRecords.php` (trait; optional-client via resolved `Client`). Powers §8 one-wizard-two-entry-points.
- [x] Repointed `health-clinical.observations.store` + `health-clinical.events.store` → `Clinical\HealthClinicalDashboardController@storeObservation/@storeEvent` (off dead `HealthClinicalController`). Confirmed via `route:list`.
- [x] Added `witnesses` (`['nullable','array']` + `witnesses.* => ['string','max:255']`) to the canonical event validator (B3). NOTE: witnesses are free-text names (clinically flexible), not user ids.
- [x] Refactored `ClientClinicalController` to use the trait (now thin).
- [x] Retired dead stack: **deleted** `App\Models\Clinical{Observation,Event,Protocol,ProtocolSchedule}`, `App\Services\HealthClinical\{ClinicalObservationService,ClinicalEventService,ProtocolService}`, `health-clinical/Dashboard.tsx`; stripped `HealthClinicalController` to just `clientSummary` (swapped dead `TYPE_LABELS` → Domain enum labels). **Kept** `HealthClinical\HealthSummaryService` — removed its vestigial (unused) dead-service constructor deps first. Zero dangling refs confirmed (repo-wide FQN grep clean).
- [x] Tests: new `HealthClinicalModuleRecordingTest` (5/16) proves module store → canonical Domain table + timeline + witnesses (B3) + HS auto-link + client_id validation + permission gating. **Regression: 256 clinical tests / 1007 assertions green; all changed PHP lints clean.**
- **Ships:** module-level recording stops writing the phantom schema; one canonical write path. Commit: `f2ceea69` (rolls into next commit).

### ✅ Step 2 — Hero + two-tier tab shell (FE; wraps existing pages) — **DONE + VERIFIED**
- [x] Tab registry `resources/js/pages/health-clinical/lib/tab-groups.ts` (`HC_TABS`/`HC_GROUPS` Monitor/Plan/Analyse + `groupForTab`/`builtTabsForGroup`/`groupsWithBuiltTabs`). `href: null` = not-built (NOT rendered — no stubs; tabs flip on as steps land).
- [x] Shared shell `resources/js/pages/health-clinical/components/health-clinical-shell.tsx` — hero on `hs-hero-kit` (medallion/status pill/2 clusters/bespoke `ClinicalChips` row mirroring only the CHIP token maps) + two-tier `GroupPills` over `TabStrip`; tab nav = Inertia visit (keeps `/health-clinical/events` a real route for governance). Record buttons + period control are prop-gated (appear in Steps 3/4 — no dead controls now).
- [x] Refactored Overview `index.tsx` through the shell (kept component name `health-clinical/index`; dropped old PageHero + KPI grid). **tsc green (0 errors).** Commit: `08bfc1a8`.
- [x] Wrapped `observations`/`Events`/`Protocols` register pages through the shell (each keeps its filters/table/pager + a `RegisterStatStrip` of its own stats; Protocols' "New Protocol" moved to a register toolbar). Added `kpis` + `tab_counts` to all 3 controllers via new `ClinicalDashboardService::getTabCounts()` (Observations badge = overdue schedules, Clinical Events badge = unreviewed; reuses the kpis snapshot). **tsc + eslint clean.** Commit: `d50f064b`.
- [x] Tests: existing `HealthClinicalTest` (dashboard `health-clinical/index` + 3 register renders) green with the additive props.
- **Decision:** registers are SEPARATE routes rendering the shared shell (not one mega-page) — preserves the governance deep-link contract + heterogeneous panels; tab click = Inertia visit.
- **Deferred to later steps (no stubs now):** Trends tab (currently per-client → needs a module route, Step 8), Health Monitoring (Step 7), Care Plans (Step 7), Assessments (Step 8), Behaviour (Step 7); hero record buttons + period control (Steps 3–4).

### ✅ Step 3 — NEWS2 + structured vitals — **DONE + VERIFIED**
- [x] Migration (additive): `news2_score` (tinyint) + `news2_band` (string) + index on `clinical_observations`. **Per decision #2: KEPT existing data keys** (`respiration_rate`/`o2_saturation`); added `consciousness` (ACVPU) + `on_oxygen` + `spo2_scale` as NEW vitals data keys — **no rename, no historical backfill.**
- [x] `News2Scorer` — full RCP NEWS2 (resp rate, SpO₂ **Scale 1 + Scale 2**, air/oxygen, systolic, pulse, ACVPU, temp) + single-parameter red-flag → band. Enums `Acvpu`, `News2Band` (+advice/isOnWatch); `News2Result` DTO (score/band/redFlag/breakdown).
- [x] `validateVitals` extended (consciousness/on_oxygen/spo2_scale, optional); **compute-on-write** in `ClinicalObservationService::record()` (vitals only) → persists score+band; **deterioration emit** via new `ClinicalSignalService::emitForDeterioration()` (reuses existing `TYPE_DETERIORATION`) when band ≥ Medium.
- [x] `getKpis()` → `clients_on_watch`; new `getDeteriorationWatch()` (latest-per-client, score sparkline) → Overview **deterioration-watch card** (avatar/sparkline bars/band pill/→client profile). Hero "On watch" tile + deterioration chip now light up with real data.
- [x] Tests: `News2ScorerTest` 11/34 (normal, red-flag, Medium/High thresholds, both SpO₂ scales, on-oxygen, ACVPU, incomplete→null); `News2ObservationTest` 7 (persistence, non-vitals/incomplete→no score, emit decision Med/High vs Low, watchlist latest-only); `ClinicalObservationServiceTest` 22 still green (constructor change safe). **tsc + eslint + php-lint clean.** Commit: `6e9a94b2`.
- **Deferred (no stubs):** notification_events.php "Health & Clinical" group + My Day fanout → Step 8; register NEWS2 score-pill column → Step 6 (§9 register polish); hero period control → Step 4.
- **Ships:** deterioration watch (count + card + chip) live; NEWS2 stored for the wizard's live score (Step 4) + Trends (Step 8).

### ✅ Step 4 — Record wizards (3 modals) — **DONE (4a–4d); 4e §8 profile-entry DEFERRED**
> All three record modals are built on the Add-Client `WizardShell` chrome, feature-complete with premium evidence upload (Event + ABC). 4e (mount the wizards in the client-profile tabs) is deferred — the module wizards deliver the core; the profile already has its own recorders, and §8 unification touches the busy client profile. Revisit after the module IA is complete.
- [x] **4a backend foundations** (the wizards depend on these): `clinical_attachments` polymorphic table (`morphs`, single `mime`, `kind`, `is_sensitive`, soft-deletes — mirrors SafeguardingAttachment) + `ClinicalAttachment` model + `attachments()` MorphMany on `ClinicalEvent` + `BehaviourAbcEntry`; `ClinicalAttachmentService` (attach/attachMany). Create-time evidence wired into event store (module + profile) + ABC store (validated `attachments[]` files, ≤10MB, image/pdf/doc). `is_flagged`/`flagged_reason` added to obs trait validation + `ClinicalObservationService::record()` (sets `flagged_by`). New endpoints: `GET clients/search` (name/preferred/NHI, debounced picker) + `GET clients/{client}/clinical-card` (allergies/baseline vitals+NEWS2/active protocols for the rail; resus deferred — no data source). Commit: `85703cb2`.
- [x] **4b** Record Observation wizard — DONE. New shared pieces: `WizardShell` gains a `railExtra` slot; `lib/news2.ts` (TS port of the PHP scorer for live display); `record-wizard-shared.tsx` (`ClientPicker` debounced against `clients/search`, `ClientChip` §8 collapsed state, `ClinicalCardRail` fetching `clinical-card`); `record-observation-dialog.tsx` (4 steps: Client&type → type-aware Measurements w/ **live NEWS2 card** → Context [recorded_at back-date, notes, **flag-on-entry** toggle] → Review). Optional `client` prop (§8), `protocolScheduleId`, `canRecordClinical` (hides vitals/pain). **Shell now owns the wizard** + the hero "Record observation" button, gated client-side via shared `auth.can.clinical` (zero backend changes). tsc + eslint + vite build clean. Commit: `20173080`. (Obs-register ctx-menu pre-bound entry → Step 6 §9.)
- [x] **4c** Log Clinical Event wizard — DONE. `record-event-dialog.tsx`: 4 steps (Client+type+severity → Description/occurred-at/**witnesses chips** → Immediate action/outcome/follow-up + **premium evidence dropzone** [FileDropzone+StagedFileCard, staged File[] sent create-time via `forceFormData` → `clinical_attachments`] → Review w/ attachment count). HS auto-link note for fall/seizure/choking. Shell now owns + mounts it; hero "Log clinical event" button gated by `eventsRecord`. tsc + eslint clean. Commit: `6e62aac4`.
- [x] **4d** Record ABC wizard — DONE. `record-abc-dialog.tsx`: 4 steps (Context [client/occurred_at/setting/others] → A·B·C [antecedent/behaviour/consequence + behaviour-tag chips + **function tile picker** (5 PBS functions) + **intensity** required + duration] → Response & follow-up [strategies, harm+notes, escalated, follow-up, **evidence dropzone**] → Review). Posts `clients.behaviour.abc.store` (always resolves a client) w/ create-time attachments. Mounted in shell + 3rd hero action "Record ABC" (gated `eventsRecord`, which ABC reuses). tsc + eslint clean. Commit: `e4788ddf`. (Behaviour-tab header entry + optional `linked_care_plan` picker → Step 7.)
- [ ] **4e** §8 client-profile entry points (mount the lifted wizards passing the current client; replace bespoke inline forms where they overlap).
- **Tests:** 4a — module is_flagged persist, event attachments create-time, ABC attachments, client search (name+NHI), clinical card. **Dependency:** Steps 1, 3. **Ships:** inline recording from module + profile with feature-complete premium evidence upload.

### ✅ Step 5 — Event review/follow-up/escalate + context menus (B2) — **DONE + VERIFIED**
- [x] Endpoints `PATCH events/{event}/review`, `PATCH events/{event}/follow-up/complete`, `POST events/{event}/escalate` on `HealthClinicalDashboardController`, route-middleware gated. `ClinicalEventService::review/completeFollowup/escalate` (+ `recordActionTimeline`); escalate raises a forced HIGH Control Room signal via new `ClinicalSignalService::emitForEscalation` (distinct idempotency key). NEW perm `clinical.events.escalate` added to **both** RbacSeeder + ClinicalPermissionsSeeder (clinical_lead/coordinator/provider_manager). `clinical.events.review` already existed.
- [x] `eventsReview`/`eventsEscalate` added to shared `auth.can.clinical` (HandleInertiaRequests) for client-side gating.
- [x] Events register rework: table → **card list** (severity left-border accent, icon tile, type+severity pill, Needs-sign-off/Reviewed + Follow-up-due badges, client·site, description, when+reporter) + **right-click `ShiftContextMenu`** (View client / Review & sign off / Mark follow-up complete / Escalate — each gated). Kept the full filter card + pager.
- [x] Tests: `ClinicalEventWorkflowTest` 5/12 (review sets reviewed_*; follow-up sets completed_*; escalate emits signal + timeline; review/escalate forbidden without permission). tsc + eslint clean. Commit: `bfb10c9f`.
- **Deferred (no stubs):** "Create H&S incident" ctx action (the auto-link already covers fall/seizure/choking; manual link → later); on-call-specific notification fanout → Step 8 (Control Room signal is the escalation surface now).

### ☐ Step 6 — Attachments (B4) + register §9 polish
- [ ] `clinical_attachments` migration (polymorphic) + `ClinicalAttachment` + `attachments()` on Event/ABC; store/download/destroy endpoints (gate `clinical.events.record`/`clinical.behaviour.record`); permission `clinical.behaviour.record` (B9 partial).
- [ ] Two-phase (or staged) upload in Event + ABC wizards; copy attachments to auto-linked HS incident.
- [ ] `GET /clients/search?q=` (B10) + shared client picker; toolbar/search/pagination/empty-state rework across all four registers; URL filter persistence; caseload default.
- [ ] Tests: sensitive-download gate; multipart `is_sensitive` boolean; search/pagination. **Dependency:** Steps 2, 4. **Ships:** evidence capture + scalable client selection.

### ✅ Step 7 — Behaviour index + Health-Monitoring rollup + lenses (B5, B6, §1B) — **DONE + VERIFIED**
- [x] **7a Behaviour tab** — `GET /health-clinical/behaviour` (perm `clinical.behaviour.viewAny`, NEW — added to both seeders for team_lead/clinical_lead/coordinator/provider_manager) + `getBehaviourRegister`/`getBehaviourRegisterStats`/`getBehaviourFilterOptions` on ClinicalDashboardService. Panel `Behaviour.tsx`: ABC entries as **A·B·C three-column cards** (client/site/function pill/intensity/harm/escalated/follow-up flags) + stat strip (escalated/harm counts) + filters + pager. `behaviour` tab flipped on in `HC_TABS` → activates the **Analyse group**. Record ABC = the hero button (already mounted on this tab). Test `HealthClinicalBehaviourTest` (2). tsc + eslint + php-lint clean. Commit: `d7771f68`.
- [x] **7b lenses** — DONE. **Care Plans** lens = new tab (`GET /health-clinical/care-plans`, gated `clinical.dashboard`): `getCarePlanLens($orgId)` (org-scoped via `CarePlan.organization_id`; active plans by next_review, overdue/unsigned counts) → `CarePlans.tsx` (banner + "Open Care Plans module →" + plan table, links to `/operations/care-plans`). **Restraint** lens = sub-view of the **Behaviour** tab (segmented ABC ↔ Restraint): `getRestraintLens($orgId)` (scoped via `client.organization_id` — RestraintEvent has no org_id, **no cross-tenant leak**) added to `behaviour()`; `Behaviour.tsx` gets the toggle + restraint table + "Open Restraint register →" (`/health-safety/restraints`). Both READ-ONLY, link out, **no new register/permission**. Test `HealthClinicalLensTest` (3, incl. org-scoping isolation). tsc + eslint + php-lint clean. Commit: `__STEP7B_SHA__`. (getKpis restraint/nga_paerewa chip data → minor follow-up; chips show sensible defaults now.)
- [x] **7c Health Monitoring** — DONE. `getMonitoringRollup($orgId, $filters)` aggregates the four per-client stores (`ClientFluid/Bowel/Seizure/SleepEntry`) — **all carry `organization_id`** so org-scoping is direct (no client join needed); sleep keys on `slept_at`, seizure `escalated` precomputed (decision #3: read both stores, don't migrate). `GET /health-clinical/health-monitoring` (perm `clinical.monitoring.viewAny`, NEW — both seeders). `HealthMonitoring.tsx`: info banner + client filter + 4 stat cards (fluid/bowel/seizures+escalated/sleep-avg) + recent-entry lists. `health_monitoring` tab flipped on → **Monitor group complete**. Test `HealthClinicalMonitoringTest` (2). (Per decision #4 existing capture perms unchanged; only the read rollup is gated.)
- **Dependency:** Steps 2, 5. **Ships:** Behaviour/Health-Monitoring/Care-Plans/Restraint tabs. **Commit (7b+7c): `__STEP7BC_SHA__`.** 8 of 9 tabs live (only Assessments → Step 8).

### ☐ Step 8 — Assessments & Risk + cross-module Trends signals (B8, §4)
- [ ] `clinical_risk_assessments` migration + FRAT/Waterlow-Braden/MUST/IDDSI scorers + index/store/detail; Assessments tab (banner until live).
- [ ] Trends cross-module signal cards: PRN↔behaviour join, weight↔nutrition, H&S fall; My Day/notification fanout for watchlist + overdue.
- [ ] Governance deep-link regression test (§4).
- [ ] Final: tsc 0 + eslint + pint-clean new files + change-scoped `artisan test`; merge → deploy → run `RbacSeeder --force` → Chrome-verify on .com (all 9 tabs/hero/wizards/ctx menus/lenses, 0 app console errors). **Dependency:** all prior. **Ships:** module complete.

---

## 6. Risks & open questions

**Decisions needed before coding the relevant step:**

1. **NEWS2 storage shape (Step 3, blocks the most).** JSON columns on `clinical_observations` vs a `clinical_vital_signs` table. Audit strongly recommends extending the canonical observation + service (compute-on-write). **Recommendation: JSON+derived columns on `clinical_observations`.** Decide before B1.
2. **Vitals key migration (Step 3) — ⚠️ touches stored JSON.** Existing observations store `respiration_rate`/`o2_saturation`; design/NEWS2 wants `respiratory_rate`/`spo2`. Renaming requires a **data-backfill migration over existing `data` JSON** (the closest thing to destructive here — it rewrites historical rows). Alternative: keep existing keys and map in the scorer (no data migration). Decide.
3. **§8 health-monitoring unification — possible data loss.** Routing fluid/bowel/sleep capture onto `clinical_observations` **loses fields**: clinical `fluid_intake` has no in/out **direction** (the existing fluid chart plots intake-vs-output) and clinical `sleep` has no `hours_slept`/date. Options: (a) read both stores side-by-side, or (b) migrate Client*Entry → clinical_observations and **extend** the data schema (add direction/hours_slept). **Recommendation: (a) for v1** (rollup reads both; keep existing capture), defer migration. **Seizure stays an event** (`ClientSeizureEntry`/Log-Event), not an observation — deliberate asymmetry.
4. **Health-monitoring permission reconciliation (Step 7).** The four ChartControllers gate on `medications.view`/`medications.administer.record`; a clinical rollup gated by `clinical.monitoring.viewAny` diverges from the write path. Decide whether unifying capture onto `clinical.observations.store` changes who can record.
5. **Assessments permission (Step 8).** New `clinical.assessments.*` vs reuse `clinical.observations.record` (design implies the latter for ABC-style reuse). Decide.

**Hard constraints / traps (not decisions, but must-not-break):**

6. **Governance deep-links (§4)** — route name/path/`event_type`/`date_from`/`date_to` are a frozen contract; the three enum values are load-bearing. Add the round-trip regression test.
7. **Handoff URL errors** — Care Plans is `/operations/care-plans` (NOT `/care-plans`); Restraints is `/health-safety/restraints`. Any link using `/care-plans` will 404.
8. **Restraint/PBS cross-tenant leak** — `RestraintEvent`/`BehaviourSupportPlan` have no `organization_id` and `RestraintController` doesn't scope; the clinical lens MUST scope via `client.organization_id`.
9. **Permissions seeded not migrated** — `RbacSeeder` is the only server path; `ClinicalPermissionsSeeder` is test-only; run `--force` on deploy or new routes 403. `clinical.events.review` already exists — don't re-add.
10. **Two-seeder `module` string divergence** — RbacSeeder uses `'Clinical'` for some existing rows, `ClinicalPermissionsSeeder` uses `'Health & Clinical'`; put NEW rows on `'Health & Clinical'` but don't "fix" the existing inconsistency (churn).
11. **Component name collisions** — import `ShiftContextMenu` ONLY from `@/components/rostering`; don't reuse `HeroComplianceBadges` (shared H&S, build a bespoke clinical chip row); build wizards on `WizardShell` not the inlined add-client rail.
12. **Page-name FS collision (Windows)** — `health-clinical/observations` (lowercase, live) vs `health-clinical/Observations` (capital, dead) and `Events`; consolidate when refactoring to panels.
13. **`AttachmentUploader` immediate-POST vs new-record wizards** — record doesn't exist mid-wizard; use staged `FileDropzone` or two-phase submit (no live endpoint to POST to until after create).
14. **Worktree gotchas** (if used): junctioned-vendor → PHP tests autoload the PARENT app (unmerged worktree `app/` not exercised — verify backend by merging then testing in parent); migrations + frontend DO use the worktree; `artisan test` with `cwd=worktree`; don't `--parallel` (per-worker DBs unmigrated → false failures); Herd `php.bat` mangles args (use `php84\php.exe` direct).

---

**Tracker file suggestion:** save this verbatim as `docs/health-clinical-redesign/PROGRESS.md`. Per-step plans expand under each Step heading as work begins.

**Key paths cited (canonical reuse):** `app/Domain/Clinical/Services/ClinicalDashboardService.php` · `app/Domain/Clinical/Services/ClinicalObservationService.php` · `app/Http/Controllers/Clinical/ClientClinicalController.php` · `resources/js/components/wizard/shell.tsx` · `resources/js/pages/health-safety/components/hs-hero-kit.tsx` · `resources/js/components/rostering/{tab-strip,shift-context-menu}.tsx` · `resources/js/pages/incidents/index.tsx` · `resources/js/components/ui/file-dropzone.tsx` · `app/Models/SafeguardingAttachment.php` · `database/seeders/RbacSeeder.php` · `app/Domain/Governance/Services/ClinicalGovernanceAutomationService.php`.
