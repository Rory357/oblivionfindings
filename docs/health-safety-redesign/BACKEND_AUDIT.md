# Health & Safety Dashboard — Backend Audit (Command-Centre Redesign)

**Scope:** Audit the current `HealthSafetyDashboardController@index` payload (and everything it touches) against the redesign design contract (`HANDOFF.md` / `README.md`), per design value, with EXISTS / PARTIAL / MISSING and the concrete source file:line. NZ-only frameworks (WorkSafe / HSWA / LTIFR / TRIFR / Ngā Paerewa / Hazardous Substances Regs 2017 / ACC).

## Summary

The backbone data model is unusually strong: a normalised `hs_events` table (with `worksafe_notifiable`, severity, category, site/client/staff/shift FKs), full investigation + corrective-action + risk-assessment + training tables, and a read-only `HsDashboardService` that already aggregates them. The current `index()` payload **does ship the `backbone` block** described in the brief (events incl. `worksafe_notifiable_open` + `events_by_severity`, investigations, corrective_actions, risk_assessments, training) plus `incident_trends`, `severity_breakdown`, `hazard_summary`, `site_drill_compliance`, `recent_incidents`, `recent_hazards`, `recent_fleet_incidents` — all **VERIFIED present** in code. What is comprehensively **MISSING** is the analytics layer the redesign hero is built on: there is **no LTIFR / TRIFR / injury-severity-rate / near-miss-ratio / actions-closed-on-time / days-since-LTI / training-audit-% calculation anywhere** (the only `*KPIService` in the app is `App\Domain\Finance\Services\FinancialKPIService`, unrelated). The `index()` method also reads **no request params at all** — no `?from/?to/?site/?lens` — so G3 (role lens) and G4 (period range + site) are entirely absent. Worklist payloads (G6) and a unified expiring feed (G5) do not exist as such, though every underlying row + date column they need is present. The trend chart ships **incident bars only** — no TRIFR/LTIFR lines. Two of the five compliance badges (Ngā Paerewa certification, first-aid cover) have **no backing data** at all.

## Hours-worked source for the LTIFR / TRIFR denominator (CRITICAL FINDING)

**A worked-hours source EXISTS. Three candidates, in descending order of fitness for the `× 1,000,000` denominator:**

1. **BEST — `App\Models\BillingEntry` (`billing_entries` table).** It has a **real, SQL-summable `hours` decimal(2) column** (`app/Models/BillingEntry.php:23,44`) plus `site_id`, `service_date` (`:18,22`), and `billing_period_start` / `billing_period_end` (`:37-38`). This is the **only worked-hours source with a materialised numeric column**, so the G1 service can do `BillingEntry::whereBetween('service_date', [$from,$to])->where('site_id',$site)->sum('hours')` in one query. It is already used this exact way: `ReportingService.php:146` (`SUM(hours) ... GROUP BY site_name_snapshot`) and `BillingService.php:216` (`->sum('hours')`). Caveat: a `BillingEntry` row exists only once a timesheet has been costed/billed, so it lags un-billed periods — fine for board LTIFR/TRIFR over closed periods, weaker for "this week" live.

2. **`App\Models\Timesheet` (`timesheets` table) via the `total_hours` accessor.** `Timesheet::getTotalHoursAttribute()` computes `(ends_at − starts_at) − break_minutes` in PHP (`app/Models/Timesheet.php:401-409`); there is **no raw `hours`/`total_hours` column** (confirmed by the model comment at `:303-304` and `:308`). It therefore **cannot be SUM'd in SQL** — it must be hydrated and summed in PHP, exactly as `ReportingService.php:60,65` does (`->get()->sum(fn ($t) => $t->total_hours)`). Has `site_id` + `shift_site_id` (`:36-37`) and `work_date` (`:35`) for per-site/per-period scoping. Most accurate "hours actually recorded" source; just not free to aggregate at scale.

3. **`App\Models\Shift` actuals via attendance.** `ShiftReportingService::shiftWorkedMinutes()` (`app/Services/Operations/ShiftReportingService.php:757-777`) prefers `HrAttendanceSession` clock-in/out (`clock_in_at`/`clock_out_at` − `break_minutes`) and falls back to the timesheet's `total_minutes`. Highest-fidelity "boots on the ground" hours and already site-scoped (`applyNormalizedShiftSiteConstraint`, `:190-207`), but it is per-shift PHP summation — heaviest to compute org-wide.

