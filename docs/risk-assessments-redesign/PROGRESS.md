# Risk Assessments Redesign — PROGRESS

Self-paced `/loop`. Rebuild `/health-safety/risk-assessments` to the H&S **gold standard** (near-twin of
Incidents / Safeguarding / Fleet Incidents / Hazards), from the design drop
`Risk Assessments.dc.html` + HANDOFF. Every workflow → an **Add-Client-parity wizard modal**.
Plus the user's explicit asks: **feature-complete modals** incl. a **premium multi-file document upload**,
and **deep cross-module integration with no duplication**.

Worktree: `sleepy-blackburn-b68924` (branch `claude/sleepy-blackburn-b68924`). `node_modules` junctioned → parent.
NZ-only, web-only, semantic tokens only.

---

## §0 — LOCKED DECISIONS

1. **Tone map (semantic tokens only):** inherent/residual level → low=`success`, medium=`info`, high=`warning`,
   extreme=`critical`. Status → draft=`neutral`, active=`success`, under_review=`warning`,
   superseded/archived=`neutral`. No raw hex/oklch, no `border-l-*`.
2. **Gates:** `index` + reads + attachment **download** = `permission:hazards.view`. All writes
   (store/update/activate/review/residual/supersede/archive + attachment upload/destroy) = `permission:hazards.manage`.
   **No new permissions** (both seeded in `RbacSeeder.php`).
3. **Controller:** new `App\Http\Controllers\HealthSafety\HsRiskAssessmentController` (NOT extend HsEventController).
   Repoint `risk-assessments.index` route to it; leave `HsEventController::riskAssessments` in place (dead, or delete later).
4. **Service is the single write path** — controller methods call `HsRiskAssessmentService` verbatim
   (create/activate/markForReview/updateResidualRisk/supersede/archive). `create` always forces `draft`.
   `supersede` merges old assessable/org/event/frequency with new data + returns the new draft.
5. **Matrix:** `RiskMatrix` (`components/health-safety/risk-matrix.tsx`) is **display-only AND collides
   medium/high tone** (both `bg-status-warning-bg`). Build an RA-scoped `RaMatrix` (interactive capture +
   read-only) using the §0.1 4-tone mapping. Leave shared `RiskMatrix` untouched (no regressions).
   *(Justified deviation from handoff "reuse RiskMatrix".)*
6. **Document upload (premium):** reuse existing `AttachmentUploader` (`components/ui/file-dropzone.tsx`) —
   drag/drop, multi-file, per-file note, sequential FormData POST. Mirror `EmergencyDrillAttachment`
   for the model + migration + 3 endpoints. **This adds ONE migration** (`hs_risk_assessment_attachments`) —
   a sanctioned deviation from handoff "no migration", driven by the user's explicit document-upload ask.
   Disk `public`; route-based download; `download_url` serialised in detail payload.
7. **No duplication:** build the RA table + 7 modals + detail dialog + ctx-menu as **shared components**
   consumed by (a) standalone register page, (b) Client profile `risk_management` tab (2nd section,
   create pre-attaches client — do NOT merge `ClientRisk`), (c) Site profile new "Risk Assessments" tab
   (create pre-attaches site). Modals POST to the org-wide endpoints which `->back()`-redirect → host page
   reloads its own RA prop. Keep distinct from `ClientRisk`, Governance risk register, `SafeguardingRiskAssessment`.
8. **Dialog parity:** modals built from `WizardShell` + `wizard/primitives` (NOT importing AddClientDialog).
   Add an optional `maxWidth` prop to `WizardShell` (additive) → `min(94vw,1080px)` to match Add-Client/design
   (default stays 980px; no regression to existing callers).
9. **Filters added to index:** `site_id`, `client_id`, `hs_event_id`, `tab`, `search` (+ existing
   `status`, `risk_level`, `due_for_review`). Plain string URLs + `router.get` (mirror Incidents), no Wayfinder import.
10. **Migration policy:** run locally/autonomously against the shared DB. Backend tests verified in the PARENT
    repo after merge (junctioned vendor loads parent app; frontend tsc/eslint run in the worktree).

---

## STEPS  (1–7 DONE + tsc/eslint clean + 22 controller tests green; Step 8 verify in progress)

- [x] **Step 1 — Attachments + write backbone**: migration `hs_risk_assessment_attachments` + model
  `HsRiskAssessmentAttachment`; `HsRiskAssessment::attachments()` relation; new `HsRiskAssessmentController`
  skeleton with 7 write actions + 3 attachment actions (upload/download/destroy); 7 Form Requests; routes.
