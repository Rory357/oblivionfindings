# Health & Safety Redesign — Workstream Plan: Backend data spine (WS1)

> Plan for WS1 per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §3/§5. NZ frameworks only.
> Source-of-truth for every value: `BACKEND_AUDIT.md` (file:line). Execute in the sub-step order in §9.

## 0. Identity
- **Workstream:** Backend data spine — KPI calc service (G1) + notifiable classifier (G2) + role/period/site params (G3/G4) + expiring feed (G5) + worklist payloads (G6).
- **Route / page:** `/health-safety` → `HealthSafetyDashboardController@index` → `dashboard.tsx`.
- **One-line goal:** make `index()` emit every value the command-centre binds to — real KPIs, worklist rows, expiring feed — scoped by `?from/?to/?site/?lens`, with no fake data.

## 1. New/changed backend files
| Concern | File | Change |
|---|---|---|
| G1 KPI calc | `app/Services/HealthSafety/HsKpiService.php` | **NEW** — pure read-only calc service |
| G2 classifier | `app/Services/HealthSafety/NotifiableEventClassifier.php` | **NEW** — HSWA threshold classifier |
| G5 expiring + G6 worklists | `app/Services/HealthSafety/HsDashboardService.php` | **EXTEND** — add `expiringFeed()`, `overdueCorrectiveActions()`, `openInvestigations()`, `notifiableEvents()` row builders |
| G3/G4 wiring | `app/Http/Controllers/HealthSafety/HealthSafetyDashboardController.php` | **EXTEND** `index()` — read `?from/?to/?site/?lens`, thread through, add new payload keys |
| Tests | `tests/Feature/HealthSafety/HsKpiServiceTest.php`, `NotifiableEventClassifierTest.php`, `HsDashboardWorklistTest.php` | **NEW** |

## 2. G1 — `HsKpiService` API (formulas from `HANDOFF.md` KPI defs)
Denominator source (audit): `BillingEntry.hours` (SQL-summable; `site_id` + `service_date`). Defined ONCE via `totalHoursWorked()`.

| Method | Formula | Source |
|---|---|---|
| `totalHoursWorked(?from,?to,?siteId): float` | `BillingEntry::whereBetween(service_date,[from,to])->when(site)->sum('hours')` | `BillingEntry.hours` |
| `ltifr(?from,?to,?siteId): ?float` | `(lostTimeInjuries ÷ hours) × 1_000_000` | LTI = `WorkplaceInjury::withLostTime()` (`lost_time_days > 0`) |
| `trifr(?from,?to,?siteId): ?float` | `(recordableInjuries ÷ hours) × 1_000_000` | recordable rule below |
| `injurySeverityRate(?from,?to,?siteId): ?float` | `(Σ lost_time_days ÷ hours) × 1_000_000` | `WorkplaceInjury.lost_time_days` |
| `daysSinceLostTimeInjury(?siteId): ?int` | `today − max(injury_date WHERE lost_time_days>0)` | re-points the buggy `days_since_notifiable` |
| `incidentsInPeriod(?from,?to,?siteId): int` | `ClientIncident::whereBetween(occurred_at)->where(type != near_miss)` | |
| `nearMissesInPeriod(?from,?to,?siteId): int` | `ClientIncident type=near_miss` count | |
| `nearMissToIncidentRatio(...): ?float` | `nearMisses ÷ recordableInjuries` (proactive-reporting health) | |
| `actionsClosedOnTimePct(?from,?to): ?float` | `actions completed_at ≤ due_date ÷ actions due in period × 100` | `HsCorrectiveAction.due_date/completed_at` |
| `trainingAuditCompliancePct(): ?float` | `compliant staff-req pairs ÷ total × 100` (NOT IN expired/not_started) | `HrStaffComplianceStatus` via `HsTrainingRequirement` |
| `openHazards(?siteId): int` | `SiteHazard whereIn(status,[open,in_progress])` | |
| `monthlyFrequencyRates(int $months=12, ?siteId): array` | per-month **trailing-12-mo rolling** `{month, ltifr, trifr}` for the trend lines | precompute monthly buckets, roll in PHP |
| `leadingLagging(?from,?to,?siteId): array` | bundles lagging {incidents, ltifr, trifr, days_lti_free} + leading {near_miss_ratio, actions_on_time_pct, training_pct, open_hazards} | |

**Recordable-injury rule (NZ/AU TRIFR):** `lost_time_days > 0` **OR** `medical_treatment_type IN ('medical_centre','hospital','ambulance')` **OR** `worksafe_notifiable = true`. Excludes `none`/`first_aid`/null (first-aid-only = not recordable). Enum confirmed: severity∈{minor,moderate,serious,critical}; medical_treatment_type∈{none,first_aid,medical_centre,hospital,ambulance}.

**Null semantics:** rate methods return `null` when `hours = 0` (no fake division); controller renders "—". Default period for rates = trailing 12 months (standard annualised frequency-rate window) unless `from/to` given.

