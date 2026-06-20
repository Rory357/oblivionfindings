# Chemical Register (`/health-safety/substances`) — redesign tracker

Self-paced `/loop`. Rebuild the **Hazardous Substances / Chemical Register** module to the H&S
gold standard (twin of Incidents / Safeguarding / Fleet / Hazards): hero + tabs + register rows +
detail-as-modal + add-client-parity wizard; premium SDS upload; site "Chemicals stored here" panel;
close real cross-module gaps. NZ / web-only / modal-first. Worktree `recursing-benz-e32c0f`.

Design drop + spec: `C:\Users\steph\Downloads\SubstancePage_extracted\design_handoff_chemical_register\`
(`SUBSTANCES_REDESIGN_HANDOVER.md` is authoritative). Audited by 6-agent workflow 2026-06-20.

---

## §0 — LOCKED DECISIONS

1. **It already exists — REPOINT, don't recreate.** Controller `HazardousSubstanceController`,
   models (`HazardousSubstance` org-level + `SafetyDataSheet` + `SubstanceStorageLocation` +
   `SubstanceExposureRecord`), routes `routes/health-safety.php:175-207` (prefix `substances.`),
   perms `hazards.view|manage|create`. **NO new permissions** → no `*PermissionsSeeder --force` on deploy.
2. **Twins to clone:** index → `resources/js/pages/incidents/index.tsx`; detail-modal (open-at-pane) →
   `resources/js/components/safeguarding/concern-dialog.tsx`; create/edit wizard →
   `resources/js/components/incidents/incident-report-dialog.tsx` (bespoke `WizardShell`, the
   add-client chrome). Controller `index()` shape → `IncidentController::index`.
3. **Wizard = bespoke `SubstanceWizardDialog` on `@/components/wizard/shell` (WizardShell)** — NOT the
   generic config-driven `HsFormWizard`. Matches add-client 1:1 (stepper rail + completeness meter +
   progress bar + review + success pane + `stepForError`), supports **create + edit + Save-and-add-another
   + success-pane deep-links** (Add SDS / Add storage / View substance / Add another). The gold-standard
   create wizards (Incidents/Safeguarding/Fleet) are all bespoke WizardShell — HsFormWizard is only the
   dashboard quick-add. Reuse shared option sets (`PHYSICAL_FORM`, `HAZARD_CLASSES`) — export from
   `wizard-configs.tsx` rather than redefining. **ONE instance** shared by register Add button +
   report-launcher `substance` tile (special-case launcher `key==='substance'` → SubstanceWizardDialog).
4. **Premium SDS upload = reuse `@/components/ui/file-dropzone.tsx`** (`FileDropzone` + `StagedFileCard`,
   `multiple={false}` into a `useForm` `file` field — the Worker-Participation precedent). Do NOT build a
   new uploader, do NOT use bare `AttachmentUploader` (it can't carry version/issue_date metadata). ADD:
   client-side size (≤10 MB) + type (pdf/doc/docx) guard in `onFiles` (borrow `GovernanceAttachmentsPanel`
   pattern) + expose the `review_date` field the controller already accepts (`after:issue_date`).
5. **3-step wizard rail** (Substance / Controls / Review) per the design. Capture the FULL field set
   (the "dead" fields `hsno_approval, signal_word, hazard_statements[], precautionary_statements[],
   ghs_pictograms[], firefighting_measures, exposure_limit_type, exposure_limit_value, requires_tracking`
   are already `$fillable`/`$casts`) distributed across Substance (identity/classification/GHS) + Controls
   (PPE/storage/handling/first-aid/spill/firefighting/exposure-limits/tracking). **Extend `store()`/`update()`
   validation** to accept them (handover §3.3 DECISION). No 4th step.
6. **Status enum = `active / inactive / removed`** (handover §3.4). Column is plain string + controller
   `in:` validation (codebase convention) — **no DB enum migration**. `store()` defaults `active`. Tabs
   surface All · Active · Controlled · SDS expiring · SDS missing · Inactive.
7. **SDS-expiry signal (NEW)** lives on `SafetyDataSheet` (accessor `sds_state` + scopes) so ONE source
   feeds register hero/tabs, `analytics.tsx` (replace `sdsExpiring={0}`), and the site panel. States:
   `missing` (no `status='current'` row) · `expired` (`review_date < today`) · `expiring`
   (`review_date <= today+30d`) · `current`. Dashboard already queries `review_date<=horizon` in
   `HsDashboardService::expiringFeed` (`:262-303`) — mirror it; do not duplicate the horizon logic.
8. **Lifecycle = POST/PATCH `/{substance}/status`** (Option A; twin `FleetAssets/IncidentController::updateStatus`)
   under `hazards.manage`, optional reason, `flash.error`-guard (`onSuccessGuard`). Reached from detail
   "Mark inactive" pane + row right-click `initialAction='deactivate'`.
9. **Action modals are panes INSIDE the detail dialog** (Add SDS · Add storage · Record exposure ·
   Deactivate), reached from BOTH the Options footer AND row right-click via `initialAction` +
   `key={detail.id}` re-mount (Safeguarding pattern). Every pane: `useForm`, `preserveScroll`, treat
   302+`flash.error` as "stay open".
10. **Retire** `create.tsx` (→ `create()` redirects to `index?new=1`, opens wizard) and `show.tsx`
    (→ thin shell rendering the same detail payload as "Open full page" deep-link, mirror
    `IncidentController::show`). en-GB → **en-NZ** sweep (`show.tsx:265,387` + all new code; `12 Aug 2024`).
11. **Cross-module (real gaps to close):** (a) `HsAnalyticsService::build()` add real `sds_expiring` →
    `analytics.tsx:716`; (b) site "Chemicals stored here" via `HsModuleSummaryService::forSite` +
    `SiteController::show` prop → `sites/show.tsx` Hazards tab (`:2261-2295`); (c) exposure→WorkSafe:
    observer/`storeExposureRecord` must pass `worksafe_notifiable` into `HsEventService::recordEvent`
    (currently always `false`) + optionally set `related_incident_id` when reported.
12. **Semantic tokens only** (no raw hex/oklch/`bg-amber-*`/`border-l-*`); app-primary gradient (no site
    brand tint); reuse hs-hero-kit / register-row-kit / workflow-ribbon / rostering kits — never fork.
    Sanctioned `eslint-disable no-restricted-syntax` only for on-dark native buttons (copy kit comments).

### Naming (standardise)
Surface = **"Chemical register"**; record noun = **"hazardous substance"**. Fix `wizard-configs.tsx:283`
`railSub` casing. Sidebar/index/show/create breadcrumbs → one product name. Regs framing:
"Hazardous Substances Regulations 2017", HSNO classification, SDS (never "MSDS"), WorkSafe-notifiable, WES limits.

---

## §1 — STEP PLAN

- [x] **Step 0 — Worktree workbench + grounding.** ✅ node_modules + vendor junctioned to parent; .env
      copied; Wayfinder TS (`resources/js/{actions,routes}`) robocopied (generated/gitignored). **Baseline
      `tsc --noEmit` = 0** (clean). Twins use string URLs (no Wayfinder imports) → new code does too.
- [x] **Step 1 — Backend foundation.** ✅ DONE (php -l + pint clean).
      • `SafetyDataSheet`: `REVIEW_HORIZON_DAYS=30`, `scopeExpiringWithin`, `state` accessor
        (superseded/current/expiring/expired).
      • `HazardousSubstance`: `currentSds` hasOne, `sds_state` accessor (missing/current/expiring/expired),
        `scopeSdsMissing`, `scopeSdsExpiring`; `status_reason` fillable.
      • **Factories**: enhanced `HazardousSubstanceFactory` (+`controlled()`/`inactive()`), new
        `SafetyDataSheetFactory` (+`expiring()`/`expired()`/`superseded()`), `SubstanceStorageLocationFactory`
        (+`nonCompliant()`), `SubstanceExposureRecordFactory` (+`requiringMedicalAttention()`).
      • Controller: shared `substanceRules(creating)` → `store()`/`update()` accept the FULL field set
        (hsno_approval/signal_word/hazard_statements/precautionary_statements/ghs_pictograms[]/
        firefighting_measures/exposure_limit_*/requires_tracking). `store()`/`update()` flash
        `created_substance_id` on `stay`. Cleaned dual-field cruft: `storeSds` (canonical `file`+
        `supplier_name`+`supplier_contact`, supersede), `storeStorageLocation` (+`container_type`,
        `last_audit_date`), `storeExposureRecord` (canonical + `medical_outcome` + `related_incident_id`).
      • **NEW** `updateStatus()` (active/inactive/removed; reason required + persisted for deactivation) +
        `PATCH /substances/{substance}/status` route (`hazards.manage`) + migration
        `2026_06_20_130000_add_status_reason_to_hazardous_substances` (nullable `status_reason`, additive).
      • ⚠️ Transient: legacy `show.tsx` child forms post old field names → will 422/drop until retired in
        Step 7. Harmless (unmerged WIP, being replaced). exposure→WorkSafe observer wiring deferred → Step 9.
- [x] **Step 2 — Controller `index()` rebuild.** ✅ DONE (php -l + pint clean). Returns `filters, tab,
      tabCounts{all,active,controlled,sds_expiring,sds_missing,inactive}, rows(paginate 20, `through`
      projection incl. `sds_state` via eager `currentSds`, per-row `can`), hero{live,attention},
      badges{worksafe_awaiting,sds_to_action,nga_paerewa_certified}, sites, can{create,manage}, openWizard,
      detail` (lazy `buildSubstanceDetail`, only when `?substance=`). Server-side filters
      (q/site_id/physical_form/status/controlled/sds_state/period) + `applyTab` matcher; `boolFilter`
      tri-state replaces `parseControlledFilter`. `create()`→`redirect index?new=1`. `buildSubstanceDetail`
      returns full identity+controls+children(sds/storage/exposure)+counts+can+staff payload.
      ⚠️ Frontend index.tsx still legacy → runtime-broken until Step 3 (tsc unaffected; unmerged WIP).
- [x] **Step 3 — Index page rebuild** ✅ DONE (`substances/index.tsx`; tsc 0 / eslint 0, vite build in
      flight). Replaced legacy PageHero page with hs-hero-kit hero (medallion + status pill + Add CTA +
      2 clusters Live/Needs-attention + 3 NZ compliance badges) + WorkflowRibbon(resolve) + hero-footer
      filter bar (Period/Site/Form/SDS/Type + search + Clear) + 6-tab TabStrip + register table
      (risk dot · controlled flag · HSNO · form · GHS mini-chips · SDS FlagBadge · storage · status) with
      left-click→detail, right-click→ctx-menu (gated, open-at-pane via `initialAction`), keyboard rows +
      LaravelPagination. URL-driven detail (`?substance=`+`only:['detail']` + `key={detail.id}` remount).
      Mounts `SubstanceDetailDialog` + `SubstanceWizardDialog` once; Add button + `?new=1` + wizard
      `onOpenSubstance` + detail `onEdit` all wired. **Module now renders end-to-end.**
- [x] **Step 4 — `SubstanceDetailDialog`** ✅ DONE (`components/health-safety/substance-detail-dialog.tsx`;
      tsc 0 / eslint 0). WizardShell + rail Overview / Safety & handling / SDS / Storage / Exposures /
      History (live counts); gated Options footer (Open full page · Edit→`onEdit` callback · Add SDS ·
      Add storage · Record exposure · Mark inactive / Reactivate); `initialSection`/`initialAction`;
      status+controlled+SDS chips in footerStart; GHS pictogram chips, quantity-held-vs-max bars,
      labelled/segregated compliance chips, en-NZ dates. Exports `SubstanceDetail` + child types.
- [x] **Step 5 — Action panes** ✅ DONE (in the detail dialog). **Add SDS = premium `FileDropzone` +
      `StagedFileCard` (multiple=false) + client-side 10 MB/pdf-doc-docx guard + version/issue/review/
      supplier/contact → supersedes**; Add storage (site/location/qty/max/unit/container/labelled/
      segregation/last-audit/notes); Record exposure (worker/site/datetime/route/duration/circumstances/
      symptoms/first-aid/medical+outcome/incident); Deactivate (inactive|removed + required reason →
      `PATCH /status`). All panes use `PaneShell` + `onSuccessGuard` (302+flash.error = stay open).
      Also created `substances/constants.ts` (option sets · GHS meta · SDS-state meta · risk-tone).
- [x] **Step 6 — `SubstanceWizardDialog`** ✅ DONE (`components/health-safety/substance-wizard-dialog.tsx`;
      tsc 0 / eslint 0). Add-client parity on WizardShell: 3-step rail (Substance / Controls / Review) +
      completeness meter + progress bar; FULL field set incl. GHS pictogram picker + hazard-class chips +
      signal-word + H/P statements + WES limits (decision §5); per-step + all-step validation with
      `stepForError` jump; create + edit modes; **Save & add another**; success pane with deep-links
      (Add SDS / Add storage / View substance / Add another) via `onOpenSubstance`. Posts `stay:true` →
      reads `created_substance_id` flash. STILL TODO: wire register Add button + report-launcher tile to
      this ONE instance (Step 3 + Step 9).
- [x] **Step 7 — Retire create.tsx / show.tsx; en-NZ; naming** ✅ DONE (php -l + pint + tsc 0). `show()` →
      `redirect index?substance={id}` (deep-link fallback, mirrors Safeguarding); `create()` already
      →`index?new=1`. Deleted `show.tsx` + `create.tsx` (no app-code refs; only auto-gen Wayfinder TS,
      regenerates on deploy). Removed now-unused `canManageEntries`. en-NZ throughout (new components use
      `formatDate`/`formatDateTime`); breadcrumb/title/Head = "Chemical register". (wizard-configs railSub
      casing + launcher unification → Step 9.)
- [x] **Step 8 — Site "Chemicals stored here"** ✅ DONE (php -l clean, tsc 0, eslint 0). New
      `HsModuleSummaryService::chemicalsStoredForSite($siteId)` → `{rows, summary{count,controlled,
      sds_to_action,segregation_gaps}}` (reuses `sds_state` via eager `currentSds`); eager `chemicalsStored`
      prop on `SiteController::show` (next to `drillsSummary`). New `components/health-safety/
      site-chemicals-panel.tsx` (`SiteChemicalsPanel`): compliance-snapshot strip + table (substance +
      controlled · location + last audit · qty-held-vs-max bar · container · SDS FlagBadge · labelled/
      segregated chips), rows deep-link `router.visit(?substance=)`, "Open chemical register →" CTA,
      read-mostly (no duplicate storage form — managed from the register). Wired into `sites/show.tsx`
      Hazards tab (import + Props type + destructure default + mount). ⚠️ didn't pint shared
      `HsModuleSummaryService`/`SiteController` (pre-existing pint debt; my additions match style).
      Optional polish deferred: site-hero "Chemicals stored"/"SDS to action" stat cards (per site.png).
- [x] **Step 9 — Cross-module** ✅ DONE (php -l + pint + tsc 0 + eslint 0). **9A Exposure→WorkSafe** (the
      real gap): new `medical_treatment` column (none→first_aid→medical→hospitalisation→death; migration
      `2026_06_20_140000`) + fillable + factory states; `storeExposureRecord` validates it & derives
      `medical_attention_sought`; **observer injects `NotifiableEventClassifier`** → sets
      `worksafe_notifiable` (HSWA ss.23–25: hospitalisation/death/critical) + treatment-driven severity;
      exposure pane swaps the bool toggle for a treatment SelectInput (+ conditional medical-outcome);
      detail projection + ExposuresSection surface the level (critical/warning badge). **9B Analytics**:
      `HsAnalyticsService::sdsExpiringCount` (site-scoped, reuses `SafetyDataSheet::expiringWithin(30)`) →
      `build()` `sds_expiring` → `AnalyticsProps` → `analytics.tsx:716` (`sdsExpiring={sds_expiring}`,
      replaced hardcoded 0). **9C Launcher unification**: `dashboard.tsx` mounts `SubstanceWizardDialog`
      for `activeWizard==='substance'` (excluded from the generic HsFormWizard) → register Add button +
      launcher tile now the ONE wizard; `onOpenSubstance`→`router.visit(?substance=)`.
- [x] **Step 10 — Tests + lint + typecheck + build** ✅ DONE (php-l + pint clean; final build in flight).
      `HazardousSubstanceControllerTest` (17 tests: gold-standard index payload, lazy detail, SDS-state
      missing/expiring, controlled tab, **full-field store**, stay→flash, create→redirect, support_worker
      403, status-requires-reason + sets reason, **SDS supersede**, storage container/audit, exposure
      derives medical_attention, **observer notifiable for hospitalisation / not for medical**, show→redirect).
      `SubstanceCrossModuleTest` (2: analytics `sds_expiring` count; site chemicals summary compliance).
      ⚠️ run **post-merge** (junctioned worktree autoloads parent `App\`); frontend fully build/tsc/eslint-verified.

Verification caveat: junctioned-vendor worktree → PHP tests autoload PARENT app (see
[[reference_worktree_junction_tests_load_parent_app]]); full backend test pass happens in parent
post-merge. Frontend tsc/eslint/build DO use the worktree. Merge/deploy/Chrome-verify are **user-gated**.

---

## §4 — ADVERSARIAL REVIEW (2026-06-20, workflow `wf_97591280-8ec`, 15 agents)

4-dimension review (security/backend/frontend/fidelity), each finding verified → **11 confirmed, 0 rejected**.
Security clean (canDo throughout, IDOR guards present, semantic tokens only — pre-checked + agent-confirmed).

**FIXED (backend cluster, php-l + pint clean):**
- ✅ #1 (HIGH) `sub-hero-site-scope-mix` — hero mixed org-wide + site-scoped counts under a site filter.
  Rewrote hero: one `$siteScope` base; all tiles + badges site-scoped & internally consistent
  (active = sds_current+expiring+missing); no longer pulls from `$tabCounts`.
- ✅ #2 (MED) `sub-sds-tab-status-asymmetry` — `sds_expiring` tab was `status!=removed`, `sds_missing` was
  `active`. Both now `status='active'` (symmetric + aligns with hero).
- ✅ #3 (MED) `sub-current-filter-includes-expired` — 'Current' SDS filter matched expired sheets (status
  col ≠ derived state). Added `HazardousSubstance::scopeSdsCurrent` (has current AND not expiring/overdue).
- ✅ #7 (LOW) `sub-controlled-filter-echo-zero` — echo `controlled` filter now derived from normalised
  tri-state (survives `?controlled=0`/garbage), pill stays in sync.
- ✅ #10 (LOW) `sub-stay-flag-dead-redirect-branch` — wizard always sends `stay`; removed the dead
  non-stay redirect branches in `store()`/`update()` (always `back()->with('created_substance_id')`).
- ✅ #9 (LOW) `sub-search-defaultvalue-stale` — added `key={filters.q}` so the uncontrolled search input
  re-syncs after Clear.

**FIXED (frontend fidelity, tsc 0 / eslint 0):**
- ✅ #4 (MED) `fidelity-hero-board-reports-missing` — ported the gov-gated "Board reports" hero popover
  (`usePage<SharedData>().props.auth.can.governance.view` + `BOARD_REPORTS`), beside the Add CTA.
- ✅ #5 (MED) `fidelity-hero-right-click-missing` — wrapped HeroShell in `onContextMenu={openHeroCtx}` →
  ShiftContextMenu (Add substance / SDS expiring→tab / SDS missing→tab / Board reports→ when gated).
- ✅ #6 (MED) `fidelity-site-panel-add-storage-cta-missing` — extracted shared `StorageLocationFields`
  (used by detail `AddStoragePane` + new site dialog — NO form duplication); new `SiteAddStorageDialog`
  (substance picker + site fixed → `/storage-locations`); "Add storage here" CTA on the site panel gated on
  `chemicalsStored.can_add`; service returns active `substances`; SiteController merges `can_add`.
- ✅ #8 (LOW) `sub-dashboard-launcher-drops-action` — controller returns validated `initialAction`; index
  seeds `pendingOpen` from it; dashboard `onOpenSubstance` threads `&action=` → launcher "Add SDS" deep-link
  now opens the pane.
- ℹ️ #11 (LOW) `fidelity-site-tab-label-hazards-not-hs` — **NO CHANGE** (sign-off nit): site tab is the
  pre-existing shared SiteHazards "Hazards" tab; renaming would re-label another module + risk
  `?tab=hazards` deep-links. Design mock's tab strip is illustrative (invents "Rooms", omits ~10 real tabs).

**→ ALL 11 review findings resolved (10 fixed, 1 no-op). 6 new factory states/fields untouched by review.**

## §3 — FINAL STATUS (2026-06-20)

✅ **ALL 10 STEPS DONE + LOCALLY VERIFIED.** tsc 0 · eslint 0 · **vite build exit 0 (✓ 4m1s)** ·
php -l clean · pint clean (new/owned files) · **19 tests written** (run post-merge — junctioned worktree
autoloads parent `App\`). No new permissions (reuses `hazards.view|manage|create`). 2 additive migrations
(`status_reason`, `medical_treatment` — nullable, safe, run on deploy).

**Change set** (worktree `recursing-benz-e32c0f`, branch `claude/recursing-benz-e32c0f`): 17 PHP + 11 TS/TSX.
New: `substance-detail-dialog.tsx`, `substance-wizard-dialog.tsx`, `site-chemicals-panel.tsx`,
`substances/constants.ts`, 3 factories, 2 migrations, 2 tests. Deleted: `substances/{show,create}.tsx`.

**REMAINING — user-gated** (established pattern; I don't merge/deploy autonomously):
1. **Merge** `claude/recursing-benz-e32c0f` → main (review diff first).
2. **Deploy** runs the 2 migrations (additive) — no `*PermissionsSeeder` needed (no new perms).
3. **Run the tests in the parent/CI** post-merge: `HazardousSubstanceControllerTest` + `SubstanceCrossModuleTest`.
4. **Chrome-verify on .com** as Demo Admin: register (hero/tabs/rows/ctx-menu/filters) · detail modal
   (6 sections + Options bar + premium SDS upload + storage/exposure panes + deactivate) · add/edit wizard
   (3 steps + GHS picker + success deep-links) · site Hazards tab "Chemicals stored here" · analytics SDS chip.

## §2 — PROGRESS LOG

- 2026-06-20: Audit complete (6-agent workflow). Tracker + LOCKED decisions written.
- 2026-06-20: **Steps 0 + 1 done** — workbench wired (tsc baseline 0), backend foundation (SDS-state
  signal, 4 factories, full-field validation, canonical child endpoints, status lifecycle + migration).
  php -l + pint clean.
- 2026-06-20: **Step 2 done** — controller `index()` gold-standard props + `buildSubstanceDetail` +
  `create()` redirect. php -l + pint clean.
- 2026-06-20: **Steps 4 + 5 done** — `SubstanceDetailDialog` (6 sections + gated Options footer + 4
  action panes incl. **premium SDS upload**) + `constants.ts`. tsc 0 / eslint 0.
- 2026-06-20: **Step 6 done** — `SubstanceWizardDialog`. All three modal components + constants exist.
- 2026-06-20: **Step 3 done + verified** — `substances/index.tsx` rebuilt; **full `vite build` exit 0**
  (✓ 4m3s, no errors). Module renders end-to-end. tsc 0 / eslint 0.
- 2026-06-20: **Step 7 done** — `show()`/`create()` → redirects; deleted `show.tsx`/`create.tsx`;
  removed `canManageEntries`; en-NZ confirmed. **Step 8 done** — site "Chemicals stored here" panel
  (service + SiteController prop + `SiteChemicalsPanel` in sites/show Hazards tab). php -l + tsc 0 + eslint 0.
  Step 9 done next.
- 2026-06-20: **Step 9 done** — all cross-module gaps closed (exposure→WorkSafe via medical_treatment +
  classifier; analytics real sds_expiring; launcher unified on SubstanceWizardDialog). php-l/pint/tsc/eslint
  all clean. **NEXT: Step 10 (FINAL)** — write `HazardousSubstanceControllerTest` (index props/tabCounts/
  SDS-state/extended store-update/status lifecycle/SDS supersede), `SubstanceExposureObserverTest`
  (HsEvent + worksafe_notifiable for hospitalisation), SiteController chemicalsStored, analytics sds_expiring.
  ⚠️ junctioned-vendor worktree autoloads PARENT app for `App\` classes → backend tests verify POST-MERGE
  (frontend already tsc/eslint/build-verified). Then final `vite build` + summary for user (merge is
  user-gated). Try running tests here first to confirm whether worktree app loads (junction `__DIR__`).
