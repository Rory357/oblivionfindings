# PPE & Equipment — Cross-Module Integration Audit

**Scope:** Confirm the handoff claim ("We couldn't find PPE surfaced anywhere outside the register page") and identify where PPE *should* integrate. Source of truth = `/health-safety/ppe` (`PpeController`, four models: `PpeType`, `PpeInventory`, `PpeAllocation`, `PpeInspection`).

**Verdict on the claim: CONFIRMED.** PPE is a fully self-contained island. Outside the register page + its routes/models, there is **zero** PPE code anywhere in the app — no HR/worker view, no site-profile tab, no dashboard tile, no analytics series, no calendar obligation, no notification, no observer, no incident/shift/client linkage. This is a large, high-value integration gap, not a maintenance one.

---

## Part A — Exhaustive "where does PPE appear today" search (the CONFIRM)

Searched the whole tree with ripgrep for: `PpeType|PpeInventory|PpeAllocation|PpeInspection`, `Ppe[A-Z]`, `\bppe\b`, `\bPPE\b`, `ppe_inventory_id|ppe_allocation_id`. Findings:

| # | Where PPE actually appears | File:line | Nature | Real integration? |
|---|---|---|---|---|
| A1 | Register page (the module itself) | `resources/js/pages/health-safety/ppe/index.tsx` | The page being redesigned | n/a (target) |
| A2 | Controller | `app/Http/Controllers/HealthSafety/PpeController.php` | index + 6 write endpoints | n/a (target) |
| A3 | Routes | `routes/health-safety.php:310-` (`prefix('ppe')`, gated `hazards.manage`) | route group | n/a (target) |
| A4 | Models | `app/Models/Ppe{Type,Inventory,Allocation,Inspection}.php` | Eloquent models | n/a (target) |
| A5 | Migration | `database/migrations/2026_03_28_200005_create_ppe_tables.php` | schema (note: `acknowledged`/`acknowledged_at` columns already exist) | n/a (target) |
| A6 | Sidebar nav link | `resources/js/components/app-sidebar.tsx` | nav entry to the register | n/a (nav) |
| A7 | Docs only | `docs/health-and-safety-implementation-plan.md`, `docs/health-and-safety-system-audit.md` | planning text | no |

### False positives explicitly ruled out (these are NOT PPE module integrations)
- `app/Support/SiteRecommendedHazards.php:50` — a recommended hazard *named* "PPE availability". Text only.
- `app/Support/SiteRecommendedDocuments.php:46` — a recommended document *named* "PPE register". Text only.
- `app/Support/SiteRecommendedChecklists.php:43` — a recommended checklist *named* "PPE register" (weekly stock check, tag `['ppe']`). Text only.
- `database/seeders/*` (`HealthSafetyDemoSeeder`, `FleetManagementSeeder`, etc.) — all `\bppe\b` hits are substrings of `strtoupper`, `md5`, "slipped", "tripped". Noise.
- `ppe_inventory_id` / `ppe_allocation_id` matches resolve only to the PPE tables/models themselves — **no other model carries a PPE foreign key**.

**Conclusion:** A1–A6 are all the register, its plumbing, and one nav link. A7 is docs. Everything else is passive text. The handoff is correct: **nothing consumes PPE data outside the register.** Note A1's three "recommended … PPE" text items prove the *product intent* that a site is expected to keep PPE stock/issue records — yet no surface reflects it. That is the opportunity.

---

## Part B — Where PPE SHOULD integrate (the opportunity map)

Every recommendation reuses existing infra (named below) — no new primitives. Priorities: **P0** = smallest high-value wins to ship with the redesign; **P1** = strong follow-ups; **P2** = nice-to-have / defer.