**Verdict:** Use **`BillingEntry.hours`** as the LTIFR/TRIFR denominator (one SQL `SUM`, has `site_id` + period columns, already summed this way elsewhere), and fall back to `Timesheet::total_hours` (PHP sum) for periods not yet billed. Both are reusable today; **no new table is required** for G1's denominator. Recommend G1 expose the chosen source behind a `HsKpiService::totalHoursWorked(?from, ?to, ?siteId)` method so the LTIFR/TRIFR formulas have one denominator definition.

---

## 1. Hero — LAGGING stats

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| Incidents (30d) | **EXISTS** | `HealthSafetyDashboardController.php:38` → `kpis.incidents_30d` | `ClientIncident::where('occurred_at','>=',30d)->count()`. Ships today. |
| LTIFR | **MISSING** | — (must compute; LTI source `WorkplaceInjury` `:103` `scopeWorksafeNotifiable` / `:108` `scopeWithLostTime`; denom = hours-worked finding above) | No LTIFR anywhere. G1. Numerator = lost-time injuries (`WorkplaceInjury::withLostTime()` i.e. `lost_time_days > 0`). |
| TRIFR | **MISSING** | — (numerator from `WorkplaceInjury.medical_treatment_type` `app/Models/WorkplaceInjury.php:31` + `injury_type` + lost-time; denom = hours worked) | No TRIFR anywhere. G1. "Recordable" = medical-treatment + restricted-work + lost-time + fatalities — needs a classification rule over `WorkplaceInjury`. |
| Days since last lost-time injury (LTI-free) | **PARTIAL** | `HealthSafetyDashboardController.php:56-59` → `kpis.days_since_notifiable` | Exists but measures **days since last *notifiable* incident** (`NotifiableIncident.occurred_at`), NOT days since last *lost-time injury*. Correct source = most recent `WorkplaceInjury` with `lost_time_days > 0`. G1 (re-point). |

## 2. Hero — LEADING stats

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| Near-miss : incident ratio | **PARTIAL** | counts exist: `:40-42` `kpis.near_misses_30d`; incidents `:38`; backbone `HsDashboardService.php:38` `events_period` | Both operands exist; the **ratio is never computed**. Brief defines it as `near misses ÷ recordable incidents`. G1. |
| Corrective actions closed-on-time % | **MISSING** | data present: `HsCorrectiveAction` has `due_date` `app/Models/HsCorrectiveAction.php:69`, `completed_at` `:70`, `scopeOverdue` `:145`; service exposes `overdue_actions` `HsDashboardService.php:80` | "Closed on time %" = `completed_at ≤ due_date ÷ actions due in period` — **not computed**. Only open/overdue counts exist. G1. |
| Training & audit % | **PARTIAL** | `HsDashboardService.php:116-154` `getTrainingComplianceKpis()` → `backbone.training` (counts non-compliant staff vs `HrStaffComplianceStatus`) | Exposes `staff_non_compliant` + requirement list, **not a single compliance %**. No "audit" dimension at all. `kpis.staff_compliance_pct` is **hardcoded `0`** (`HealthSafetyDashboardController.php:94`). G1. |
| Open hazards | **EXISTS** | `HealthSafetyDashboardController.php:44` → `kpis.open_hazards` | `SiteHazard::whereIn('status',['open','in_progress'])->count()`. Ships today. |

