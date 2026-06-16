# Health & Safety Dashboard — Gap Analysis (refreshed against the code)

> **REGION SCOPE — NEW ZEALAND ONLY.** WorkSafe NZ · Health and Safety at Work Act 2015 (HSWA) + regs · WorkSafe **notifiable events** · Health and Safety at Work (Hazardous Substances) Regulations 2017 · Ngā Paerewa Health and Disability Services Standard **NZS 8134:2021** · ACC. NZ frequency metrics are **LTIFR / TRIFR** — never the US "TRIR". **No UK / overseas frameworks** (no CQC, RIDDOR, HSE, COSHH, OSHA).

**Page:** `/health-safety` → `HealthSafetyDashboardController@index` → `resources/js/pages/health-safety/dashboard.tsx`
**Companion audits (this redesign):** `docs/health-safety-redesign/BACKEND_AUDIT.md`, `docs/health-safety-redesign/CURRENT_STATE_AUDIT.md`, `docs/health-safety-redesign/PROTOTYPE_DIGEST.md`
**Design drop:** `.design-drops/health-safety-redesign/` (gitignored)
**Refreshed:** 16 June 2026

This is the **A–F brief checklist re-audited against the repository** (the original seed was written from the design, not the code). `[x]` = present/satisfied **in code today**; `[~]` = partially present; `[ ]` = design-complete but **not yet built** (tracked per-workstream in `docs/health-safety-redesign/PROGRESS.md`).

---

## A. Hero banner — make it the command centre
Current `dashboard.tsx` uses `PageHero`, but as a **simple KPI header** (4 stats + 2 links) — not a command centre (`CURRENT_STATE_AUDIT.md`).

- [~] **A1.** Hero is `PageHero` already, but under-used → rebuild as the command centre. *(WS2)*
- [ ] **A2.** Live status eyebrow ("Safety system · synced") — not present. *(WS2)*
- [ ] **A3.** Title "Health & Safety command centre" + underlined active **site** name + org. *(WS2)*
- [ ] **A4.** Split stat tiles into **LEADING vs LAGGING** clusters, each toned + jump-to-register. *(WS2; data WS1)*
- [ ] **A5.** Compliance badge row (WorkSafe notifiable / Ngā Paerewa NZS 8134:2021 / Hazardous Substances / Fire / First-aid), tone+icon+label. *(WS2; two badges need data — see G-section)*
- [ ] **A6.** Primary **"＋ Report"** launcher (chooser → wizard modals) + Export board summary / WorkSafe register. *(WS6–7)*
- [ ] **A7.** ~~quickActions icon strip~~ — **prototype has NO quickActions strip** (`PROTOTYPE_DIGEST.md`); do not invent one. Item retired.
- [ ] **A8.** **Footer band**: period **range** control (week / 30d / quarter / custom), Site filter, Role toggle, "this week" summary strip. *(WS2; params WS1/G4)*

## B. Every workflow → Add-Client-style wizard modal (in place)
Current dashboard has **zero modals** — every action navigates away (`CURRENT_STATE_AUDIT.md`). **All 9 write endpoints already exist** (see below), so this is wizard-wiring, not new backends.

- [ ] **B1.** Replace navigate-away links with `WizardShell` modals. *(WS6–7)*
- [ ] **B2.** **Report incident / near-miss** — 6-step reference flow incl. WorkSafe notifiable check → `POST /incidents`. *(WS6)*
- [ ] **B3.** **Log hazard + risk assessment** — L×C matrix + hierarchy of control → `POST /hazards` (`sites.hazards.store`). *(WS7)*
- [ ] **B4.** **Record first-aid** → `POST /health-safety/first-aid`. *(WS7)*
- [ ] **B5.** **Log restraint event** → `POST /health-safety/restraints/events`. *(WS7)*
- [ ] **B6.** **Record emergency drill** → `POST /health-safety/drills`. *(WS7)*
- [ ] **B7.** **Injury → Return-to-work (ACC)** → `POST /health-safety/injuries`. *(WS7)*
- [ ] **B8.** **Add hazardous substance** (Hazardous Substances Regs 2017) → `POST /health-safety/substances`. *(WS7)*
- [ ] **B9.** **Lone-worker check-in / escalation** — single-surface → `POST /health-safety/lone-workers/sessions/{session}/check-in`. *(WS7)*
- [ ] **B10.** **Worker participation / committee** → `POST /health-safety/worker-participation/committees/{committee}/meetings`. *(WS7)*
- [ ] **B11.** Every wizard ends with SuccessPane + Inertia partial reload. *(WS6–7)*

## C. Dashboard body (below the hero)
Current body = KPI grid + recharts charts + backbone cards + a drill table + recent-activity link-lists + 7 quick-action links. No TabStrip, no worklists, no context menu (`CURRENT_STATE_AUDIT.md`).

- [ ] **C1.** Leading-vs-lagging `TabStrip` (Overview / Leading / Lagging / Compliance). *(WS3)*
- [ ] **C2.** Role lens (Governance / Manager / Frontline) re-weights the body. *(WS3; server-scope G3)*
- [~] **C3.** Trends — recharts already present (area trend, severity donut, 3 gauges, hazard bar), but **no LTIFR/TRIFR lines, no near-miss-ratio donut, no hazard burn-down**; charts must be re-cut to match the prototype exactly. *(WS5; data WS1)*
- [ ] **C4.** **Actionable worklists** (overdue CAs, open investigations, WorkSafe-notifiable, expiring) — only counts exist today. *(WS4; payloads G5/G6)*
- [ ] **C5.** Worklist row idiom: status pill + owner + due, click→detail modal, right-click→`ShiftContextMenu`, "View register" link. *(WS4)*
- [~] **C6.** Site safety league — a proper league exists on `/analytics` (`site_comparison`) but **not on index**, and its `total_incidents` is **not site-scoped** (bug, `analytics():246`). Move to index + fix. *(WS5/WS8; G4)*
- [ ] **C7.** Governance export strip (board summary / WorkSafe register / investigation outcomes / CA traceability / risk register). Routes exist (`HsGovernanceReportController`). *(WS8)*

