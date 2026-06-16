# Health & Safety Analytics — Backend Audit

> **REGION SCOPE — NEW ZEALAND ONLY.** Frameworks: WorkSafe NZ, Health and Safety at Work Act 2015 (HSWA), WorkSafe **notifiable events** (HSWA s.56), Health and Safety at Work (Hazardous Substances) Regulations 2017, Ngā Paerewa Health and Disability Services Standard (NZS 8134:2021), ACC. Frequency metrics are **LTIFR / TRIFR** (per 1,000,000 hours, the NZ/AU convention) — never the US "TRIR". No CQC / RIDDOR / HSE / COSHH / OSHA.

**Date:** 16 June 2026
**Audited for:** the `/health-safety/analytics` rebuild (`HealthSafetyDashboardController@analytics` → `resources/js/pages/health-safety/analytics.tsx`).
**Method:** every column below was read directly from its migration + model file (not inferred). `file:line` references the source of truth.

---

## 0. Executive summary

| Capability the design needs | Verdict | Source |
|---|---|---|
| Incidents by type / severity / status / root cause, over time | ✅ Available | `client_incidents` |
| Hazard burn-down (opened vs closed over time) | ✅ Available | `site_hazards.created_at` / `.closed_at` |
| Injuries by type / body part / lost-time days | ✅ Available | `workplace_injuries` |
| Emergency-drill compliance per site | ✅ Available | `emergency_drills` |
| Corrective-action closure (avg days, % on time) | ✅ Available | `hs_corrective_actions` |
| Worker participation (engagement + consultation) | ✅ Available | `hs_committee_meetings`, `hs_consultations`, `hs_representatives` |
| Training / competency compliance trend | ✅ Available | `staff_training_records` (+ `medication_competency_assessments`) |
| WorkSafe notifiable: notified vs awaiting | ✅ Available | `notifiable_incidents.status` / `.notified_at` |
| **Hours worked** (LTIFR/TRIFR denominator) | ✅ **Available** | `timesheets` (`total_hours` accessor, site-scoped) |
| **LTIFR** (lost-time injury frequency) | ✅ **Computable** | `workplace_injuries.lost_time_days > 0` ÷ timesheet hours |
| **TRIFR** (total recordable frequency) | ⚠️ **Partial** | hours are fine; *recordable classification* must be derived (no clean flag) |
| Per-site incident scoping | ⚠️ Needs join | `client_incidents` has **no `site_id`** — reach site via `client.site_id` |

**Two headline findings:**
1. **The site-scoping bug is real and structural.** `analytics()` line 246 counts *all* incidents for every site row. The fix is not a simple `->where('site_id', …)` because **`client_incidents` has no `site_id` column** — an incident links to a site only transitively through `client.site_id`. The rebuild must `join clients` (or `whereHas('client', …)`) and group by `clients.site_id`.
2. **Hours-worked exists**, so **LTIFR is truthful**. Only the **recordable-injury classification** for TRIFR is soft — handled with a documented heuristic (§9), not fabrication.

---

## 1. ClientIncident — incidents register

- **Model:** `app/Models/ClientIncident.php` · **Table:** `client_incidents`
- **Migrations:** `2026_01_24_000001_create_client_incidents_table.php`, `2026_03_28_000001_enhance_client_incidents_for_nz_compliance.php`, `2026_04_12_170000_add_service_context_and_metadata_to_client_incidents.php`

| Column | Type | Analytics use |
|---|---|---|
| `client_id` | FK→clients, NOT NULL | **only path to a Site** (`client.site_id`) |
| `shift_id` | FK→shifts, nullable | secondary hours/context link |
| `type` | string, indexed | incidents-by-type (`injury, behaviour, medication, safeguarding, near_miss`) |
| `severity` | string default `low` | severity donut (`low, medium, high, critical`) |
| `status` | string default `draft` | `draft, submitted, reviewed, closed` |
| `occurred_at` | timestamp, indexed | the time axis for every incident trend |
| `closed_at` | timestamp nullable | incident closure timing |
| `root_cause_category` | text nullable | **root-cause Pareto** |
| `is_notifiable` | bool default false | notifiable flagging |
| `worksafe_notification_status` | string nullable | WorkSafe status |
| `worksafe_notified_at` | timestamp nullable | notified-vs-awaiting (incident side) |
| `potential_severity` / `potential_consequence` | string/text nullable | near-miss richness |
| `injury_classification` | string nullable | injury sub-classing |
| `medical_treatment_type` | string nullable | `none, first_aid, medical_centre, hospital, ambulance` |

