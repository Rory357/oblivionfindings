# Health & Safety Dashboard — Current State Audit (pre-redesign)

Audited: 2026-06-16
Scope file: `resources/js/pages/health-safety/dashboard.tsx` (1,675 lines)
Controller: `app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php@index`
Target redesign locale: **NEW ZEALAND ONLY** (WorkSafe NZ, HSWA 2015, Ngā Paerewa NZS 8134:2021, ACC, LTIFR/TRIFR).

---

## TASK 1 — Current frontend audit

### Overall structure (top → bottom render order)

The page is a single default-export component `HealthSafetyDashboard`, wrapped in `<AppLayout>` with breadcrumbs `Health & Safety > Dashboard`. Inside one `flex flex-col gap-6 p-6` container, it renders, in order:

1. **Hero header** — `<PageHero>` (design-system component) with title, description, a `Shield` icon, **4 inline `stats`** (Incidents 30d, Open Hazards, Overdue Actions, Drill Compliance %) and an `actions` slot containing 2 buttons (Report Incident → `/incidents/create`, Report Hazard → `/compliance/hazards`).
2. **KPI grid** — 13 cards driven by a static `KPI_CONFIG` array, `grid sm:grid-cols-2 lg:grid-cols-4`. Each card is a `<Link>` wrapping a `<Card>` with conditional colour (green/amber/red) computed from the value. KPIs: incidents_30d, near_misses_30d, open_hazards, overdue_actions, workplace_injuries_ytd, lost_time_days_ytd, days_since_notifiable, drill_compliance_pct, active_alerts, open_safeguarding, fleet_incidents_30d, fleet_unresolved, staff_compliance_pct.
3. **Charts row 1** (`lg:grid-cols-3`) — (a) **Incident Trends (12 months)** stacked gradient `AreaChart` (recharts), col-span-2; (b) **Severity Breakdown** donut `PieChart` with center total + custom legend dots.
4. **Radial progress gauges** (`sm:grid-cols-3`) — 3 `RadialBarChart` gauges: Drill Compliance, Staff Compliance, Resolution Rate (Resolution Rate is computed client-side from `recent_incidents` status).
5. **H&S Backbone status** (`sm:grid-cols-2 lg:grid-cols-4`) — only rendered if `backbone` prop present. 4 linked cards: Active Investigations, Open Corrective Actions, Active Risk Assessments, Open H&S Events (the last surfaces "WorkSafe notifiable" — already NZ-correct).
6. **Charts row 2** (`lg:grid-cols-2`) — (a) **Hazard Risk Distribution** horizontal `BarChart` (Extreme/High/Medium/Low); (b) **Site Drill Compliance** plain HTML `<table>` (top 8 sites, Last Drill / Days / Status badge).
7. **Monthly Comparison** mini `LineChart` (current vs previous month, current vs prev derived from `incident_trends.slice(-2)`). Conditionally rendered.
8. **Recent Activity** (`lg:grid-cols-3`) — 3 `<Card>` lists: Recent Incidents, Recent Hazards, Recent Fleet Incidents. Each row is a `<Link>` to the detail page with severity/risk + status badges.
9. **Quick Actions** — bottom `<Card>` with 7 `<Button>` links from a static `QUICK_ACTIONS` array (Report Incident, Report Near-Miss, Report Hazard, Record First Aid, Start Lone Worker, Report Safeguarding, Log Fleet Incident).

### Design-system components used vs absent

| Component | Used? | Notes |
|---|---|---|
| **PageHero** (`@/components/page`) | ✅ YES | Used for the hero, but **as a simple KPI/stat header**, not a command-centre. Passes `title`, `description`, `icon`, `stats[]` (4), `actions`. |
| **TabStrip** (`rostering/tab-strip.tsx`) / **page-tabs** (`@/components/page` `page-tabs.tsx`) | ❌ NO | Dashboard is a single scrolling page — no tabs. A design-system `PageTabs`/`TabStrip` exists and is unused here. |
| **WizardShell / Wizard modals** (`meds/wizard-shell.tsx`, `finance/wizard.ts`, `hr/wizard.ts`, `wizard-stepper.tsx`) | ❌ NO | Dashboard launches **no modals at all** — every "report/log" action is a plain `<Link>` navigation to another page. No `<Dialog>` import. |
| **EntityFilter** (`rostering/entity-filter.tsx`) | ❌ NO | No site/client/date filtering on the dashboard. All data is org-wide and period-fixed in the controller. |
| **ShiftContextMenu** (`rostering/shift-context-menu.tsx`) / any right-click ctx menu | ❌ NO | No context menus. Recent-activity rows are simple links. |
| Charts: **recharts** | ✅ | Area/Pie/Bar/Line/RadialBar + custom tooltips. |
| **Card / Badge / Button** (shadcn ui) | ✅ | Heavy use. |
| `formatDateLong` (`@/lib/datetime`) | ✅ | Date formatting. |