- [ ] **Step 2 — `index()` enrichment**: tabCounts (all/active/drafts/due_for_review/high_extreme/superseded_archived),
  hero block (2 clusters + NZ compliance counts/booleans), detail (eager-load on `?assessment=`), pickers
  (sites/clients/staff/events), `can:{manage}`, expanded filters. Row serialiser w/ assessable label + tones.
- [ ] **Step 3 — Shared RA UI kit**: `RaMatrix` (interactive+readonly); the 7 wizard modals (New 6-step,
  Edit, Supersede multi, Approve, Mark-for-review, Record-residual, Archive — single-steps as 1-step WizardShell);
  detail-as-modal dialog (rail + 2 matrices + controls + version chain + attachments + Options footer);
  row table (`register-row-kit`) + `ShiftContextMenu` builder + `useRaModals` orchestration. Upload in New/Edit/detail.
- [ ] **Step 4 — Standalone register page**: full rebuild of `risk-assessments/index.tsx` (HeroShell + hs-hero-kit
  hero + ribbon + clusters + badges + footer filter bar; TabStrip; shared table; modals; detail).
- [ ] **Step 5 — Client profile placement**: 2nd section in `risk_management` tab + `ClientController@show` props
  (`hs_risk_assessments`, pickers, can). Create pre-attaches client.
- [ ] **Step 6 — Site profile placement**: new "Risk Assessments" tab + `SiteController@show` props. Create pre-attaches site.
- [ ] **Step 7 — Cross-module touch-point parity**: dashboard RA tiles deep-link (`?status=active|risk_level=high|due_for_review=true`);
  expiring-feed link; HsEvent detail "New risk assessment" (event pre-attached) + link cards; hero "Board export"
  link to governance RA register; (optional) analytics RA-by-level drill.
- [ ] **Step 8 — Verify + harden**: `tsc` + `eslint` (worktree); feature tests (controller CRUD + transitions +
  attachments + scoping) + `migrate` + `vite build` in PARENT after merge; adversarial multi-agent review; fix findings.

---

## AUDIT FACTS (build-ready, from 8-agent sweep)

### Model `App\Models\HsRiskAssessment` (`hs_risk_assessments`)
Fillable: organization_id, reference_number, assessable_type, assessable_id, hs_event_id, title,
risk_description, status, likelihood, consequence, risk_score, risk_level, existing_controls,
additional_controls, residual_likelihood, residual_consequence, residual_risk_score, residual_risk_level,
risk_acceptable, assessed_by_user_id, assessed_at, approved_by_user_id, approved_at, review_due_at,
review_frequency_days, superseded_by_id, created_by, updated_by.
Casts: likelihood/consequence/risk_score/residual_* → int; risk_acceptable → bool; assessed_at/approved_at → datetime;
review_due_at → date. risk_level/residual_risk_level = plain strings.
Const: STATUS_{DRAFT,ACTIVE,UNDER_REVIEW,SUPERSEDED,ARCHIVED}; LEVEL_{LOW,MEDIUM,HIGH,EXTREME};
RISK_BANDS low[1,4]/medium[5,9]/high[10,15]/extreme[16,25].
Rel: assessable() MorphTo; hsEvent(); assessedBy(assessed_by_user_id); approvedBy(approved_by_user_id);
supersededBy(self superseded_by_id); creator(created_by). **No attachments rel (add it).**
Scopes: scopeActive, scopeHighOrExtreme, scopeDueForReview, scopeForAssessable($type,$id).
Static: calculateScore(int $l,int $c):['score','level']; scoreToLevel; generateReferenceNumber() → RA-YYYY-NNNN (withTrashed).
Helpers: isActive, isDueForReview, isHighOrExtreme. Traits: AuditableChanges, HasFactory, SoftDeletes.

### Service `App\Services\HealthSafety\HsRiskAssessmentService` (verbatim signatures)
- create(array $data): HsRiskAssessment — forces draft, gens ref, sets assessed_by/created_by=auth, calc inherent+residual.
- activate(HsRiskAssessment $a, array $approval = []): HsRiskAssessment — draft→active, approved_by=$approval['approved_by_user_id']??auth, approved_at=now, review_due_at from frequency if unset. Throws if not draft.
- markForReview($a): active→under_review. Throws if not active.
- updateResidualRisk($a, int $likelihood, int $consequence, ?bool $acceptable=null): sets residual_* + risk_acceptable.
- supersede($a, array $newData): active|under_review→superseded; creates+returns NEW draft (merges old org/assessable/event/freq).
- archive($a): →archived (idempotent).