## 3. Compliance badges

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| WorkSafe notifiable (count awaiting) | **EXISTS** | `HsDashboardService.php:49-52` `worksafe_notifiable_open` → `backbone.events.worksafe_notifiable_open`; also `NotifiableIncident.status` (`:91` `isPending`) | Backbone gives open notifiable **event** count. A dedicated "awaiting notification" count is better sourced from `NotifiableIncident` where `status = 'pending'` (`NotifiableIncident.php:89-92`) — that nuance is not yet surfaced. G2. |
| Ngā Paerewa NZS 8134:2021 (Certified) | **MISSING** | — (no certification model/field found anywhere) | No backing data. Static "Certified" badge or a new config/setting needed. Not in any model. |
| Hazardous substances (SDS expiring count) | **PARTIAL** | `SafetyDataSheet.review_date` `app/Models/SafetyDataSheet.php:21,33`; substances `HazardousSubstance` | Column exists to compute it, but **no "expiring within N days" scope** — `SafetyDataSheet` only has `scopeCurrent` (status-based, `:64`). Not in payload. G5. |
| Fire (drills current) | **PARTIAL** | `HealthSafetyDashboardController.php:62-68` `drill_compliance_pct`; per-site `:136-163` `site_drill_compliance`; `EmergencyDrill.scheduled_at/completed_at/drill_type` (`app/Models/EmergencyDrill.php:23,25,20`) | Org-wide drill % + per-site status exist, but **not filtered to fire/evacuation drill_type** and not surfaced as a boolean "current" badge. Re-shape needed. G5-adjacent. |
| First aid (cover OK) | **MISSING** | — (FirstAid register route exists `routes/health-safety.php:52-61`, but no cover/coverage computation; not in payload) | No first-aid cover calculation anywhere. Needs a "qualified first-aiders per site ≥ threshold" rule. |

## 4. Footer "this week" summary

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| Incidents count (this week) | **PARTIAL** | `:38` is **30d** not week; trend `:99-122` is monthly | Value computable from `ClientIncident.occurred_at`, but no **week-scoped** number exists (no period param). G4. |
| WorkSafe-notifiable count (this week) | **PARTIAL** | `backbone.events.worksafe_notifiable_open` `HsDashboardService.php:49`; `NotifiableIncident` | Open count exists; not week-scoped. G2 + G4. |
| Hazards open | **EXISTS** | `:44` `kpis.open_hazards` | Snapshot, not period-bound — acceptable for "open". |
| Drills due | **PARTIAL** | `site_drill_compliance[].status` `:148-154` (`due_soon`/`overdue`); `EmergencyDrill::scopeUpcoming` `app/Models/EmergencyDrill.php:111-114` | Derivable (count sites `status != compliant`), but **not emitted as a scalar**. G5. |
| Lone-workers checked-in | **PARTIAL** | `:71-73` `kpis.active_alerts` (`ControlRoomAlert` source `lone_worker`, unresolved) | Exposes **unresolved lone-worker alerts**, not "all checked in" status. Inverse of what the badge wants; lone-worker sessions live in `LoneWorkerController` (`routes/health-safety.php:238-254`). Re-shape needed. |

## 5. Charts

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| Incident trend — 12 monthly incident bars | **EXISTS** | `HealthSafetyDashboardController.php:97-122` → `incident_trends[]` (`month`,`count`,`types{}`) | 12-month grouped-by-type. Ships today. |
| …+ TRIFR line | **MISSING** | — | No TRIFR series. Requires monthly TRIFR = recordables/hours per month. G1. |
| …+ LTIFR line | **MISSING** | — | No LTIFR series. Same. G1. |
| Near-miss : incident ratio donut | **MISSING** | operands exist (`:40` near misses; `:38` incidents) | Ratio + donut series not produced. G1. |
| Hazard burn-down line | **MISSING** | `SiteHazard` has `created_at` + `closed_at` (`app/Models/SiteHazard.php:64-63`) and `status` history fields | No time-series of open hazards emitted (only a current `hazard_summary` by `risk_rating`, `:130-134`). G1/G5. |
| Drill compliance gauge % | **EXISTS** | `:62-68` `kpis.drill_compliance_pct` | Single % present. |
| Training & audit gauge % | **PARTIAL** | `backbone.training` `HsDashboardService.php:142-153`; `kpis.staff_compliance_pct` hardcoded `0` `:94` | Non-compliant **count** exists; **% not computed**, audit dimension absent. G1. |
| Severity breakdown donut | **EXISTS** | `:124-128` `severity_breakdown` (by `ClientIncident.severity`); also backbone `events_by_severity` `HsDashboardService.php:44-48` | Two sources ship today. |
| Incidents by category bars | **PARTIAL** | backbone `events_by_category` `HsDashboardService.php:39-43`; analytics `incident_data` by `type` `:217-221` (analytics page only) | Available on the **backbone** as `events_by_category` and on the separate `/analytics` page — **not on the index `incident_trends`/category shape the chart wants**. Easy to surface. |
| Site safety league (incidents + open hazards per site) | **PARTIAL** | `site_drill_compliance[]` `:136-163` (per-site, but drill only); `analytics::siteComparison` `:245-283` (incidents+hazards+lost-time+score) | A proper league EXISTS on `/analytics` (`site_comparison`), **not on index**. Note bug: `analytics` `total_incidents` (`:246`) is **not filtered by `$site->id`** — counts all incidents per row. Must move to index + fix scoping. G4. |

