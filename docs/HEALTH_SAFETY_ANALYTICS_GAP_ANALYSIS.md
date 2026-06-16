# Health & Safety Analytics — Gap Analysis

> **REGION SCOPE — NEW ZEALAND ONLY.** All regulatory framing is Aotearoa New Zealand: WorkSafe NZ, the Health and Safety at Work Act 2015 (HSWA), WorkSafe **notifiable events**, the Health and Safety at Work (Hazardous Substances) Regulations 2017, Ngā Paerewa Health and Disability Services Standard (NZS 8134:2021), and ACC. Frequency metrics are **LTIFR / TRIFR** — never the US "TRIR". No CQC / RIDDOR / HSE / COSHH / OSHA.

**Date:** 16 June 2026 · **re-verified against current code.**
**Page:** `/health-safety/analytics` → `HealthSafetyDashboardController@analytics` (line 207) → `resources/js/pages/health-safety/analytics.tsx` (303 lines).
**Companions:** `HEALTH_SAFETY_ANALYTICS_BACKEND_AUDIT.md` (data-source verification, this folder), the design drop `.design-drops/health-safety-redesign/design_handoff_health_safety_analytics/` (`HANDOFF.md`, the `.dc.html` reference).

**Worklist legend:** `[ ]` open · `[x]` done (commit referenced) · `[~]` deferred (reason given).

## Current state (verified 16 June 2026)

The page is a real but **under-built** report. It renders a basic `PageHero` (title + `BarChart3` icon + 3 stats), a detached `<Card>` with two raw `<input type="date">` + Apply, then **hand-rolled CSS bar lists / number blocks / `<table>`s** for incidents-by-type, hazard-by-risk blocks, near-miss-vs-incident ratio, a root-cause `<table>` with `<div>` progress bars, a site-comparison table, and injury type / body-part lists. The controller supplies `incident_data`, `hazard_data`, `injury_data{by_type,by_body_part}`, `site_comparison`, `root_cause_data`, `filters{from,to}`.

It **does not use `recharts`**, has **no leading-vs-lagging framing**, **no trend-over-time series**, **no site/role filter** (date only), **no drill-down**, **no export**. It reads as a static report, not the app's analyse/explore surface. The data layer for everything the redesign needs **exists** (see the backend audit) — the gap is in surfacing and shaping it.

---

## A. Charts & visual quality

- [ ] **A1** Replace hand-rolled CSS bars/number-blocks/`<table>`s with `recharts` + `DonutChart`/`StatTile`, toned with `--chart-1..5` / `--status-*`.
- [ ] **A2** Add donuts (incident severity, hazards-by-risk) with hover-to-focus + legend + %. *(ops-charts `DonutChart` is static — build the hover-focus donut with recharts `Pie`/`activeIndex`.)*
- [ ] **A3** Add trend lines/areas (every current chart is a single-period snapshot).
- [ ] **A4** Tone charts with semantic tokens only (`var(--chart-1)` … `var(--status-critical)`), never raw hex/oklch.
- [ ] **A5** Chart tooltips + `aria-label`s + a data-table fallback for screen readers.

## B. Hero command bar

- [ ] **B1** Turn the hero into a command bar: eyebrow (pulsing dot + `SAFETY ANALYTICS · {range}`), meta row (Calendar/MapPin/Shield), `--primary` gradient (shared with the dashboard hero).
- [ ] **B2** Headline **LTIFR · TRIFR · near-miss ratio · compliance %** stat tiles with tone + period-over-period delta (not Total Incidents / Near Misses / Ratio).
- [ ] **B3** NZ compliance badges: WorkSafe notifiable · {n awaiting} · Ngā Paerewa NZS 8134 · Hazardous Substances SDS · Fire & evacuation · First-aid cover. Dot **and** label (never colour-only).
- [ ] **B4** Replace the detached `<input type=date>` card with a **range** segmented control (30d / Quarter / 6 months / YTD / Custom from→to) in the hero footer band.
- [ ] **B5** Add a **Site** `EntityFilter` (onDark) in the footer band.
- [ ] **B6** Add a **Governance / Manager / Frontline** role lens toggle (shared idiom with the dashboard).
- [ ] **B7** Add export affordances in the hero (Export CSV/PDF · Board pack · WorkSafe register).
- [ ] **B8** Optional "this period" summary strip in the footer band.

## C. Leading-vs-lagging trend framing (what makes it "analytics")

- [ ] **C1** Add a `TabStrip` (Overview / Trends / Breakdowns / Sites / Governance) — structural split.
- [ ] **C2** LTIFR / TRIFR trend over time (per backend audit §9; LTIFR truthful, TRIFR via documented heuristic).
- [ ] **C3** Near-miss : incident ratio trend.
- [ ] **C4** Incidents/30d trend.
- [ ] **C5** Hazard burn-down (opened vs closed + running open) — `site_hazards.created_at`/`closed_at`.
- [ ] **C6** Corrective-action closure trend (avg days + % on time) — `hs_corrective_actions`.
- [ ] **C7** Training / audit compliance % trend — `staff_training_records`.
- [ ] **C8** WorkSafe notifiable over time (notified vs awaiting) — `notifiable_incidents`.
- [ ] **C9** Worker participation trend (engagement + consultation) — `hs_committee_meetings` / `hs_consultations`.
- [ ] **C10** Leading-vs-lagging scorecard (proactive vs reactive metrics, with deltas).

