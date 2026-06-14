# eMAR Redesign — Progress Tracker

Branch: `feat/emar-redesign` (off `origin/main`). Worktree: **main tree in place** (HR/Finance loops
isolated in their own worktrees `hr-m1-people` / `fin-wt`). Design bundles: `.design-drops/emar-redesign/`.

## Chosen order (highest-traffic clinical → governance → aggregators last)

| # | Page | Route | Bundle folder | Status | Commit |
|---|------|-------|---------------|--------|--------|
| 1 | MAR Charts | `/emar/mar` | `Emar_Charts_Page/` | done* | `b6658602` + frontend |
| 2 | Medication Rounds | `/emar/rounds` | `Emar_Medication_Rounds_Page/` | done* | `628a1783` + frontend |
| 3 | Medications Database | `/emar/medications` | `Medications_Page/` | todo | — |
| 4 | Prescriptions & Orders | `/emar/prescriptions` | `Prescription_Page/` | todo | — |
| 5 | PRN Records | `/emar/prn` | `PRN_Redesign/` | todo | — |
| 6 | Controlled Drugs | `/emar/controlled` | `Controlled_Drugs_Page/` | todo | — |
| 7 | Destructions | `/emar/destructions` | `Destruction_Page/` | todo | — |
| 8 | Stock Management | `/emar/stock` | `Stock_Management/` | todo | — |
| 9 | Medication Reviews | `/emar/reviews` | `Medications_review/` | todo | — |
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