### Routes (`routes/health-safety.php`)
Read group `permission:hazards.view` lines 39–44 (RA index = line 43, `HsEventController@riskAssessments`).
Write group `permission:hazards.manage` lines 47–67 (events only — NO RA writes exist).
Governance reports `permission:governance.view` lines 70–76 (RA register = line 75, JSON export).
→ Add RA write group after line 67; repoint index route to new controller.

### Wizard kit
- `add-client-dialog.tsx` North Star: Dialog `min(94vw,1080px)`×`min(92vh,860px)`, `[&>button]:hidden`; rail+pct bar;
  "Step X of N"; 3px progress; `useForm` {forceFormData:true, preserveScroll, preserveState}; onError→stepForError jump;
  footer Back/Cancel/Save&add-another(create only, review step)/Create; success pane. `PhotoField` = file pattern.
- `wizard/shell.tsx`: WizardShell (980px default → add maxWidth prop), WizardStepPane, WizardSuccessPane, ReviewCard,
  ReviewRow, type WizardStep{key,label,blurb,icon}.
- `wizard/primitives.tsx`: Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented<T>, ChipMulti,
  TilePicker, Ring, type IconType. (RA modals compose these.)

### Register chrome
- `hs-hero-kit.tsx`: HeroShell({children,footer?}), HeroStatusPill, HeroMedallion({icon}), HeroCluster({title,icon,children}),
  HeroClusterTile({href?,label,value,caption,tone,delta?,deltaTone?}), HeroComplianceBadges({items?,…}), HeroSegmented
  ({label?,items,value,onChange,ariaLabel,variant?:'pill'|'segmented'}), fmt(), Tone='success|warning|critical|neutral', DOT_CLASS.
- `register-row-kit.tsx`: RegisterTableHeader({icon,title,subtitle?,hint?,hintIcon?}), FlagBadge({icon,children,tone,title}),
  TONE_BG, TONE_DOT (success/warning/critical/neutral), titleCase, initials, entityTone(id).
- `@/components/rostering`: TabStrip({value,onChange,items:RosterTabItem[],ariaLabel}), RosterTabItem{id,label,icon,tone,badge?},
  ShiftContextMenu({ctx:ShiftCtxState,onClose}), ShiftCtxItem (sep | {icon,label,sub?,kbd?,tone?,onClick}),
  ShiftCtxState{x,y,tag,tagBg?,tagColor?,meta,items}, EntityFilter({label,allLabel,items,value,onChange,onDark?}).
- Incidents patterns (`pages/incidents/index.tsx`): `go(next)`=router.get(url,{...filters,...next},{preserveState,preserveScroll,replace});
  detail-as-modal via `?incident=id` + `only:['detail']`; openRowCtx builds ShiftCtxState; report modal launcher seeded by `report` prop.

### RiskMatrix — display-only. Props {likelihood,consequence,residualLikelihood?,residualConsequence?,compact?}. No onSelect. (→ build RaMatrix.)

### Attachments pattern (mirror EmergencyDrillAttachment)
Model fillable: <parent>_id, uploaded_by, disk, original_name, path, mime, size, kind, notes, alt_text; cast size int;
rel parent() + uploader(); isImage(). Migration: FK cascadeOnDelete, uploaded_by nullOnDelete, disk default 'public',
softDeletes. Controller: uploadAttachment (validate file|max:20480 + kind/notes/alt_text; store('…','public'); ->back()),
downloadAttachment (abort_unless owner + exists; Storage::disk->download(path, original_name)), destroyAttachment
(delete file + soft-delete row). Routes: POST/DELETE under hazards.manage, GET download under hazards.view.
Frontend: `AttachmentUploader` ({endpoint,noteField,sensitive?,accept,hint}) already exists — reuse. Disk 'public'.
Serialise `download_url` in detail payload.

### Cross-module touch-points
- Dashboard `dashboard.tsx:51-55` has risk_assessments.{active,high_extreme,due} props but renders NO tile → add clickable tiles.
- `HsDashboardService.php:267-273` expiring feed links register root → add `?due_for_review=true`.
- HsEvent detail dialog `event-detail-dialog.tsx:369,1653-1694` lists RA read-only → add "New risk assessment" (event pre-attach) + links.
- Governance RA register `HsGovernanceReportController@riskAssessmentRegister` (JSON) already linked from dashboard hero popover + compliance tab → add "Board export" from RA hero.
- Sidebar `app-sidebar.tsx:1301` → `/health-safety/risk-assessments` (correct, no change).
- Analytics has NO RA breakdown (hazardsByRisk = SiteHazard) → optional add `riskAssessmentsByLevel` + drill.