## D. Drill-down & standardised rows

- [ ] **D1** Make chart segments / breakdown rows / donut slices / site rows clickable → read-only **detail modal** (Add-Client `WizardShell` chrome: context-facet rail, record-list body, Options bar Export + Open register).
- [ ] **D2** Right-click `ShiftContextMenu` (View detail · View client · View staff · Open corrective action · Export) on every drillable element.
- [ ] **D3** "Open {register}" links from a breakdown into the matching register (`/health-safety/events`, `/injuries`, hazards).
- [ ] **D4** Make root-cause a proper **Pareto** (ordered bars + cumulative-% line + 80% reference), not a `%` table — add `cumulative_pct` to `root_cause_data`.

## E. Export & governance

- [ ] **E1** Export the active view (CSV; PDF via print) — new `analyticsExport()` mirroring `EmarReportController::export`.
- [ ] **E2** One-click governance packs wired to the existing JSON endpoints under `/health-safety/reports/*`: board summary, WorkSafe register, investigation outcomes, corrective-action traceability, risk-assessment register.
- [ ] **E3** Worker-participation & training analytics (leading) — HSR/committee engagement, consultation completion, competency/training completion trend.
- [ ] **E4** Site **heatmap / league** beyond a flat table — sortable league + intensity-shaded hotspot grid.

---

## Backend gaps (verified — detail in `HEALTH_SAFETY_ANALYTICS_BACKEND_AUDIT.md`)

- [x] **BE1** `analytics()` returns monthly `trends[]` (LTIFR/TRIFR, near-miss ratio, incidents, hazards opened/closed/open, CA avg-days + %-on-time, compliance %, worker engagement + consultation, WorkSafe notified/awaiting) — `HsAnalyticsService::buildTrends`. *(commit b-payload)*
- [x] **BE2** **LTIFR / TRIFR calc** from `timesheets.total_hours` (site-scoped, `submitted`/`approved`; rostered-shift fallback flagged via `hours_meta.source`). LTIFR truthful; TRIFR via the documented recordable heuristic (audit §9); `null` (not 0) when hours missing.
- [x] **BE3** **Root-cause Pareto** — `rootCausePareto()` returns ordered `count`/`pct`/`cumulative_pct`.
- [x] **BE4** 🐞 **Site-scoping bug fixed** — `siteComparison()` joins `clients` and groups by `clients.site_id` (one query, not N); per-site `ltifr`/`trifr` added.
- [x] **BE5** **Site- & role-scoped payloads** — `build(?siteId, from, to, lens)`; every query site-scoped; `role_note` + scorecard for the lens.
- [x] **BE6** **Worker-participation / training series** — `engagementByMonth` / `consultationByMonth` / `complianceByMonth`.
- [x] **BE7** **CSV export endpoint** — `analyticsExport()` streams record-level CSV (incidents/injuries/hazards/sites/root_cause) via `HsAnalyticsService::exportRows`, route `health-safety.analytics.export`. Governance pack JSON endpoints already exist under `/health-safety/reports/*` (front-end wiring = E2).

---

## Non-NZ content scan (audit)

- [x] **No non-NZ frameworks present** in `analytics.tsx`, the controller, the H&S models, or H&S services (grepped 16 June 2026). No CQC / RIDDOR / HSE / COSHH / OSHA / TRIR.
- [ ] **Guard against drift** — `site_comparison.compliance_score` + `drill_status` copy must be framed against NZ obligations (emergency-drill cadence, Ngā Paerewa NZS 8134:2021), surfaced in a UI tooltip; not an overseas inspection regime.

---

## Definition of Done (mirrors the work order §5)

- [ ] Backend audit + refreshed gap analysis committed; A–E either `[x]` or `[~]` with reason.
- [ ] `analytics()` returns real, site- & role-scoped payloads incl. trends, LTIFR/TRIFR (honest `hours_source`), root-cause cumulative %, compliance, WorkSafe notifiable, worker-participation/training; site-scoping bug fixed; props typed.
- [ ] `analytics.tsx` rebuilt to the design with existing primitives + recharts; charts match the prototype (Pareto, dual-line frequency, hazard burn-down, donuts); semantic tokens only.
- [ ] Drill-in detail modal (Add-Client chrome) + right-click menu + export on every drillable element.
- [ ] Build, types, lint pass; no non-NZ regulatory references in touched code.
- [ ] Dashboard + analytics feel like one product (shared hero/tab/filter/role idioms).
