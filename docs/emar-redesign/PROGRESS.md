# eMAR Redesign — Progress Tracker

Branch: `feat/emar-redesign` (off `origin/main`). Worktree: **main tree in place** (HR/Finance loops
isolated in their own worktrees `hr-m1-people` / `fin-wt`). Design bundles: `.design-drops/emar-redesign/`.

## Chosen order (highest-traffic clinical → governance → aggregators last)

| # | Page | Route | Bundle folder | Status | Commit |
|---|------|-------|---------------|--------|--------|
| 1 | MAR Charts | `/emar/mar` | `Emar_Charts_Page/` | todo | — |
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