## 6. Worklists (G6)

| Design value | Status | Source file:line | Notes / Gap-id |
|---|---|---|---|
| `overdue_corrective_actions[]` (id, owner, due/status, client/staff ids) | **MISSING (data ready)** | `HsCorrectiveAction`: `scopeOverdue` `:145-150`, `assigned_to_user_id` `:63`, `due_date` `:69`, `status` `:68`; parent `HsEvent` carries `client_id`/`staff_id` `app/Models/HsEvent.php:71-72` | Only a **count** (`overdue_actions` `HsDashboardService.php:80`) is emitted — no row array. The linked client/staff for context-menu jumps live on the parent `HsEvent`, so the payload must join `hs_event` → `client_id`/`staff_id`. G6. |
| `open_investigations[]` | **MISSING (data ready)** | `HsInvestigation`: `scopeActive` `:178`, `scopeOverdue` `:188`, `lead_investigator_id` `:99`, `target_completion_date` `:101`, `status` | Only counts (`active_investigations`, `overdue_investigations`, `HsDashboardService.php:62-63`). No row array. client/staff via parent `HsEvent`. G6. |
| `notifiable_events[]` | **MISSING (data ready)** | `NotifiableIncident` (full model `app/Domain/Governance/Models/NotifiableIncident.php`): `status`, `occurred_at`, `notified_at` `:31`, `notification_reference` `:32`, `site_preserved` `:38`, `related_incident_id` `:23`; or `HsEvent::worksafeNotifiable()` `:223` | Neither model emits a worklist array on index. `NotifiableIncident` is the richer source (already has `site_preserved`, `notification_deadline`, ≥5yr via `SoftDeletes`). G2 + G6. |
| `expiring[]` (unified) | **MISSING (data ready)** | risk: `HsRiskAssessment::scopeDueForReview` `app/Models/HsRiskAssessment.php:148-153` (`review_due_at`); SDS: `SafetyDataSheet.review_date` `:21`; drills: `EmergencyDrill.scheduled_at` `:23`; training: `HsTrainingRequirement` | All four date sources exist but are **not unified** into one `expiring[]` feed. G5. |

**Worklist context-menu jump requirement (client_id/staff_id):** `HsEvent` carries `client_id` + `staff_id` directly (`HsEvent.php:71-72`), and corrective-actions/investigations belong to an event — so the linkage for "View client / View staff" is available via the event FK. `NotifiableIncident` links to `ClientIncident` via `related_incident_id` (`:84-87`). The data is there; it is purely a serialization gap.

## 7. Backend TODO gaps G1–G6 (verdict)

| Gap | Title | Status | Evidence |
|---|---|---|---|
| **G1** | KPI calc service (LTIFR/TRIFR/injury_severity_rate/near_miss_ratio/actions_closed_on_time_pct/days_since_lti/training_audit_pct) | **MISSING — build new** | No H&S KPI service exists (`grep` of `ltifr|trifr|frequency_rate|KpiService` returns only `App\Domain\Finance\Services\FinancialKPIService`). All seven metrics absent. Inputs all exist: `WorkplaceInjury` (LTI/recordable), `BillingEntry.hours` (denom), `HsCorrectiveAction` (closed-on-time), `ClientIncident` near-miss/incident counts, `HrStaffComplianceStatus` (training). |
| **G2** | Notifiable-event flagging (`notification_status` awaiting/notified + `worksafe_ref` + `notified_at` + `site_preserved` + ≥5yr retention) | **PARTIAL — schema already present** | `NotifiableIncident` model already has `status` (pending/notified/closed), `notified_at` (`:31`), `notification_reference` (`:32`), `site_preserved` (`:38`), `notification_deadline` (`:33`), and `SoftDeletes` (≥5yr retention) (`:14`). Plus `HsEvent.worksafe_notifiable`/`worksafe_status`/`worksafe_reference` (`HsEvent.php:73,76-77`). What's missing: **auto-classification against the three HSWA thresholds** (death / notifiable injury-or-illness / notifiable incident) and surfacing the `awaiting` count + worklist. No invented schema needed. |
| **G3** | Role lens (`?lens=governance\|manager\|frontline`) | **MISSING** | `index()` reads **no `lens` param** (`HealthSafetyDashboardController.php:30-32` ignores `$request` entirely). No role-scoping of props. Build new. |
| **G4** | Period range + site params (`?from=&to=&site=`) | **MISSING on index** | `index()` is a fixed snapshot — reads no params; all windows hardcoded (`30d` `:33`, `startOfYear` `:34`, `6mo` `:35`, `12mo` `:98`). NOTE: the sibling `analytics()` method **does** accept `?from/?to` (`:209-214`) and can be a template; but it has no `?site` and the index has neither. Build new on index. |
| **G5** | Unified expiring feed | **MISSING — sources ready** | Four expiry date columns exist (risk `review_due_at`, SDS `review_date`, drill `scheduled_at`, training requirement) but no unified `expiring[]`. Build aggregator. |
| **G6** | Worklist payloads (overdue_corrective_actions/open_investigations/notifiable_events) | **MISSING — data ready** | Service currently returns **counts only** (`HsDashboardService` get*Kpis). Models expose every needed field + scopes (`scopeOverdue`, `scopeActive`, owner/due/status). Add row-array builders + join `HsEvent` for client/staff ids. |

