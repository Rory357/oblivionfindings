# eMAR Redesign — Progress Tracker

Branch: `feat/emar-redesign` (off `origin/main`). Worktree: **main tree in place** (HR/Finance loops
isolated in their own worktrees `hr-m1-people` / `fin-wt`). Design bundles: `.design-drops/emar-redesign/`.

## Chosen order (highest-traffic clinical → governance → aggregators last)

| # | Page | Route | Bundle folder | Status | Commit |
|---|------|-------|---------------|--------|--------|
| 1 | MAR Charts | `/emar/mar` | `Emar_Charts_Page/` | done* | `b6658602` + frontend |
| 2 | Medication Rounds | `/emar/rounds` | `Emar_Medication_Rounds_Page/` | done* | `628a1783` + frontend |
| 3 | Medications Database | `/emar/medications` | `Medications_Page/` | done* | `e85d3a13` + frontend |
| 4 | Prescriptions & Orders | `/emar/prescriptions` | `Prescription_Page/` | done* | backend+frontend |
| 5 | PRN Records | `/emar/prn` | `PRN_Redesign/` | done* | backend+frontend |
| 6 | Controlled Drugs | `/emar/controlled` | `Controlled_Drugs_Page/` | done* | backend+frontend |
| 7 | Destructions | `/emar/destructions` | `Destruction_Page/` | done* | `e62c5de8` |
| 8 | Stock Management | `/emar/stock` | `Stock_Management/` | done* | `6d551ef8` |
| 9 | Medication Reviews | `/emar/reviews` | `Medications_review/` | done* | `09593cbd` |
| 10 | Competency | `/emar/competency` | `Competency_Emar/` | todo | — |
| 11 | Self-Administration | `/emar/self-admin` | `Self_Administration_Page/` | todo | — |
| 12 | Medication Errors | `/emar/errors` | `Emar_Errors_Page/` | todo | — |
| 13 | Handovers (meds) | `/emar/handovers` | `Handover_Page_Emar/` | todo | — |
| 14 | Audit Trail | `/emar/audit` | `Audit_Trail_Emar/` | todo | — |
| 15 | Reports | `/emar/reports` | `Emar_Reports/` | todo | — |
| 16 | Emergency Access | `/emar/emergency-access` | `Emar_Emergency_Access_Page/` | todo | — |

Status legend: `todo` / `in-progress` / `done`. `done*` = all automated gates green (types/lint/pint/tests/build); live pixel-verify vs prototype deferred to user (auth-gated dev server).

## Global / shared work (do once, reuse across pages)

- [x] **Per-site brand-colour FOUNDATION (§3b)** — `sites.brand_colour` nullable hex column (migration `2026_06_14_100000`), `Site` fillable, `Store/UpdateSiteRequest` server-side hex validation (`regex:/^#[0-9A-Fa-f]{6}$/`), settings control in the site wizard identity step (`sites/_wizard.tsx`), and a new **`brandColour?: string\|null` prop on `PageHero`** that overrides `--hero-base` (injected as a CSS-var value, no hex in className → ESLint guard green). 2 validation tests green. **Mechanism:** controller resolves the active site's `brand_colour` → page prop → `<PageHero brandColour={…}>`; null falls back to `category` token then `--primary`. eMAR hero *consumption* is wired per-page starting with MAR Charts.
- [x] **Chrome API reference** captured (PageHero / TabStrip / MedsWizardDialog / wizard primitives / EntityFilter / DayPickerChip / StatTile) — see investigation notes; reused across pages.

## Shared-file edits log (for integration conflict resolution)

(Track every edit to `resources/js/components/app-sidebar.tsx`, `resources/js/components/page/page-hero.tsx`, `resources/css/app.css` here.)

- **`resources/js/components/page/page-hero.tsx`** (brand-colour foundation): added optional `brandColour?: string | null` prop + resolved `heroBase` (brandColour → category → primary) driving `--hero-base`. Purely additive — existing `category`-only callers unchanged. ⚠️ Finance loop also edits this file.

## Backlog / deferred