**Limitation (flag):** no "audit" model exists — `trainingAuditCompliancePct` computes **training** compliance only; surfaced as the single design figure. Logged in §10.

## 3. G2 — `NotifiableEventClassifier`
- `classify(harm, severity): ['notifiable'=>bool, 'category'=>?string, 'reason'=>string]`.
- Prototype rule: notifiable when `harm ∈ {hospitalisation, death} OR severity === Critical`.
- Map to the 3 HSWA categories: `death` → notifiable death; `hospitalisation`/serious illness → notifiable injury/illness; else (critical severity w/o the above) → notifiable incident.
- Schema already present on `NotifiableIncident` (status/notified_at/notification_reference/site_preserved/notification_deadline/SoftDeletes=≥5yr) — **no migration**. Classifier feeds the incident wizard (WS6) + the `awaiting` badge/worklist.
- "Awaiting" count = `NotifiableIncident` where `status='pending'`.

## 4. G3/G4 — controller params
- `index(Request)` reads: `from`/`to` (date range; default 30d snapshot preserved for back-comat → actually default trailing 30d for counts, 12mo for rates), `site` (int site id, null=all), `lens` (governance|manager|frontline, default manager).
- Thread `siteId` into every scoped query; `analytics()` already models `?from/?to` parsing (reuse that pattern). Add `?site` everywhere; **fix the `site_comparison.total_incidents` scoping bug** while here (currently unscoped).
- `lens` (G3): controller returns a `lens` string + a `lens_emphasis` ordering hint; the heavy re-weighting is client-side in WS3, but governance/frontline also **scope** which worklists load (e.g. frontline → hazards + lone-worker first). Keep server contribution minimal but real (emphasis array + lens echoed).

## 5. G5/G6 — `HsDashboardService` row builders (data ready per audit)
| Method | Rows from | Each row carries |
|---|---|---|
| `overdueCorrectiveActions(?siteId, limit)` | `HsCorrectiveAction::overdue()` + join `HsEvent` | id, reference_number, title, priority, status, due_date, owner (assigned user name/id), `client_id`/`staff_id` (via event), days_overdue |
| `openInvestigations(?siteId, limit)` | `HsInvestigation::active()` + event | id, title, status, lead_investigator, target_completion_date, overdue bool, client/staff ids |
| `notifiableEvents(?siteId, limit)` | `NotifiableIncident` (status/occurred_at/notified_at/site_preserved) | id, title, status (awaiting/notified), occurred_at, notification_deadline, worksafe_ref, related_incident_id |
| `expiringFeed(?siteId, limit)` | unify risk `HsRiskAssessment.review_due_at` + SDS `SafetyDataSheet.review_date` + drill `EmergencyDrill.scheduled_at` + training | type, label, due_date, days_until, register_url, site |

## 6. Cross-module touchpoints
- Worklist `client_id`/`staff_id` → `/clients/{id}` & `/staff/{id}` (context-menu jumps, WS4). Verify ids serialize.
- `expiring[].register_url` → real register pages (risk-assessments / substances / drills / competency).
- Notifiable events → investigation linkage (`related_incident_id`).

## 7. Retire → redirect
- None in WS1 (backend only). Route retirements happen in WS6–8 when wizards replace navigate-away pages.

## 8. Verification (WS1 gate)
- `vendor/bin/pint` touched PHP; feature tests: `HsKpiServiceTest` (LTIFR/TRIFR math incl. recordable rule + null-on-zero-hours; days-since-LTI; actions-on-time; ratio), `NotifiableEventClassifierTest` (each HSWA threshold + non-notifiable), `HsDashboardWorklistTest` (row arrays carry client/staff ids; site scoping; expiring unifies 4 sources).
- ⚠️ **Worktree caveat** (memory `reference_worktree_junction_tests_load_parent_app`): feature tests may autoload the parent `app/` — true backend verification is **post-merge in the parent repo**. Run what we can here; note in PROGRESS.
- NZ grep of touched files → zero CQC/RIDDOR/HSE/COSHH/OSHA/TRIR.

## 9. Execution order (sub-steps, commit each)
1. `HsKpiService` + `HsKpiServiceTest`. ← **this commit**
2. `NotifiableEventClassifier` + test.
3. `HsDashboardService` row builders (G5/G6) + `HsDashboardWorklistTest`.
4. Controller `index()` rewire (G3/G4 params + new payload keys + site_comparison fix).
5. WS1 gate + tick PROGRESS.

## 10. Notes / decisions / deferrals
- **Hours denominator** = `BillingEntry.hours` (lags un-billed periods; acceptable for board frequency rates). PHP `Timesheet::total_hours` fallback deferred unless tests show billing gaps.
- **"Audit" %** has no model → training compliance stands in (flagged).
- **Ngā Paerewa certification** + **first-aid cover** badges still have no backing data (audit) — WS2 will render them static/config-driven with a TODO, not fake counts.