| # | Touchpoint | File(s) to touch | Recommendation | Reuse / pattern | Priority |
|---|---|---|---|---|---|
| **B1** | **H&S dashboard — unified `expiring[]` worklist** | `app/Services/HealthSafety/HsDashboardService.php` `expiringFeed()` (L262-318); consumed at `HealthSafetyDashboardController.php:172` (`worklists.expiring`) | **BUILD (tiny):** add two PPE sources to the existing feed — `PpeInventory` `next_inspection_due` ("PPE inspection") + `expiry_date` ("PPE expiry"), `register_url => '/health-safety/ppe'`. The feed already renders type/label/due/days_until/site rows; PPE just becomes more rows. **Highest value-per-line integration in the audit.** | Mirror the RiskAssessment/SDS/Drill loops already in `expiringFeed()` + `expiringItem()` helper | **P0** |
| **B2** | **H&S dashboard — "Needs attention" KPI tile + compliance badge** | `HealthSafetyDashboardController.php` `$kpis` (L96-111) + the dashboard hero `HeroComplianceBadges` | **BUILD (tiny):** add `ppe_inspections_overdue` + `ppe_expiring` (≤60d) + `ppe_unack` counts (`PpeInventory::inspectionDue()`/`condemned()` scopes already exist; allocation `acknowledged=false` count). Surface as a hero compliance chip "RPE/PPE inspections overdue" feeding the same NZ badge row used by hazards/drills. | Same `$kpis` array + `HeroComplianceBadges` the dashboard already binds | **P0** |
| **B3** | **Worker My-Day — "My PPE" acknowledgement card** | `app/Http/Controllers/MyTasksController.php` `__invoke` (renders `my-day`, keyed on `$userId`); card in `resources/js/pages/my-day/` | **BUILD:** per-worker prop `my_ppe = PpeAllocation::where('user_id',$userId)->whereNull('returned_at')` split into (a) **issued items awaiting acknowledgement** (`acknowledged=false`) → in-card "Acknowledge" POST, and (b) **RPE fit-test due/expiring** (respiratory type + fit-test date). Drives PPE acknowledgement from the frontline (today acknowledgement is unsettable anywhere). | **Direct precedent:** the lone-worker `LoneWorkerCheckInCard` + `active_lone_worker_session` prop pattern just shipped to My-Day (see MEMORY `project_lone_workers_redesign`). New endpoint `POST …/allocations/{allocation}/acknowledge` (handoff already specs this; columns exist). | **P0/P1** |
| **B4** | **Site profile — "PPE" tab + badge** | Tab list `resources/js/pages/sites/show.tsx:1121-1180`; summary built in `app/Http/Controllers/SiteController.php:408,700-701` | **BUILD tab + DEEP-LINK body:** add a `{ value:'ppe', label:'PPE', icon:ShieldCheck, badge }` tab mirroring the existing **Inspections** (overdue badge) / **Drills** (status badge) tabs. Lazy-load a `ppeSummary` prop (site stock count, inspections-due, expiring, condemned). Tab body = compact read-only stock/inspection table + "Open PPE register" deep-link (`/health-safety/ppe?site_id={id}`). The register stays the single write surface. | Copy `buildInspectionsSummary($site)` → `buildPpeSummary($site)`; tab badge logic identical to `inspectionsSummary.overdue_schedules` | **P1** |
| **B5** | **Site Calendar — PPE obligation provider** | New `app/Services/Sites/Calendar/Providers/PpeObligationProvider.php`; register in `SiteCalendarAggregator::defaultProviders()` (L57-73) | **BUILD:** a provider iterating `PpeInventory` per site, emitting a `CalendarItem` for each `next_inspection_due` ("PPE inspection due") and `expiry_date` ("PPE expires"), `link => '/health-safety/ppe'`, `dueStatus()` tone. So PPE inspections/expiries appear as calendar obligations without re-entry. | **Near-exact template:** `AssetMaintenanceObligationProvider.php` (iterates a `DATE_FIELDS` map of due-dates per record → one `CalendarItem` each, `dueStatus`, `siteArray`, `link`). PPE has the same shape (2 date fields). | **P1** |
| **B6** | **Scheduled notification — PPE compliance reminder** | New `app/Jobs/PpeComplianceReminderJob.php` + `app/Notifications/PpeComplianceDueNotification.php`; schedule in `routes/console.php` (`->dailyAt(...)` block, alongside `InspectionDueJob` L182 / `HazardOverdueJob` L211) | **BUILD:** daily job → for inspections-due/expiring inventory notify the site/H&S lead; for **unacknowledged allocations** notify the assigned worker (`PpeAllocation.user_id`). Database channel (+ optional mail), `action_url => '/health-safety/ppe'`. | **Two precedents:** `InspectionDueNotification` (database+mail, upcoming/overdue, `action_url` to register) and `Safeguarding\SafeguardingReviewDueNotification` (database-only digest to assignee, deep-link). No PPE notification exists today. | **P1** |
| **B7** | **H&S Analytics — PPE compliance in the board scorecard + a register view** | `app/Services/HealthSafety/HsAnalyticsService.php` `scorecard()` (L701) + `exportRows()` register views (L784) | **BUILD (small):** add a `ppe_compliance_pct` line to the leading-vs-lagging `scorecard` (% of inventory in-date + inspection-current), and a `ppe` view to `exportRows`/drill records so PPE appears in CSV/JSON export + drill modal. | Same scorecard row shape as `training_pct`; same `exportRows` view registry pattern | **P2** |
| **B8** | **Incidents — link a damaged-PPE incident to its PPE item** | `app/Models/ClientIncident.php` / `FleetIncident`; incident detail dialog | **DEFER:** optional `ppe_inventory_id` nullable FK on incidents so a "PPE failure / damaged equipment" incident can reference the item, and the PPE detail-modal History can show "involved in INC-xxxx". Low frequency; needs schema + UI on both sides. | Mirror how Safeguarding added a reverse incident relation (MEMORY `project_safeguarding_redesign` X1) | **P2** |
| **B9** | **Staff profile — "Issued PPE" panel** | `resources/js/pages/staff/show.tsx` (+ `StaffController@show`) | **DEEP-LINK / DEFER:** a manager-facing read-only "Issued PPE" list on the staff profile (their active `PpeAllocation`s) with a deep-link to the register's Allocations tab filtered by worker. B3 (worker self-view) is higher value; this is the manager mirror. | Reuse the staff/show card grid; allocation query identical to B3 | **P2** |