**Page 6 (Controlled Drugs):** loss-index route **retirement** (kept the page; Loss Reports tab provides it), overdue-reconciliation **scheduled job** (gap 3), incident-id **surfacing** on discrepancy rows (gap 4), CD **offline-queue convergence** (gap 6), CD register **PDF** as hero "More" action. The **Destructions** tab here lists CD destructions + a **shared `RecordDestructionDialog`** (in `_cd-dialogs.tsx`) reused on Page 7 — Destructions stays its own page (NOT folded). Backend: balance-integrity **gap-1 validation** (directional after = before ± qty) + fixed a **pre-existing `storeCDEntry` `Undefined array key "notes"` 500** + site filter/brand colour + flat-mapped medications/recentEntries/destructions. 7-tab page (Register/Recent/Reconciliation/Discrepancies/Destructions/Loss/Audit). All CD safety (witness ≠ recorder, conflict, append-only, auto-discrepancy→incident) already enforced. Reasons: separable infra; core = 7-tab register + all CD wizards + balance-integrity.

**Page 9 (Medication Reviews):** right-click **context menus** (actions inline), **INR/monitoring modal** + INR-page retirement (`emar.clients.inr.index` kept — separable page retirement), **CSV/PDF export** + generate-routine-cycle + bulk-assign-reviewer (no backend), Print, `in_progress` status on conduct-open (G5 — conduct→complete in one flow), dedicated **`medications.review.conduct`** permission (needs seeder+deploy reseed — kept `medications.orders.manage`), **recommendations table** (G2 follow-up — JSON `actions[]` suffices for the Kanban), per-drug recommendations driven off the live med chart (used manual rows — avoids payload bloat). Backend SHIPPED: site-scoped brand-coloured flat payload (§3b), **surfaced actions[]/medications_reviewed[]** (G1), **deprescribing pipeline** aggregation (G2) + `advanceReviewAction()` route `emar.reviews.actions.advance` (gp→implemented→monitor→done, gp_status=accepted leaving gp), quarter KPIs + GP-acceptance % (G8), **DBI/falls** migration `2026_06_15_030000` + completeReview (G4); storeReview already took reviewer_user_id (G3), destroyReview already soft-cancel. Frontend: `_review-dialogs.tsx` 4 MedsWizardDialog modals (Schedule 4-step / **Conduct 5-step replacing Edit+Complete** / Detail / Reschedule) + 6-tab page (Overview/Due/Scheduled/Completed/**Deprescribing Kanban**/All). 3 PHPUnit green. Reasons: separable infra / new permissions+seeders / cross-page; core = brand 6-tab governance board + 4 wizards + deprescribing pipeline + DBI capture.