- **`near_miss` is a `type` value, not a flag** (`ClientIncident.php:13`). Near-miss ratio = `count(type='near_miss') / count(type!='near_miss')`.
- **⚠️ No `site_id`.** Confirmed across all 4 migrations. Per-site grouping must join `clients` and group by `clients.site_id` (model itself derives site via `client?->site_id`, `ClientIncident.php:215`).
- **Verdict:** ✅ for type/severity/status/root-cause/time trends. ⚠️ per-site needs the join.

## 2. SiteHazard — hazard register

- **Model:** `app/Models/SiteHazard.php` · **Table:** `site_hazards` · **Migration:** `2026_02_08_000003_create_site_hazards_tables.php` · `SoftDeletes`

| Column | Type | Analytics use |
|---|---|---|
| `site_id` | FK→sites, NOT NULL | native per-site scoping ✅ |
| `risk_rating` | enum `low, medium, high, extreme`, indexed | hazards-by-risk donut |
| `severity` | enum `low, medium, high, critical` | — |
| `status` | enum `open, in_progress, mitigated, closed, reopened` | open-hazard counts |
| `due_date` | date nullable | overdue-action signal |
| `created_at` | timestamp | **burn-down "opened"** |
| `closed_at` | timestamp nullable | **burn-down "closed"** |

- **Burn-down is fully supported:** opened = `created_at`, closed = `closed_at`, running-open = cumulative(opened − closed). Open = `status IN (open, in_progress)`.
- **Verdict:** ✅ Available, natively site-scoped.

## 3. WorkplaceInjury — injuries & ACC

- **Model:** `app/Models/WorkplaceInjury.php` · **Table:** `workplace_injuries` · **Migration:** `2026_03_28_100004_create_return_to_work_tables.php`

| Column | Type | Analytics use |
|---|---|---|
| `user_id` | FK→users, NOT NULL | injured worker |
| `site_id` | FK→sites, nullable | per-site scoping ✅ |
| `related_incident_id` | FK→client_incidents nullable | links injury↔incident |
| `injury_date` | dateTime, NOT NULL | time axis |
| `injury_type` | string | injuries-by-type bars |
| `body_part_affected` | string nullable | injury-by-body-part bars |
| `severity` | string | — |
| `medical_treatment_type` | string nullable | TRIFR recordable heuristic |
| `worksafe_notifiable` | bool default false | TRIFR recordable heuristic |
| `acc_claim_lodged` | bool default false | ACC context |
| `lost_time_days` | integer default 0 | **LTIFR numerator** (`>0` = lost-time) + severity rate |

- `scopeWithLostTime()` = `where('lost_time_days','>',0)` (`WorkplaceInjury.php:108`) — the LTI definition.
- **⚠️ No clean "recordable" classification column** (no `is_recordable` / restricted-work flag). See §9 for the TRIFR heuristic.
- **Verdict:** ✅ LTIFR numerator + injury breakdowns + lost-time days. ⚠️ recordable-set for TRIFR is derived.

## 4. EmergencyDrill — fire & evacuation

- **Model:** `app/Models/EmergencyDrill.php` · **Table:** `emergency_drills` · **Migration:** `2026_03_28_100003_create_emergency_drill_tables.php` · `SoftDeletes`
- Columns: `site_id` (NOT NULL), `drill_type`, `scheduled_at`, `started_at`, `completed_at` (nullable), `status` (`scheduled, completed`), `outcome`, `evacuation_time_seconds`.
- Drill compliance = sites with a `completed_at` within the cadence window ÷ total sites. NZ framing: emergency-drill cadence under Ngā Paerewa / fire-evacuation scheme — **not** an overseas inspection regime.
- **Verdict:** ✅ Available, natively site-scoped.

