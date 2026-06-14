# eMAR Redesign — Progress Tracker

Branch: `feat/emar-redesign` (off `origin/main`). Worktree: **main tree in place** (HR/Finance loops
isolated in their own worktrees `hr-m1-people` / `fin-wt`). Design bundles: `.design-drops/emar-redesign/`.

## Chosen order (highest-traffic clinical → governance → aggregators last)

| # | Page | Route | Bundle folder | Status | Commit |
|---|------|-------|---------------|--------|--------|
| 1 | MAR Charts | `/emar/mar` | `Emar_Charts_Page/` | in-progress | — |
| 2 | Medication Rounds | `/emar/rounds` | `Emar_Medication_Rounds_Page/` | todo | — |
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

Status legend: `todo` / `in-progress` / `done`.

## Global / shared work (do once, reuse across pages)

- [x] **Per-site brand-colour FOUNDATION (§3b)** — `sites.brand_colour` nullable hex column (migration `2026_06_14_100000`), `Site` fillable, `Store/UpdateSiteRequest` server-side hex validation (`regex:/^#[0-9A-Fa-f]{6}$/`), settings control in the site wizard identity step (`sites/_wizard.tsx`), and a new **`brandColour?: string\|null` prop on `PageHero`** that overrides `--hero-base` (injected as a CSS-var value, no hex in className → ESLint guard green). 2 validation tests green. **Mechanism:** controller resolves the active site's `brand_colour` → page prop → `<PageHero brandColour={…}>`; null falls back to `category` token then `--primary`. eMAR hero *consumption* is wired per-page starting with MAR Charts.
- [x] **Chrome API reference** captured (PageHero / TabStrip / MedsWizardDialog / wizard primitives / EntityFilter / DayPickerChip / StatTile) — see investigation notes; reused across pages.

## Shared-file edits log (for integration conflict resolution)

(Track every edit to `resources/js/components/app-sidebar.tsx`, `resources/js/components/page/page-hero.tsx`, `resources/css/app.css` here.)

- **`resources/js/components/page/page-hero.tsx`** (brand-colour foundation): added optional `brandColour?: string | null` prop + resolved `heroBase` (brandColour → category → primary) driving `--hero-base`. Purely additive — existing `category`-only callers unchanged. ⚠️ Finance loop also edits this file.

## Backlog / deferred

- _none yet_

## Notes

- Started: 2026-06-14. Fresh start (no prior progress).

### MAR Charts (Page 1) — resume notes
Plan: `docs/emar-redesign/mar-charts-plan.md`. Commits so far: foundation `848ccebe`, plan `f5367958`.

**Confirmed facts (don't re-investigate):**
- Modal write endpoints already exist: `emar.medications.store/verify/reject`, `emar.clients.inr.store` + `emar.inr.disable`, `emar.clients.syringe_drivers.store` (+checks/complete), `emar.clients.attention_alerts.store` (+update/resolve), `emar.clients.alert_suppression`, `emar.clients.medication_settings`. Models exist: `ClientInrRecord`, `MedicationSyringeDriver`, `ClientMedicationAlert`.
- **Dose recording is reused, not rebuilt:** `resources/js/pages/meds/today/components/record-dose-wizard.tsx` (`RecordDoseWizard`) + `prn-wizard.tsx` (`PrnWizard`) are on `MedsWizardDialog` chrome and write via `EnhancedMarService` — the single pipeline. They consume `ScheduleRow`/`PrnMedication` + `witnesses` + `notGivenReasons` (`NotGivenReason::options()`) + `signedAs` (from `board_user`). Types: `resources/js/pages/meds/today/types.ts`.
- `Emar/WorkerMedsController@today` (`:66`) builds that payload via private helpers: `scheduleForDate` (:556), `clientsPayload` (:674), `sitesPayload` (:718), `prnMedications` (:749), `witnesses` (:1019), `administrationsForDay` (:517), `recordedPayload` (:638), `roundLabelFor` (:660), `prnFollowUps` (:849), `activityForDate` (:957), `rawUtcInstant` (:1169), `friendlyTimeLabel` (:829). Deps via ctor (`:59`) — incl. `MarScheduleService $scheduleService`.
- `recordDose` (:176) confirms safety layers (verification gate, coded omission, observations, witness+credential, time window) all run in `EnhancedMarService`. **So MAR gaps 3–5 need NO new backend — just reuse the `meds.today.record` path.**

**NEXT STEPS (ordered):**
1. **Extract `app/Services/Emar/MedsBoardPayloadService.php`** owning the helpers above (`forClients(array $clientIds, Carbon $date, User $user): array` → `{schedule, prn_medications, witnesses, not_given_reasons, board_user, clients, sites, stats?}`). Move the private methods from `WorkerMedsController` into it; have `WorkerMedsController@today` delegate. **Then run the meds/today tests** (frontline path — guard against regression) before proceeding.
2. `EmarController@mar` (`:746`): call the service for `[selectedClient->id]` and add `schedule`/`prn_medications`/`witnesses`/`not_given_reasons`/`board_user` to the props; add `site_brand_colour` (selected client's `site->brand_colour`) to `selectedClient`.
3. Rebuild `resources/js/pages/emar/MarCharts.tsx`: hero (`PageHero` + `brandColour`), attention bar, **MAR grid** (group `schedule` by `medication_id` × `time` columns), PRN card, clinical rail. New components under `resources/js/components/emar/mar/`.
4. Wire `RecordDoseWizard` (left-click cell / quick-actions; CD → full wizard) + `PrnWizard` (PRN Give). Build the rail/governance modals on `MedsWizardDialog` posting to the existing endpoints.
5. `TabStrip` facets (schedule/due/prn/history). Retire legacy `RecordAdministrationDialog`/`prn-sheet` usage from MAR; redirect `emar.clients.inr.index` once unused.
6. §9 gate: types/lint/pint/tests(+new)/build/screenshot vs prototype + brand-colour change + modal-chrome audit + cross-module click-through. Commit `feat(emar): redesign MAR Charts`.
