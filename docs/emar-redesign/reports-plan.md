# eMAR Redesign — Page Plan: Reports (`/emar/reports`)

## 0. Identity
- **Route:** `GET /emar/reports` → `emar.reports` (`EmarReportController@index`, perm `reports.viewAny`). **READ-ONLY** + real exports.
- **Inertia page:** `resources/js/pages/emar/Reports.tsx` (rewrite/re-skin).
- **Existing exports (all REAL download targets — wire, don't stub):** `emar.reports.export?report_type=…` (CSV: administration/prn/controlled/rounds/errors + service records), `emar.reports.export_mar`, `emar.reports.export_discrepancies`, PDF `emar.pdf.mar` / `emar.pdf.cd_register` / `emar.pdf.round_sheet`.
- **Goal:** flat filter-card + loose export buttons → brand hero + `TabStrip` governance surface with rich per-tab panels (the payload is **already comprehensive**: admin/PRN/CD/staff/stock/rounds/errors). Largely a re-skin reusing `OpsStatCard`/`DonutChart`/recharts.

## Key findings (verify-against-code)
- `index()` already ships: adminSummary + dailyAdmin + clientBreakdown, topPrnMeds + prnByClient, controlledDrugs, staffCompliance, stockStatus, roundCompletion, errorSummary, clients, careLevels, filters. Current page uses `OpsStatCard`/`DonutChart`/recharts → reuse.
- **No site filter / no brand colour** (§3b gap). **No coded-reason breakdown** (handoff "Reason not given" tab — derivable from `administrations.reason_code`).
- **No EmarSavedReport/Subscription models or Build/Schedule write endpoints** → per [[feedback_hide_unbuilt_actions]] the **Custom-reports / Build / Schedule** tabs+modals are **OMITTED** (not stubbed). Export is real download links; "Report CD loss" reuses the existing `ReportLossDialog` (Page 6, `CDLossReportController@store`).

## 1. Section + modal map (§1/§4)
| Block | Component | Source |
|---|---|---|
| Hero (live eyebrow, stats Compliance/Doses/Open-errors/CD-variances, badges, period presets + search + site footer) | `PageHero` + `brandColour` | payload + colour |
| Tabs (Administration/Reason-not-given/PRN/Controlled/Staff/Stock/Rounds/Errors/Audit-tools) | `TabStrip` | client-side |
| Filter bar (client / care level / custom range) | inline `Select` | re-query via `router.get` |
| Panels (stat cards + chart + table) | `OpsStatCard`/`DonutChart`/recharts + tables | payload |
| Audit tools (report-pack links) | inline cards → existing export routes | — |
| Report CD loss | **REUSE** `ReportLossDialog` (`_cd-dialogs.tsx`) | `CDLossReportController@store` |
| Drill-in (Administration row) | **BUILD** read-only `MedsWizardDialog` single-step | — (+ deep-link `/emar/mar?client_id=`) |

## 2. Hero spec
Eyebrow live-ping `LIVE REPORTING · refreshed`; title "Medication reporting for {site underlined / your services}, {period}"; description (doses, compliance %, missed/refused, CD variances + errors); stats **Compliance · Doses recorded · Open errors · CD variances**; badges (CD variances / errors open / competencies expiring); actions **Export** (→ CSV) + **Print MAR & CD register** (→ PDF); footer = period presets (7/30/90/this-month/custom) + search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | parity | `?site_id` → filter client-scoped panels (admin/prn/cd/errors via `whereHas client.site_id`) + `site_brand_colour` + `sites` + `active_site` | feature: brand colour + payload |
| reasons | no coded-reason breakdown | `reasonBreakdown` (refused/withheld/missed grouped by reason_code → refusal/clinical/omission classes + by-class totals) | feature: reasonBreakdown present |
| CD loss | modal reuse | add `cdMedications` (CdMedication shape) so the reused `ReportLossDialog` has its picker | — |
- Stock/Rounds/Competency stay point-in-time/global (handoff G3) — site filter not threaded there.

## 4. Cross-module (§6)
- Export buttons → existing CSV/PDF routes (current filters as query params). "Report CD loss" → reused `ReportLossDialog` (shared with Page 6 Controlled Drugs). Audit-tools packs link to the real PDF/CSV exporters. Drill-in deep-links `/emar/mar`.

## 5. Retire → fold into modals
- New-tab `window.open` exports → real download links/forms (same routes). Loose export buttons → per-panel Export + the Audit-tools pack grid. No routes removed.

## 6. Execution checklist
- [ ] Backend: `index()` — `?site_id` filter (client-scoped panels) + brand colour + sites + `reasonBreakdown` + `cdMedications`. Test.
- [ ] Frontend: `Reports.tsx` rebuild (brand hero + 9-tab TabStrip + filter bar + panels reusing OpsStatCard/DonutChart/recharts + audit-tools links + reuse ReportLossDialog + read-only drill-in).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d: the only built modals are the **reused** `ReportLossDialog` (real write) + a **read-only** drill-in. 
- **OMITTED (no backend — not stubbed):** Custom-reports tab, **Build report** + **Schedule report** modals, Saved/Scheduled report lists — require new `EmarSavedReport` + `EmarReportSubscription` models + persist/run/schedule endpoints that don't exist. Flagged for a future backend slice.
- **Deferred (handoff gaps):** error-rate-per-1000 (computed client-side from existing totals — kept), resident-centric audit summary table (Audit-tools shows export packs instead), PDF "audit pack" bundling, threading site filter through stock/rounds/competency (point-in-time). Reasons: new models/endpoints / point-in-time data. Core = brand 9-tab reporting surface + all real data panels + coded-reason breakdown + real exports + reused CD-loss modal.