## 5. Corrective actions — HSWA investigation follow-through

- **Primary model:** `app/Models/HsCorrectiveAction.php` · **Table:** `hs_corrective_actions` · **Migration:** `2026_04_10_180000_create_hs_corrective_actions_table.php` · `SoftDeletes`

| Column | Type | Analytics use |
|---|---|---|
| `hs_event_id` | FK→hs_events, NOT NULL | site reachable via `hs_events` |
| `organization_id` | nullable, indexed | org scoping |
| `status` | string(30) default `open` | `open → in_progress → completed → verified → closed` |
| `priority` | string(20) default `medium` | `low, medium, high, critical` |
| `due_date` | date nullable | **% on time** (`completed_at <= due_date`) |
| `assigned_at` | timestamp nullable | — |
| `completed_at` | timestamp nullable | **avg days to close** (`created_at → completed_at`) |
| `verified_at` / `closed_at` | timestamp nullable | closure lifecycle |
| `created_at` | timestamp | opened |

- `scopeOverdue()` = `due_date < today AND status NOT IN (verified, closed)` (`HsCorrectiveAction.php:145`).
- **⚠️ No `site_id`** — per-site needs `hs_events.site_id` (verify on `hs_events`). Org-wide trend works today.
- Secondary `SiteHazardAction` (`site_hazard_actions`) has `completed_at` but **no `due_date`** → cannot compute on-time%; use `HsCorrectiveAction`.
- **Verdict:** ✅ avg-days + %-on-time org-wide; ⚠️ per-site via `hs_events`.

## 6. Worker participation — HSWA worker-engagement duty

- **Migrations:** `2026_03_28_100001_create_worker_participation_tables.php`, `2026_03_29_000001_enhance_worker_participation.php`

| Model / Table | Key columns | Analytics use |
|---|---|---|
| `HsRepresentative` / `hs_representatives` | `user_id`, `site_id`, `status`, `elected_at`, `term_expires_at`, `training_days_completed` | active-HSR count, HSR training |
| `HsCommittee` / `hs_committees` | `name`, `site_id`, `meeting_frequency`, `status`, `members` (json) | committee roster |
| `HsCommitteeMeeting` / `hs_committee_meetings` | `scheduled_at`, `ended_at`, `status` (`scheduled, in_progress, completed, cancelled`), `attendees` (json), `confirmed_attendees` (json) | **engagement %** = held ÷ scheduled, attendee turnout |
| `HsConsultation` / `hs_consultations` | `consultation_date`, `consultation_type`, `site_id`, `status` (`open, feedback_received, actioned, closed`), `workers_consulted` (json) | **consultation completion %** |

- Engagement trend: bucket `hs_committee_meetings.scheduled_at` by month, `status='completed'` ÷ scheduled.
- Consultation completion: bucket `hs_consultations.consultation_date` by month, `status IN (actioned, closed)` ÷ total. **No `closed_at`** → use `updated_at` as a close-time proxy.
- **Verdict:** ✅ engagement + consultation; ⚠️ precise consultation close-time is a proxy. Site-scopable.

## 7. Training & competency compliance

- **Primary:** `app/Models/StaffTrainingRecord.php` · **Table:** `staff_training_records` · **Migration:** `2026_01_28_000003_create_staff_vetting_training_tables.php`
  - `status` enum `not_started, in_progress, completed, passed, failed, expired, exempted`; `completed_at`, `completion_date`, `expires_at`, `enrolled_at`, `assessment_passed`.
  - `scopeCompleted()` = `status IN (completed, passed)` (`StaffTrainingRecord.php:112`).
  - Denominator from `TrainingCourse` (`training_courses`): `mandatory_for_all` (bool), `mandatory_for_roles` (json), `validity_period_months`.
- **Secondary:** `MedicationCompetencyAssessment` (`medication_competency_assessments`) — `status='passed'`, `assessment_date`, `expiry_date` → meds-competency currency trend.
- `HsTrainingRequirement` (`hs_training_requirements`) defines *which* training is mandatory (denominator), not completions.
- **⚠️ No `site_id`** on training records — scope per site via the user's site assignment if needed.
- **Verdict:** ✅ training-compliance % trend (status + `completed_at`/`completion_date` + `expires_at`).