## Appendix — `backbone` block claim verification (all confirmed present in code)

Emitted by `HealthSafetyDashboardController.php:184-185,200` via `HsDashboardService::getDashboardSummary()` (`HsDashboardService.php:160-169`):

| Claimed key | Present? | Source |
|---|---|---|
| `backbone.events` incl. `worksafe_notifiable_open` | ✅ | `HsDashboardService.php:49-52` |
| `backbone.events.events_by_severity` | ✅ | `HsDashboardService.php:44-48` |
| `backbone.events.events_by_category` | ✅ (bonus) | `HsDashboardService.php:39-43` |
| `backbone.investigations` | ✅ | `HsDashboardService.php:59-70` |
| `backbone.corrective_actions` | ✅ | `HsDashboardService.php:76-92` |
| `backbone.risk_assessments` | ✅ | `HsDashboardService.php:98-110` |
| `backbone.training` | ✅ | `HsDashboardService.php:116-154` |
| `incident_trends` | ✅ | `HealthSafetyDashboardController.php:97-122` |
| `severity_breakdown` | ✅ | `HealthSafetyDashboardController.php:124-128` |
| `hazard_summary` | ✅ | `HealthSafetyDashboardController.php:130-134` |
| `site_drill_compliance` | ✅ | `HealthSafetyDashboardController.php:136-163` |
| `recent_incidents` | ✅ | `HealthSafetyDashboardController.php:165-169` |
| `recent_hazards` | ✅ | `HealthSafetyDashboardController.php:171-182` |
| `recent_fleet_incidents` | ✅ (bonus) | `HealthSafetyDashboardController.php:195-199` |
| `kpis` (12 scalar KPIs) | ✅ | `HealthSafetyDashboardController.php:81-95` (note `staff_compliance_pct` hardcoded `0` `:94`) |

## Write endpoints already wired (for the wizard modals)

All POST/PUT endpoints the 9 redesign wizards need **already exist** in `routes/health-safety.php` and `routes/incidents.php`:
- Report incident / near-miss → `incidents.store` (`routes/incidents.php:20`).
- Log hazard + risk assessment → no direct `site_hazards` store route found in these two files (hazard CRUD lives elsewhere — flag for the FE wiring step); risk-assessment data model `HsRiskAssessment` present.
- First-aid → `health-safety.first-aid.store` (`routes/health-safety.php:59`).
- Restraint event → `health-safety.restraints.events.store` (`:72`).
- Emergency drill → `health-safety.drills.store` (`:187`) + participants/findings.
- Return-to-work / injury → `health-safety.injuries.store` (`:215`) + rtw-plans/capacity-assessments.
- Hazardous substance + SDS → `health-safety.substances.store` (`:154`) + `sds.store` (`:162`).
- Lone-worker check-in → `health-safety.lone-workers.sessions.check-in` (`:246`).
- Worker participation / committee → `health-safety.worker-participation.*` (`:108-141`).

(All gated on `permission:hazards.view|hazards.manage|hazards.create` / `incidents.create` — see `reference_deploy_seeders` note: new perms must be seeded on the server.)