---

## LOG
- 2026-06-20: Audit complete (design HTML read + 8 parallel agents). Tracker + tasks created. Worktree node_modules junctioned. Starting Step 1.
- 2026-06-20: **Steps 1–7 BUILT.** Backend: `HsRiskAssessmentController` (index w/ tabCounts+hero+detail+pickers+can+filters, 7 lifecycle writes, 3 attachment actions, JSON `show`), 5 Form Requests, `RiskAssessmentPresenter` (row/detail/pickers — shared by all 3 controllers), `HsRiskAssessmentAttachment` model, 2 migrations (attachments table + approval_note/last_review_note cols), routes, flash key. Frontend: 8 shared components (`types`,`ra-kit`,`ra-matrix` interactive,`ra-wizard-dialog` 7-kind + **premium staged evidence upload**,`ra-detail-dialog`,`ra-table`+ctx builder,`ra-register-section` embeddable) + full `index.tsx` rebuild + `WizardShell` maxWidth prop. Profiles: Client `risk_management` 2nd section + Site new RA tab (both reuse `RaRegisterSection`, create pre-attaches entity, ClientRisk kept separate). Touch-points: dashboard expiring deep-link, event-detail "View in register" + event pre-attach on create, hero Board-export link.
- 2026-06-20: **VERIFIED so far:** `tsc --noEmit` clean on all touched files (88 errors are pre-existing `@/routes` Wayfinder artifacts only). `eslint` clean. Local migrations applied. **`HsRiskAssessmentControllerTest` 22/22 green (103 assertions).**
- 2026-06-20: ⚠️**KEY FIX:** in-code `->can('hazards.*')` returns FALSE (no `Gate::before`; `EnsurePermission` middleware uses `$user->canDo()`). Switched all 3 controllers to `->canDo('hazards.view'|'hazards.manage'|'governance.view')`. (NB: existing HsEventController still uses `->can()` — latent, out of scope.)
- 2026-06-20: Step 8 — **regression suite 316 passed (1279 assertions), ZERO regressions.** Adversarial review `wf_0f719276-976` returned **31 confirmed / 45 raw**.
- 2026-06-20: **Review fixes applied (17):** Client name in `attachedTo()` (Client has no `name`, use `full_name`); `updateResidual` preserves `risk_acceptable` when omitted; upload `mimes:` allowlist; attach_id existence check uses models (respects SoftDeletes); new `risk_acceptable` filter + "Residual not OK" hero tile now a live link; residual-matrix legend fixed (inherent solid + residual dashed, correct labels); embedded edit/supersede now LOCKED to the profile entity; `stepForError` maps fields→steps; dashboard expiring item deep-links to the specific assessment; "Tap"→"Click" (web-only); detail rail uses `StatusChip` (dot); a11y — `th scope=col`, avatar `aria-label`, hero search `aria-label`, WizardShell progressbar ARIA + hide "Step 1 of 1" for single-step + `maxHeight` prop (RA = 860px Add-Client parity). +2 tests (risk_acceptable filter, deleted-assessable reject).
- 2026-06-20: **Skipped (14) w/ rationale:** org-scope/`organization_id` (single-tenant; null module-wide; `EnsurePermission`/whole H&S module identical — not a regression); staged-upload `router.post` (proven AttachmentUploader pattern); ctx-menu keyboard + row→ctx keyboard (shared `ShiftContextMenu`, pre-existing; actions reachable via detail Options bar); `awaiting_approval`==drafts (design-intended dual tile); ref-number race (DB UNIQUE constraint); archive try/catch (idempotent, never throws); `paramsFrom` drops 'all' (false positive — kept); `can as any` on client profile (matches file's existing pattern); reverse morphMany (scope is canonical); matrix focus-outline (false positive — capture has no residual overlay); delete-confirm (module-consistent, soft-deleted).
- 2026-06-20: post-fix tsc + eslint clean. **24 controller tests green (117 assertions).** **Production `vite build` green (✓ 3m 47s, 0 errors).** ALL 8 STEPS DONE + fully verified in-worktree. Memory written. **NOT committed/merged — awaiting user go for merge→deploy→Chrome-verify.** ⚠️at merge: shared-file conflicts likely vs concurrent H&S loops (WizardShell maxWidth/maxHeight, HandleInertiaRequests flash key, ClientController/SiteController/ClientProfile show, event-detail-dialog, HsDashboardService) — keep BOTH sides additive.