## 8. WorkSafe notifiable events — HSWA s.56

- **Model:** `app/Domain/Governance/Models/NotifiableIncident.php` · **Table:** `notifiable_incidents` · **Migrations:** `2026_02_11_000001_enhance_governance_module.php`, `2026_03_28_200002_enhance_notifiable_incidents.php`

| Column | Type | Analytics use |
|---|---|---|
| `incident_type` | string (`death, serious_harm, serious_injury, health_safety, privacy_breach`) | event class |
| `notification_authority` | string (`worksafe, health_nz, privacy_commissioner, charities_services`) | **filter `=worksafe`** for the WorkSafe series |
| `related_incident_id` | FK→client_incidents nullable | links to incident (only site path, 2-hop) |
| `status` | string default `pending` (`pending, notified, acknowledged, closed`) | **awaiting** (`pending`) vs **notified** (rest) |
| `occurred_at` | timestamp, NOT NULL | time axis |
| `notified_at` | timestamp nullable | notified timing; null = awaiting |
| `notification_deadline` | dateTime nullable | **overdue-awaiting** flag |
| `closed_at` | timestamp nullable | closure |

- Notified-vs-awaiting series: group `occurred_at` by month; awaiting = `status='pending'` (or `notified_at IS NULL`), notified = the rest. Restrict to `notification_authority='worksafe'` for the WorkSafe-specific chart; the hero badge "WorkSafe notifiable · {n awaiting}" = count of `worksafe` + `pending`.
- **⚠️ No `site_id`** — per-site only via the optional 2-hop `related_incident_id → client_incidents → client.site_id`. Org-wide is solid.
- **Verdict:** ✅ notified-vs-awaiting over time; ⚠️ per-site weak.

## 9. Hours worked — the LTIFR/TRIFR denominator ✅

- **Primary source — `Timesheet`** · `app/Models/Timesheet.php` · **Table:** `timesheets` · base migration `2026_01_09_100010_create_timesheets_table.php`
  - `starts_at` (NOT NULL), `ends_at` (NOT NULL), `break_minutes` (default 0), `work_date` (indexed), `status` (e.g. `approved`), `site_id`, `shift_site_id`.
  - **`getTotalHoursAttribute()` = `(starts_at→ends_at diffInMinutes − break_minutes) / 60`** (`Timesheet.php:401`). So `SUM(total_hours)` over approved timesheets = real hours worked, **bucketable by `work_date` (month) and `site_id`**.
- **Secondary / cross-check — `Shift`** (`shifts`): rostered `starts_at`/`ends_at` + `actual_starts_at`/`actual_ends_at` + `expected_break_minutes` + `site_id`.
- **Tertiary — `HrAttendanceSession`** (clock-in/out, shift-linked).

### Decision — how this audit computes the frequency rates
- **Hours worked = Σ `timesheets.total_hours`** where `status` is an approved/confirmed state, per month and per site. (Fallback to rostered `shifts` hours only if no approved timesheets exist in the window — flagged in the payload as `hours_source: 'rostered_fallback'`.)
- **LTIFR** = `lost-time injuries × 1,000,000 / hours_worked`, where lost-time = `workplace_injuries.lost_time_days > 0`. **Truthful** — both terms are real.
- **TRIFR** = `recordable injuries × 1,000,000 / hours_worked`. Since there is **no clean recordable flag**, "recordable" is defined here (and surfaced in a tooltip on the page) as:
  > `lost_time_days > 0` **OR** `worksafe_notifiable = true` **OR** `medical_treatment_type IN (medical_centre, hospital, ambulance)` — i.e. lost-time + medical-treatment-beyond-first-aid + notifiable. First-aid-only injuries are excluded.
  This is a **documented derivation from real columns**, not a fabricated number. If `hours_worked = 0` for a period, both rates return `null` and the UI shows "needs hours data" for that bucket rather than dividing by zero.
