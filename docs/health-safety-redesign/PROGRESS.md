# Health & Safety Dashboard Redesign — PROGRESS (loop control)

> **On (re)start, read this file first** and resume at the first `todo`/`in-progress` workstream.
> Process ONE workstream at a time: plan → build → verify → commit → tick. Don't start the next mid-way.
> Branch: `claude/nifty-kowalevski-4d5c73` (isolated worktree off main). Bundle in `.design-drops/` (gitignored).

## Status legend
`todo` · `in-progress` · `done` (with commit hash) · `deferred` (+reason)

## Phase 0 — Audits (gate before building)
| Item | Status | Output |
|---|---|---|
| Backend audit | **done** | `docs/health-safety-redesign/BACKEND_AUDIT.md` |
| Prototype digest | **done** | `docs/health-safety-redesign/PROTOTYPE_DIGEST.md` |
| Current-state + NZ-content audit | **done** | `docs/health-safety-redesign/CURRENT_STATE_AUDIT.md` |
| Gap analysis refresh | **done** | `docs/HEALTH_SAFETY_GAP_ANALYSIS.md` |

## Workstreams (ordered — data spine first so UI binds to real props)
| # | Workstream | Status | Commit | Plan |
|---|---|---|---|---|
| 1 | **Backend data spine** — `HsKpiService` (G1) + notifiable classifier (G2) + role/period/site params (G3/G4) + expiring feed (G5) + worklist payloads (G6) | **in-progress** | — | `backend-spine-plan.md` |

### WS1 sub-steps
| Step | Status | Commit |
|---|---|---|
| 1. `HsKpiService` (G1) + `HsKpiServiceTest` | **done (php -l clean; tests run post-merge)** | _this commit_ |
| 2. `NotifiableEventClassifier` (G2) + test | **done (php -l clean)** | _this commit_ |
| 3. `HsDashboardService` row builders G5/G6 + test | todo | — |
| 4. Controller `index()` rewire (G3/G4 params + payload + site_comparison fix) | todo | — |
| 2 | **Hero command centre + footer band** | todo | — | `hero-plan.md` |
| 3 | **TabStrip + role lens** (Overview/Leading/Lagging/Compliance) | todo | — | `tabs-plan.md` |
| 4 | **Worklists + detail modal + context menu** | todo | — | `worklists-plan.md` |
| 5 | **Charts — pixel-faithful** (8 charts) | todo | — | `charts-plan.md` |
| 6 | **Report launcher + Report-incident wizard** (reference flow) | todo | — | `incident-wizard-plan.md` |
| 7 | **The other 8 wizards** | todo | — | `wizards-plan.md` |
| 8 | **Governance exports + site league** | todo | — | `governance-plan.md` |

## Key decisions / facts (from the audits)
- **NZ-content is already clean** — zero CQC/RIDDOR/HSE/COSHH/OSHA/TRIR hits. Keep it that way; re-grep touched files each WS.
- **Hours-worked denominator (LTIFR/TRIFR):** `App\Models\BillingEntry.hours` (SQL-summable, `service_date`), summed via `SUM(hours)` in `ReportingService`. Exposed behind `HsKpiService::totalHoursWorked(?from,?to,?siteId)`. ⚠️ **Audit correction:** `billing_entries` has **NO `site_id` column** (the model's `site_id` fillable + `site()` relation are dead — column never migrated). Per-site hours key on **`site_name_snapshot`** (the `ReportingService` pattern), resolved from the site's current name. `WorkplaceInjury`/`SiteHazard`/`HsEvent` DO have real `site_id`; `ClientIncident` has none → site-scoped via `shift.site_id`.
- **Rate window policy:** LTIFR/TRIFR/severity-rate annualise over a **trailing 12 months** (stable denominator) regardless of the picked period; period-bound counts (incidents/near-misses/actions-on-time) follow `?from/?to` (default 30d). Recordable-injury rule: `lost_time_days>0` OR `medical_treatment_type ∈ {medical_centre,hospital,ambulance}` OR `worksafe_notifiable`.
- **G2 notifiable schema already exists** — `NotifiableIncident` (status/notified_at/notification_reference/site_preserved/notification_deadline/SoftDeletes=≥5yr) + `HsEvent.worksafe_notifiable/worksafe_status/worksafe_reference`. Only the **classifier + surfacing** are missing. WorkSafe rule (prototype): notifiable when `harm ∈ {hospitalisation, death} OR severity === Critical`; production maps to the three HSWA categories.
- **All 9 wizard write endpoints already exist** (incidents.store, sites.hazards.store, first-aid, restraints.events, drills, injuries, substances+sds, lone-workers check-in, worker-participation). Hazard store is `POST /hazards` in `routes/sites.php` (`SiteHazardController@store`).
- **Current `dashboard.tsx`**: `PageHero` (simple KPI header), 13-card KPI grid, recharts charts, backbone cards, drill table, recent-activity lists, 7 navigate-away quick actions. No TabStrip/EntityFilter/WizardShell/ShiftContextMenu. Props read: `kpis`, `incident_trends`, `severity_breakdown`, `hazard_summary`, `site_drill_compliance`, `recent_incidents`, `recent_hazards`, `recent_fleet_incidents?`, `backbone?`.
- **Prototype nuances** (don't over-build): NO quickActions strip; role-lens is cosmetic in the prototype (we add real server scoping in G3); incident completeness meter uses 8 checks for 6 steps (~88% before WorkSafe gate); preserve macrons (Kōwhai) + NZ phrasing.

## Existing assets to reuse (don't rebuild)
- Hero: `components/page` → `PageHero`. Tabs: `components/rostering/tab-strip.tsx` → `TabStrip`. Filter: `components/rostering/entity-filter.tsx`. Wizard chrome: `components/wizard/shell.tsx` + `components/wizard/primitives.tsx`. Right-click: `components/rostering/shift-context-menu.tsx`. Stat tiles/charts: `components/ops-stat-card` + recharts. Display widgets already present: `components/health-safety/event-timeline.tsx`, `risk-matrix.tsx`.

## Deferrals / open questions
- _(none yet)_

## Verification notes (worktree gotcha)
- This is a junctioned/worktree workbench. PHP **feature tests may autoload the parent app/** — backend changes are truly exercised only after merge in the parent repo (see memory `reference_worktree_junction_tests_load_parent_app`). Migrations + frontend DO use the worktree. Plan backend test verification accordingly.