### Hero verdict
**Simple KPI/stat header, NOT a command-centre.** `PageHero` shows 4 hard-wired stats + 2 action buttons. There is no live alert strip, no notifiable-incident countdown banner, no command palette, no inline quick-record wizards. The "command-centre" feel of other redesigned modules (eMAR etc.) is **absent**.

### Redesign features — present vs absent (rough)
**Present already:** PageHero shell; rich KPI grid w/ colour thresholds; recharts trend/severity/hazard/comparison/gauge visualisations; H&S backbone summary (events/investigations/corrective-actions/risk-assessments/training); WorkSafe-notifiable surfacing; recent-activity lists; quick-action launcher (link-based).
**Absent / to build:** TabStrip layout; in-page **wizard/dialog quick-record** flows (everything currently navigates away); EntityFilter (site/period scoping); ShiftContextMenu / right-click actions; a true command-centre hero (alert strip, notifiable countdown banner); LTIFR/TRIFR statutory rate tiles (only raw counts + lost-time days today); ACC framing; Ngā Paerewa / HSWA framing in UI copy.

### Exact props destructured (backend → frontend contract)
From `Props` type + the component signature, the dashboard reads these **9 props** (`recent_fleet_incidents` defaults to `[]`, `backbone` optional):

```ts
kpis: Record<string, number>
incident_trends: Array<{ month: string; count: number; types: Record<string, number> }>
severity_breakdown: Record<string, number>
hazard_summary: Record<string, number>
site_drill_compliance: Array<{ id; name; last_drill_date: string|null; days_since: number|null; status: 'compliant'|'due_soon'|'overdue' }>
recent_incidents: Array<{ id; type; severity; status; occurred_at }>
recent_fleet_incidents?: Array<{ id; incident_type; severity; status; occurred_at; location; asset: {id;name}|null }>
recent_hazards: Array<{ id; type; risk_rating; status; site_name }>
backbone?: {
  events: { open_events; open_events_high_critical; events_period; worksafe_notifiable_open; events_by_severity: Record<string,number> }
  investigations: { active_investigations; overdue_investigations; awaiting_review }
  corrective_actions: { open_actions; overdue_actions; awaiting_verification }
  risk_assessments: { active_assessments; high_extreme_active; due_for_review }
  training: { total_requirements; blocking_requirements; staff_non_compliant }
}
```

**`kpis` keys** (from controller `$kpis` array): `incidents_30d`, `near_misses_30d`, `open_hazards`, `overdue_actions`, `workplace_injuries_ytd`, `lost_time_days_ytd`, `days_since_notifiable`, `drill_compliance_pct`, `active_alerts`, `open_safeguarding`, `fleet_incidents_30d`, `fleet_unresolved`, `staff_compliance_pct` (note: `staff_compliance_pct` is currently **hard-coded to 0** in the controller).

Controller renders `Inertia::render('health-safety/dashboard', [...])` with prop keys: `kpis`, `incident_trends`, `severity_breakdown`, `hazard_summary`, `site_drill_compliance`, `recent_incidents`, `recent_hazards`, `recent_fleet_incidents`, `backbone`. Backbone comes from `HsDashboardService::getDashboardSummary($thirtyDaysAgo)`.

---

## TASK 2 — NZ-only regulatory content check (overseas terms)

Searched case-insensitively across: `resources/js/pages/health-safety/**`, `resources/js/components/health-safety/**`, `app/Http/Controllers/HealthSafety/**`, `app/Models/Hs*.php`, `app/Models/*Incident*.php`, `app/Models/*Hazard*.php`, `app/Services/HealthSafety/**`, `routes/health-safety.php`, `routes/incidents.php`, `docs/*health*` / `docs/*safety*`. Also spot-checked `resources/js/pages/incidents/**`, `app/Http/Controllers/*Incident*.php`, and `resources/js/pages/health-safety/analytics.tsx`.

`HSE` was matched as a standalone token (`\bHSE\b`) plus the long form "Health and Safety Executive" / "Health & Safety Executive", to avoid substring false positives like "these"/"chse".

| Term | Hits |
|---|---|
| **CQC** | **ZERO hits** |
| **RIDDOR** | **ZERO hits** |
| **HSE** (standalone acronym) / "Health and Safety Executive" | **ZERO hits** |
| **COSHH** | **ZERO hits** |
| **OSHA** | **ZERO hits** |
| **TRIR** (US metric) | **ZERO hits** |

**Result: the H&S surface is already clean of UK/US/overseas regulatory references.** No `file:line` hits to strip. NZ-correct terminology is already present where it matters — e.g. `worksafe_notifiable_open` (backbone events), the "WorkSafe notifiable" label in the dashboard, the `health-safety/reports/worksafe-register` route, and the `NotifiableIncident` model (`App\Domain\Governance\Models\NotifiableIncident`) used for the "Days Since Notifiable" KPI.

`TRIFR` / `LTIFR` were **not** flagged (correct NZ metrics) — and note neither currently appears as a computed rate tile on the dashboard, so the redesign will be *adding* them, not replacing overseas equivalents.