## D. Notifiable events & registers (NZ HSWA)
- [ ] **D1.** Surface WorkSafe notifiable-event status on the dashboard worklist. *(WS4)*
- [~] **D2.** Auto-flag incidents meeting the HSWA threshold. **Schema present** (`NotifiableIncident` + `HsEvent.worksafe_notifiable`), **auto-classifier missing**. *(WS1/G2)*
- [~] **D3.** Capture preserve-site + notify steps. **Fields present** (`NotifiableIncident.site_preserved`, `notified_at`, `notification_reference`); wizard capture missing. *(WS1/G2 + WS6)*
- [ ] **D4. (backend)** Server-side notifiable classifier (death / notifiable injury-or-illness / notifiable incident). *(WS1/G2)*

## E. Standardisation & accessibility
- [~] **E1.** Match app idioms — `PageHero` ✅ used; `TabStrip`/`EntityFilter`/`WizardShell`/`ShiftContextMenu` **not yet** on this page. *(WS2–7)*
- [~] **E2.** Semantic tokens only — current page is largely token-based; verify no raw colours during rebuild (ESLint guardrail). *(all WS)*
- [ ] **E3.** Keyboard-operable rows/menus/modals — added with the new worklists + wizards. *(WS4/6/7)*
- [ ] **E4.** Responsive hero/worklists. *(WS2–4)*

## F. Strip / verify NZ-only regulatory content — ✅ VERIFIED CLEAN IN CODE
A case-insensitive scan of the entire H&S surface (`resources/js/pages/health-safety/**`, `components/health-safety/**`, `app/Http/Controllers/HealthSafety/**`, `app/Models/Hs*`/`*Incident*`/`*Hazard*`, `app/Services/HealthSafety/**`, `routes/health-safety.php`, `routes/incidents.php`, H&S docs) returned **zero hits** for every overseas term (`CURRENT_STATE_AUDIT.md`):

- [x] **F1.** CQC / RIDDOR / HSE / COSHH / OSHA — **0 hits** each.
- [x] **F2.** Frequency metrics are LTIFR / TRIFR — **"TRIR" has 0 hits**; LTIFR/TRIFR are net-new (to be computed in G1), not mislabelled.
- [x] **F3.** Care certification = Ngā Paerewa NZS 8134:2021 (no overseas care regulator in code).
- [x] **F4.** Substances framed under Hazardous Substances Regs 2017 / HSNO/EPA (no COSHH).
- [x] **F5.** Injury management / RTW framed under ACC.

> **NZ-content scan result: nothing to strip.** The surface is already NZ-only. Keep it that way during the rebuild; re-grep touched files at each workstream's verification gate (§10).

---

## Backend gaps (engineering — verified against code; details + file:line in `BACKEND_AUDIT.md`)

- [ ] **G1.** **KPI calc service** — build new `App\Services\HealthSafety\HsKpiService`. None of LTIFR / TRIFR / injury-severity-rate / near-miss ratio / actions-closed-on-time % / days-since-LTI / training-audit % is computed anywhere (`staff_compliance_pct` is hardcoded `0`). **Hours-worked denominator source found:** `BillingEntry.hours` (SQL-summable, `site_id` + period cols) with `Timesheet::total_hours` PHP fallback. *(WS1)*
- [~] **G2.** **Notifiable-event flagging** — **schema already present** on `NotifiableIncident` (`status`, `notified_at`, `notification_reference`, `site_preserved`, `notification_deadline`, `SoftDeletes`=≥5yr) and `HsEvent` (`worksafe_notifiable`/`worksafe_status`/`worksafe_reference`). **Missing:** the auto-classifier against the three HSWA thresholds + surfacing the "awaiting" count/worklist. No invented schema needed. *(WS1)*
- [ ] **G3.** **Role-scoped payload** — `index()` reads no `?lens`; add governance/manager/frontline scoping. *(WS1/WS3)*
- [ ] **G4.** **Period range + site params** — `index()` is a fixed snapshot (no `?from/?to/?site`). The sibling `analytics()` already accepts `?from/?to` and is a template. *(WS1)*
- [ ] **G5.** **Unified expiring feed** — risk (`HsRiskAssessment.review_due_at`), SDS (`SafetyDataSheet.review_date`), drills (`EmergencyDrill.scheduled_at`), training requirements — all exist, none unified into `expiring[]`. *(WS1)*
- [ ] **G6.** **Worklist payloads** — `overdue_corrective_actions[]` / `open_investigations[]` / `notifiable_events[]` as row arrays (currently counts only); join `HsEvent` for `client_id`/`staff_id` context-menu jumps. *(WS1)*

### Known wiring bugs to fix in passing
- Dashboard "Report Hazard" action points at the list page `/compliance/hazards` instead of the create flow — replace with the hazard wizard. *(WS7)*
- `analytics()` `site_comparison.total_incidents` is **not** filtered by `$site->id` (counts all incidents on every row). *(WS5/WS8)*
- `kpis.staff_compliance_pct` hardcoded `0` → compute in G1. *(WS1)*