- **Honesty rule:** every LTIFR/TRIFR value in the payload carries the `hours_source` it was computed from, so the page can footnote it.

---

## 10. Data-integrity bugs found (must fix in the rebuild)

1. **🐞 `site_comparison.total_incidents` not site-scoped** — `HealthSafetyDashboardController@analytics:246`:
   ```php
   $totalIncidents = ClientIncident::whereBetween('occurred_at', [$from, $to])->count(); // ← every site shows the org-wide total
   ```
   **Fix:** scope through the client relationship (no `site_id` on the table):
   ```php
   $totalIncidents = ClientIncident::whereBetween('occurred_at', [$from, $to])
       ->whereHas('client', fn ($q) => $q->where('site_id', $site->id))
       ->count();
   ```
   The most efficient form is one grouped query: `ClientIncident::join('clients', …)->whereBetween(…)->groupBy('clients.site_id')->selectRaw('clients.site_id, COUNT(*)')` then map onto the sites list.

2. **🐞 `hazard_data` filtered by `created_at` but labelled a distribution** — fine for "hazards opened in window", but the hero "open hazards" badge must use `status IN (open,in_progress)` regardless of open date. Keep the two distinct.

3. **⚠️ `compliance_score` is an ad-hoc heuristic** (`100 − incidents×5 − hazards×10 − drill penalty`). Acceptable as a relative league score, but it must be **described against NZ obligations** (drill cadence, Ngā Paerewa), not an overseas inspection score. Document the formula in the UI tooltip.

4. **⚠️ No `whereNull('deleted_at')` guard needed** for `SiteHazard`/`EmergencyDrill`/`HsCorrectiveAction` — they use `SoftDeletes`, so Eloquent excludes trashed automatically; raw `join`/`DB::table` queries must add `whereNull('deleted_at')` manually.

---

## 11. Non-NZ regulatory scan

- ✅ **No CQC / RIDDOR / HSE / COSHH / OSHA / TRIR** anywhere in `analytics.tsx`, the controller, the H&S models, or the H&S services (grepped). The codebase is already NZ-clean.
- **Guard:** the rebuild uses **LTIFR / TRIFR**, **WorkSafe notifiable events**, **Ngā Paerewa NZS 8134:2021**, **Hazardous Substances Regulations 2017**, **ACC** only. The `compliance_score` / `drill_status` copy is framed against NZ obligations.

---

## 12. Proposed `analytics()` payload (typed)

```
filters:        { from, to, site_id, lens }            // lens ∈ governance|manager|frontline
sites:          [{ id, name }]                          // for the EntityFilter
active_site:    { id, name } | null
site_brand_colour: string | null
period_summary: { incidents, near_misses, worksafe_awaiting, open_hazards, actions_on_time_pct, drills_complete, drills_total }
hero_stats:     { ltifr{value,delta,dir,hours_source}, trifr{…}, near_miss_ratio{…}, compliance_pct{…} }
scorecard:      { leading:[{key,label,value,delta,dir}], lagging:[{…}] }
trends:         [{ month, ltifr, trifr, near_miss_ratio, incidents, hazards_opened, hazards_closed,
                   hazards_open, ca_avg_days, ca_pct_on_time, compliance_pct,
                   worker_engagement, worker_consultation, worksafe_notified, worksafe_awaiting }]
incident_data:  [{ type, count }]
severity_data:  [{ severity, count }]
root_cause_data:[{ cause, count, pct, cumulative_pct }]   // ordered desc → Pareto
injury_data:    { by_type:[{type,count}], by_body_part:[{body_part,count}] }
hazard_data:    [{ risk_rating, count }]
site_comparison:[{ id, name, total_incidents, open_hazards, lost_time_days, ltifr, trifr,
                   compliance_score, drill_status }]
worksafe_notifiable: { notified, awaiting }               // current totals for the hero badge
hours_meta:     { source, total_hours }                   // honesty footnote
```

All series are **monthly** over `[from, to]`, **site-scoped** when `site_id` is set, and **role-weighted** (the `lens` reorders/relabels emphasis; the data set is identical, the scorecard ordering and the role note differ). No invented schema — every field maps to a column verified above.