---

## TASK 3 — Existing reusable H&S components + write-endpoint inventory

### Existing H&S dialog/wizard/modal components (reuse candidates)

There are **no shared/exported** H&S dialog or wizard components. The two files under `resources/js/components/health-safety/` are display widgets, not forms:
- `resources/js/components/health-safety/event-timeline.tsx`
- `resources/js/components/health-safety/risk-matrix.tsx`

All create/record forms are **page-local inline `<Dialog>`/`<DialogContent>` blocks** (shadcn dialog) defined inside each list/detail page — not extracted into reusable components. They are good *patterns to copy* but cannot be imported as-is:
- `health-safety/first-aid/index.tsx` — first-aid record dialog (1 dialog).
- `health-safety/lone-workers/index.tsx` — 6 dialogs (start session, check-in, end, emergency, acknowledge alert, resolve alert).
- `health-safety/worker-participation/index.tsx` — ~14 dialogs (representatives, committees, meetings, consultations, attendees, minutes, etc.).
- `health-safety/injuries/show.tsx` — RTW plan / capacity assessment / modified-duty dialogs.
- `health-safety/ppe/index.tsx` — add item / add type / allocate / inspection dialogs.
- `health-safety/restraints/index.tsx` and `substances/show.tsx` — also contain inline dialogs.

**Design-system pieces the redesign should pull in** (exist, currently unused by the dashboard): `@/components/page` `page-tabs.tsx` (TabStrip), `meds/wizard-shell.tsx` (multi-step wizard shell pattern), `rostering/entity-filter.tsx` (EntityFilter), `rostering/shift-context-menu.tsx` (right-click context menu). The dashboard itself uses only `PageHero` from the design system today.

### The 9 workflows — write-endpoint status

All 9 have a **working POST write endpoint already** (verified: route registered AND controller method exists). None need building from scratch; the redesign work is wiring them into in-page wizards/dialogs on the dashboard rather than link-outs.

| # | Workflow | POST endpoint (route name → controller) | Status |
|---|---|---|---|
| 1 | Report incident / near-miss | `POST /incidents` `incidents.store` → `IncidentController@store` (line 144). Near-miss = same endpoint with `type=near_miss`. | ✅ EXISTS |
| 2 | Log hazard + risk assessment | `POST /hazards` `sites.hazards.store` → `SiteHazardController@store` (in **`routes/sites.php`** L132, perm `hazards.create`). H&S risk-assessments are read-only views (`HsEventController@riskAssessments`); structured RA write lives in the hazard/event flow. | ✅ EXISTS (hazard); RA capture via hazard/event |
| 3 | Record first aid | `POST /health-safety/first-aid` `health-safety.first-aid.store` → `FirstAidController@store` (L63). | ✅ EXISTS |
| 4 | Log restraint | `POST /health-safety/restraints/events` `…restraints.events.store` → `RestraintController@storeEvent` (L73) (+ `plans.store`). | ✅ EXISTS |
| 5 | Record drill | `POST /health-safety/drills` `…drills.store` → `EmergencyDrillController@store` (L120) (+ participants/findings). | ✅ EXISTS |
| 6 | Injury → return-to-work | `POST /health-safety/injuries` `…injuries.store` → `ReturnToWorkController@store` (L79); RTW plan `POST /injuries/{injury}/rtw-plans` `…rtw-plans.store`. | ✅ EXISTS |
| 7 | Add hazardous substance | `POST /health-safety/substances` `…substances.store` → `HazardousSubstanceController@store` (L76) (+ SDS / storage / exposure sub-routes). | ✅ EXISTS |
| 8 | Lone-worker check-in | `POST /health-safety/lone-workers/sessions/{session}/check-in` `…sessions.check-in` → `LoneWorkerController@checkIn` (L153) (+ `startSession` L123, end, emergency). | ✅ EXISTS |
| 9 | Worker-participation meeting | `POST /health-safety/worker-participation/committees/{committee}/meetings` `…committees.meetings.store` → `WorkerParticipationController@storeMeeting` (L155) (+ complete/cancel/minutes). | ✅ EXISTS |

### Notable wiring gaps for the redesign (not endpoint-missing, just mis-pointed)
- Dashboard **"Report Hazard"** (hero action + Quick Action) links to **`/compliance/hazards`** (a list page), not to a create flow. The real create lives at `GET /hazards/create` (`sites.hazards.create`) / `POST /hazards` (`sites.hazards.store`). Redesign should target the create flow / an inline wizard.
- **"Report Near-Miss"** quick action links to `/incidents/create` (generic) — same store endpoint, but type pre-selection is left to the user.
- `staff_compliance_pct` KPI is **hard-coded `0`** in the controller — the Staff Compliance gauge/tile shows 0% regardless of real data.
- No LTIFR/TRIFR computation exists yet (only `workplace_injuries_ytd` + `lost_time_days_ytd` raw counts) — statutory rate tiles are net-new for the redesign.
