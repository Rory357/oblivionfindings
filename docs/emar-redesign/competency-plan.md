# eMAR Redesign — Page Plan: Competency (`/emar/competency`)

## 0. Identity
- **Route:** `GET /emar/competency` → `emar.competency` (`EmarController@competency` :1656).
- **Inertia page:** `resources/js/pages/emar/Competency.tsx` (rewrite).
- **Write endpoints — ALL EXIST:** `emar.competency.store` (:2557), `.update` (:2606), `.destroy` (:2656, hard delete — kept; not a legally-immutable register, AuditableChanges logs it).
- **Model:** `MedicationCompetencyAssessment` — 12 boolean areas, `observed_rounds` array-cast (fillable but **not** validated → un-fillable today), `action_plan` validated, `total_score`/`pass_threshold` computed (=10), scopes active/expired/expiringSoon, `competencyAreas`/`passedCount`/`totalAreas` accessors.
- **Goal:** flat table + inline Dialogs → brand hero + 6-tab `TabStrip` + KPI strip + coverage matrix, all create/edit/view/renew on `MedsWizardDialog` (§3d), surfacing the modelled-but-hidden data.

## Key findings (verify-against-code)
- **`observed_rounds`** is cast+fillable but absent from store/update validation → **never capturable**. Add it (Step 3). No migration.
- **`action_plan`** already validated → just surface (Step 5 + View). `total_score`/`pass_threshold` already computed → surface (score column, outcome panel).
- **Staff aren't site-scoped** (User has no `site_id`; only `sitesAsPrimaryContact`). So **no site filter** — facet by Role + Status + search. Brand colour still resolves from `?site_id` (parity, themeable deep-link).
- **destroyCompetency hard-deletes** — kept (handoff lists Delete as a row action; not an immutable register).

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats In-date%/Expiring/Unassessed/CD-witnesses, badges, footer search + role/status filters) | `PageHero` + `brandColour` | flat payload + KPIs |
| Critical ribbon (expired) | inline | `expired[]` |
| KPI strip (6) | inline cards | KPIs |
| Tabs (all/in-date/expiring/expired/unassessed/coverage) | `TabStrip` | client-side facets |
| Tables + Expiring/Unassessed lists + Coverage matrix | inline | `assessments[]` + `staffWithoutAssessment[]` |
| New / Edit / Renew assessment (5-step) | **BUILD** `AssessmentWizardDialog` | `competency.store` / `.update` |
| View detail (read-only) | **BUILD** `ViewAssessmentDialog` | — |

## 2. Hero spec
Eyebrow live-ping `MEDICATION COMPETENCY OVERSIGHT · live`; title "Team medication competency for {site underlined / your team}"; description derived (in-date/total, %, expiring, unassessed); stats **In date % · Expiring · Unassessed · CD witnesses**; banner for expired; footer = search + Role `EntityFilter` + Status `EntityFilter`. Brand colour from `?site_id`; **Export register** = client-side CSV.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | parity | `competency()`: flat payload (drop pagination) + `?site_id` brand colour + sites/active_site | feature: brand colour + payload |
| observed | un-fillable | store/update: validate `observed_rounds` (array) → Step 3 captures it | feature: store persists observed_rounds |
| tri-state | booleans can't say "not seen" | **migration** `not_seen_areas` (json); store/update validate + persist; coverage matrix reads it | — |
| restricted | standard practice | **migration** `restricted` (bool) + `restriction_notes` (text); store/update; permission chip | feature: restricted persists |
| surface | scores/action_plan/observed never shown | payload exposes all 12 areas + observed_rounds + not_seen_areas + restricted + action_plan + scores + permissions + assessor; KPIs | — |

## 4. Cross-module (§6)
- Competency gates who can administer/witness — surfaced as permission chips. Sidebar "Competency" → `/emar/competency` (unchanged). Staff list shared via `getStaffList()`.

## 5. Retire → fold into modals
- Inline `<Dialog>` new/edit → `MedsWizardDialog` 5-step wizard (New/Edit/Renew) + View modal. No standalone GET create/edit pages exist to remove (already inline). No route changes.

## 6. Execution checklist
- [ ] Backend: migration (restricted/restriction_notes/not_seen_areas); model fillable/casts; `competency()` rebuild (flat + brand + KPIs + full fields); store/update (observed_rounds + not_seen_areas + restricted). Tests.
- [ ] Frontend: `_competency-dialogs.tsx` (AssessmentWizardDialog 5-step + ViewAssessmentDialog); `Competency.tsx` rewrite (hero + 6-tab + KPI strip + tables + coverage matrix + CSV export).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d HARD RULE: MedsWizardDialog (handoff said WizardShell — overridden). Reuse Pages 1–9 patterns + dialog-generalization + brand-colour payload.
- **Score semantics:** keep `total_score` = Yes-count, pass `>= 10` (existing); `not_seen_areas` recorded for honest display + coverage, but applicable-aware thresholding (denominator shrinks when areas not-seen) is a backlog refinement.
- **Deferred:** `triggering_error_id` remedial link (needs medication_errors picker — Page 12), `assessor_signed_at`/`staff_signed_at` persistence (declarations captured as gating checkboxes; assessor recorded via assessor_id + AuditableChanges), right-click context menus (inline actions), `reassessment_due` task scheduling. Reasons: cross-page (errors) / separable audit columns / pattern-consistent. Core = brand 6-tab board + KPI strip + coverage matrix + 5-step wizard (observed rounds + tri-state + restrictions + action plan + outcome gating) + View modal.