**Page 8 (Stock Management):** **days-of-supply / avg consumption rate** (not stored — used an on-hand÷reorder ratio bar, NOT an invented "~Nd left"), **true FEFO multi-batch lots** (one batch/expiry per row today — FEFO tag when the row's own expiry ≤30d), dedicated **waste/return-to-pharmacy** action on expired lines (destructions live on Page 7 — cross-link only). Backend SHIPPED: site-scoped brand-coloured payload (§3b), **controlledRegister** reconciliation feed (register balance vs on-hand + open discrepancy), flat 5-stage pharmacy-order lifecycle, **cold-chain** `storage_condition` migration + `requiresColdChain()` + updateStockItem. All write endpoints already existed (order store/advance, receive, update, adjust, balance-check) — wired, none added. Frontend: `_stock-dialogs.tsx` 4 MedsWizardDialog modals (New order / Receive [**preserves scan-gate + `submitEmarMutation` offline queue**] / Count [CD-aware: balance-check vs adjust] / Adjust) + 6-tab page (All/Low/Expiring/Expired/Controlled/Orders) client-grouped list + CD recon tab + order tracker; retired per-row `ScheduledStockCounts` popover into the Count modal. 3 PHPUnit green. Reasons: separable data-modelling (consumption history, batch lots) / cross-page (waste→Page 7); core = brand 6-tab board + 4 wizards + CD reconciliation + order lifecycle + cold-chain.

**Page 7 (Destructions):** **Awaiting** tab + awaiting-destruction **stock state** (gap 8 — no such state in the data model; tab dropped rather than shipping an empty stub), **photo-evidence** upload (gap 7), witness **credential/password** verification at sign-off (gap 4), **CD-register reconciliation entry** auto-written on a CD destruction (gap 5 — destruction decrements stock but doesn't yet post a `balance_check` row), server-side **PDF** export (the CSV export is real + client-side; PDF deferred). Backend SHIPPED: immutability (SoftDeletes + `voided_at/void_reason/voided_by`, `scopeVerified`) with **void-not-delete** (`voidDestruction`, retired hard-delete `destroyDestruction`), witness integrity gap 10 (2nd witness ≠ destroyer ≠ 1st), site_id gap 6, flat brand-coloured payload. Frontend: **generalized shared `RecordDestructionDialog`** (CD-derived from picked med; serves Pages 6 & 7) + new `VoidDestructionDialog` + 3-tab page (Destruction log / Controlled drugs / Reports & export). 5 PHPUnit green. Reasons: separable compliance/UX enhancements or new data states; core = immutable voidable register + 3-tab surface + shared record wizard + witness rules + CSV export.

**Page 5 (PRN Records):** CSV **export register** (hero export button NOT rendered — hidden unbuilt action), full **date-range period picker** (used a recent 30-day window + site filter instead), Trends per-med **colour** bars (single-colour bars). The two modals are **REUSED verbatim**: `PrnWizard` (record dose → `meds.today.prn`) + `PrnEffectDialog` (effectiveness → `meds.today.prn_effect`) — the latter is the existing shared raw-`Dialog` effectiveness component (reused as the single effectiveness path, not migrated to MedsWizardDialog since meds/today shares it). Backend: `prn()` rebuilt to compose `MedsBoardPayloadService` (prn_medications/clients/witnesses/board_user) + a flat 30-day register + pending-review queue + site_brand_colour. NO new modals. Reasons: secondary; core = 4-tab actionable register reusing the existing PRN write + effectiveness paths.

**Page 4 (Prescriptions & Orders):** right-click **context menu** (actions are inline buttons), covert **review/extend** workflow + reminder, order **expiry** scheduled job, Link→MAR **create-new-med** path (link-existing only), **Activity** tab = simple derived order feed (not full `TimelineEvent`). The covert wizard composes rich capacity/MDT input into the `clinical_justification`/`legal_basis`/`pharmacist_advice` text columns (no dedicated columns added). Backend: migration `2026_06_15_000000` (countersign_method + read_back_confirmed/witnessed_by) + flat payload + endpoint extensions (storePrescription read-back, updatePrescription client_medication_id link, countersignPrescription method+confirm). Reasons: secondary / separable infra; core shipped = 5-tab surface (Orders/Countersign/Dispensing/Covert/Activity) + dispensing lifecycle + covert client_medication_id fix + all-modal workflows.

**Page 3 (Medications Database):** import **row-preview/validation** step (kept fire-and-forget CSV import w/ toast), full **live drug-interaction engine** in the Add-wizard Safety step (allergy cross-check IS live via `/api/medications/clients/{id}/allergies`; interaction shown as a note + the **Interactions** reference modal lists `interaction_severity`), **client-context cards** (folded into the detail modal + hero), and **version-history** modal (old `MedicationVersionHistory` — not in the new design's modal set). The shared 4-step `AddMedicationDialog` (in `pages/emar/_dialogs.tsx`) now also powers MAR's "Add medication" (mar-governance-dialogs reuses it — one create path). §7 retire is moot (orphaned medications pages already removed). Reasons: secondary; core shipped = register hero + TabStrip facets + filterable directory + all-modal CRUD (Add/Edit/Detail/Discontinue[req. reason]/Import/Verify/Reject/Interactions).

**Page 2 (Rounds):** round **timeline** donut axis (most complex visual lens), **Chart** resident×round matrix tab (needs per-round items load — secondary lens), **audit-&-timeline** dialog, **right-click context menu** (board cards open via button), template-wizard **service-context** selector (site + default staff cover coverage), **Re-record** on already-recorded doses (backend dedup blocks it → shown read-only), and backend **G2/G3** (complete-with-pending guard + auto-miss scheduled job) + G5–G8/G10. Reasons: secondary lenses / separable miss-tracking infra; core shipped = hero + board (cards/list) + guided modal + templates wizard + generate + activity. Cross-module: `meds.round.show` deep links keep working via the redirect (repointing `RoundInfo.url` to skip the hop is a minor optimisation, deferred).

## Notes

- Started: 2026-06-14. Fresh start (no prior progress).

### MAR Charts (Page 1) — resume notes
Plan: `docs/emar-redesign/mar-charts-plan.md`. Commits so far: foundation `848ccebe`, plan `f5367958`.

**Confirmed facts (don't re-investigate):**
- Modal write endpoints already exist: `emar.medications.store/verify/reject`, `emar.clients.inr.store` + `emar.inr.disable`, `emar.clients.syringe_drivers.store` (+checks/complete), `emar.clients.attention_alerts.store` (+update/resolve), `emar.clients.alert_suppression`, `emar.clients.medication_settings`. Models exist: `ClientInrRecord`, `MedicationSyringeDriver`, `ClientMedicationAlert`.
- **Dose recording is reused, not rebuilt:** `resources/js/pages/meds/today/components/record-dose-wizard.tsx` (`RecordDoseWizard`) + `prn-wizard.tsx` (`PrnWizard`) are on `MedsWizardDialog` chrome and write via `EnhancedMarService` — the single pipeline. They consume `ScheduleRow`/`PrnMedication` + `witnesses` + `notGivenReasons` (`NotGivenReason::options()`) + `signedAs` (from `board_user`). Types: `resources/js/pages/meds/today/types.ts`.
- `Emar/WorkerMedsController@today` (`:66`) builds that payload via private helpers: `scheduleForDate` (:556), `clientsPayload` (:674), `sitesPayload` (:718), `prnMedications` (:749), `witnesses` (:1019), `administrationsForDay` (:517), `recordedPayload` (:638), `roundLabelFor` (:660), `prnFollowUps` (:849), `activityForDate` (:957), `rawUtcInstant` (:1169), `friendlyTimeLabel` (:829). Deps via ctor (`:59`) — incl. `MarScheduleService $scheduleService`.
- `recordDose` (:176) confirms safety layers (verification gate, coded omission, observations, witness+credential, time window) all run in `EnhancedMarService`. **So MAR gaps 3–5 need NO new backend — just reuse the `meds.today.record` path.**

**DONE:**
1. ✅ `app/Services/Emar/MedsBoardPayloadService.php` extracted; `WorkerMedsController` delegates; `EmarController@mar` exposes the board keys + `site_brand_colour`. Commit `b6658602`. 10 worker-meds + 2 MAR-payload tests green.
2. ✅ Frontend rebuilt: `resources/js/pages/emar/MarCharts.tsx` (hero+`brandColour`, attention bar, time-grid, PRN card, clinical rail, `TabStrip` schedule/due/prn/history, no-resident picker) + new components `resources/js/components/emar/mar/{mar-grid,attention-bar,prn-card,clinical-rail}.tsx` + `resources/js/pages/emar/components/mar-governance-dialogs.tsx` (Add-medication / Record-INR / Syringe-driver / Manage-alerts / Verify-order / Chart-warnings — all on `MedsWizardDialog`). Reuses `RecordDoseWizard` + `PrnWizard` (one dose/PRN path). types + lint clean.

**DEFERRED → backlog (with reason):**
- Right-click **quick-action context menu** (one-click mark given/refused) — right-click currently opens the full `RecordDoseWizard` (safe; CD witness never skipped). Enhancement.
- **Corrections-review** modal — rail shows the pending count (informational); full review flow is a follow-up.
- **Chart-warnings auto-prompt on open** — currently a manual "Review warnings" button (`WarningsDialog` built); auto-`useEffect` open is a small follow-up.
- **Drug-interactions banner** — not surfaced in the new layout (attention bar covers warnings); could fold interactions into the attention bar.
- **Break-glass status** on MAR — dropped from MAR (owned by Emergency Access page #16).
- Standalone **Record-observation** modal — NOT needed: observations are captured at dose sign-off inside `RecordDoseWizard` (rule-driven). Reconciled, not a gap.

**REMAINING for §9 gate:** build (running), live pixel-verify vs prototype (defer to user/dev — auth-gated), then commit `feat(emar): redesign MAR Charts` + flip status to done.