---

## Part C — Smallest high-value bundle to ship WITH the redesign

If only three integrations land alongside the register rebuild, do **B1 + B2 + B3** (all P0):

1. **B1** — PPE rows in the dashboard `expiring[]` worklist. ~15 lines in `HsDashboardService::expiringFeed()`. Makes overdue PPE visible to the whole H&S command-centre for free.
2. **B2** — PPE counts in dashboard `$kpis` + one `HeroComplianceBadges` chip. ~10 lines; scopes already exist (`PpeInventory::inspectionDue()`, `::condemned()`).
3. **B3** — Worker My-Day "My PPE" acknowledgement card. This is the only place acknowledgement can be *driven* from the frontline; the `acknowledged` columns already exist (just unwired), and the lone-worker My-Day card is a 1:1 precedent.

B4 (site-profile tab) and B5 (calendar provider) are the next tier (P1) — both are near-mechanical copies of existing patterns (`buildInspectionsSummary`, `AssetMaintenanceObligationProvider`).

---

## Part D — Existing infra to REUSE (don't build new)

- **Dashboard expiring feed:** `HsDashboardService::expiringFeed()` / `expiringItem()` — already unions RiskAssessment + SDS + Drill due-dates. **The** hook for B1.
- **Dashboard KPIs + NZ badges:** `HealthSafetyDashboardController` `$kpis` array + `HeroComplianceBadges` (hs-hero-kit). Hook for B2.
- **Calendar obligation provider contract:** `app/Services/Sites/Calendar/Contracts/CalendarObligationProvider.php` + base `Providers/ObligationProvider.php` (`inRange`, `dueStatus`, `isoDate`, `siteArray`). Closest sibling: `AssetMaintenanceObligationProvider`. Registry: `SiteCalendarAggregator::defaultProviders()`. Hook for B5.
- **Notifications:** `InspectionDueNotification` (database+mail, register deep-link) and `Safeguarding\SafeguardingReviewDueNotification` (database digest to assignee). Scheduler: `routes/console.php` `->dailyAt()` block (jobs `InspectionDueJob` L182, `HazardOverdueJob` L211). Hook for B6.
- **Site-profile summary builder:** `SiteController::buildInspectionsSummary()` (L408) + the `inspectionsSummary`/`drillsSummary` props + tab-badge convention in `sites/show.tsx`. Hook for B4.
- **My-Day worker card:** `MyTasksController@__invoke` per-`$userId` stream + the lone-worker check-in card precedent. Hook for B3.
- **PPE model scopes already present:** `PpeInventory::available()/inspectionDue()/condemned()`, `isExpired()/isInspectionDue()`; `PpeAllocation::active()/returned()`. Most B-items are read-only queries over these.
- **No observer exists** (`app/Observers/Ppe*` absent) — if a future requirement needs PPE→ControlRoom/HsEvent signalling (e.g. a condemned-respirator alert), follow the Fleet/Safeguarding observer pattern; out of scope for this redesign.

---

## Part E — Risks / notes

- **`hazards.manage` gate:** all PPE write paths are gated on `hazards.manage`. Any cross-module **write** affordance (B3 acknowledge, B4 deep-link actions) must respect it; **read** surfaces (B1/B2 dashboard, B5 calendar, B7 analytics) should follow the host surface's own gate, not re-require `hazards.manage`, or register-only roles lose visibility. (MEMORY notes `support_worker` has *zero* `hazards.*` perms — so B3's worker card must authorise on `allocation.user_id === auth id`, NOT on `hazards.manage`, exactly like the lone-worker check-in fix.)
- **Site scoping:** `PpeInventory` has a real `site_id` FK (unlike `billing_entries`), so per-site dashboard/calendar/site-profile scoping is a clean `where('site_id', …)` — no snapshot-name join needed.
- **Acknowledgement is currently inert:** `allocate()` never sets `acknowledged`; no endpoint sets it. B3 + B6 are what make the existing columns meaningful. The redesign handoff already specs the `acknowledge` endpoint — B3/B6 consume it.
- **Don't double-count:** if B1 (expiring worklist) and B5 (calendar) and B6 (notification) all ship, ensure each reads the same `PpeInventory::inspectionDue()`/expiry definition so dashboard, calendar and the email agree (the H&S modules have a history of mismatched compliance numbers — see MEMORY drill/LTIFR consolidation notes).
