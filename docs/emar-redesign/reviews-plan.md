# eMAR Redesign — Page Plan: Medication Reviews (`/emar/reviews`)

## 0. Identity
- **Route:** `GET /emar/reviews` → `emar.reviews` (`EmarController@reviews` :1682).
- **Inertia page:** `resources/js/pages/emar/Reviews.tsx` (rewrite).
- **Write endpoints — ALL EXIST:** `emar.reviews.store` (:2165, scheduled), `.update` (:2185, reschedule), `.complete` (:2201, clinical_summary + medications_reviewed[] + actions[] + whanau + next date), `.destroy` (:2226, soft → status=cancelled). **NEW:** a deprescribing stage-advance endpoint (mutates `actions[].stage`).
- **Model:** `MedicationReview` — `actions` + `medications_reviewed` already `array`-cast; scopes overdue/upcoming/due; `reviewer()` = reviewer_user_id.
- **Goal:** flat header/table + off-contract dialogs → brand hero + 6-tab `TabStrip` + Overview + list tabs + **Deprescribing Kanban** (new), all workflows on `MedsWizardDialog` (§3d, overrides handoff's "WizardShell").

## Key findings (verify-against-code)
- **storeReview already accepts `reviewer_user_id`** (G3 backend done) + trigger_reason. **completeReview already stores `actions[]`/`medications_reviewed[]`** (G1) — just never surfaced. So the Recommendations step writes structured `actions[]`; Detail + Kanban read them. No migration for the pipeline.
- **Deprescribing action shape (G2):** `{drug, action, rationale, gp_status: pending|accepted|declined, stage: gp|implemented|monitor|done}`. Pipeline = completed reviews' actions where `action !== 'Continue'`. Kanban columns map to `stage`.
- **DBI/falls (G4):** small migration `drug_burden_index` + `falls_last_quarter` on medication_reviews (Findings step captures, Detail shows).
- **destroyReview is a soft cancel** (status=cancelled) — already immutable-friendly.

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats Overdue/Due30/CompletedQ/GP-accept%, badges, footer cycle stepper + search + site/reviewer filters) | `PageHero` + `brandColour` | flat payload + KPIs + colour |
| Tabs (overview/due/scheduled/completed/deprescribing/all) | `TabStrip` | client-side facets |
| Overview (KPIs + due-now + upcoming + recently-completed + pipeline mini) | inline | derived |
| List tabs (table) | inline | `reviews[]` |
| Deprescribing Kanban (Awaiting GP→Implemented→Monitoring→Closed) | inline | `deprescribing[]` + advance |
| Schedule review (4-step) | **BUILD** `ScheduleReviewDialog` | `reviews.store` |
| Conduct & complete (5-step) | **BUILD** `ConductReviewDialog` (replaces Edit+Complete) | `reviews.complete` |
| View detail (read-only) | **BUILD** `ReviewDetailDialog` | — |
| Reschedule (compact) | **BUILD** `RescheduleReviewDialog` | `reviews.update` |

## 2. Hero spec
Eyebrow live-ping `MEDICATION GOVERNANCE · live`; title "Medication reviews for {site underlined}"; description derived (N need attention, M overdue); stats **Overdue · Due 30d · Completed (quarter) · GP accept %**; banner for overdue; footer = quarter cycle stepper + search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | no site/colour (§3b) | `reviews()`: flat payload (drop pagination), `?site_id` filter (via client.site_id), sites/active_site/site_brand_colour | feature: brand colour + payload |
| G1 | actions/meds never surfaced | payload: per-review `actions[]` + `medications_reviewed[]` + reviewer assigned name | feature: payload has actions |
| G2 | no deprescribing lifecycle | `deprescribing[]` aggregation (completed reviews' non-Continue actions → {review_id,index,drug,action,rationale,gp_status,stage,client,reviewer}); **`advanceReviewAction()`** + route `emar.reviews.actions.advance` (gp→implemented→monitor→done; gp_status=accepted leaving gp) | feature: advance moves stage |
| G4 | no DBI/falls | migration `drug_burden_index` + `falls_last_quarter`; model fillable/casts; completeReview accepts them | feature: complete stores DBI |
| G8 | quarter KPIs + GP-accept% | computed: completed-this-quarter, GP acceptance % from decided actions | — |

## 4. Cross-module (§6)
- Reviews link to clients (resident chart). Deprescribing surfaces `actions[]` already stored. Sidebar "Reviews" → `/emar/reviews` (unchanged). INR/monitoring modal retirement DEFERRED (keep `emar.clients.inr.index`).

## 5. Retire → fold into modals
- `ScheduleReviewDialog`/`EditReviewDialog`/`CompleteReviewDialog` (off-contract) → rebuilt on `MedsWizardDialog` (Schedule 4-step + Conduct 5-step replacing Edit+Complete). No route changes. INR page retirement deferred.

## 6. Execution checklist
- [ ] Backend: migration (DBI/falls); model fillable/casts; `reviews()` rebuild (flat + site/brand + actions surfaced + deprescribing + KPIs); `completeReview` DBI/falls; `advanceReviewAction()` + route. Tests.
- [ ] Frontend: `_review-dialogs.tsx` (4 MedsWizardDialog modals); `Reviews.tsx` rewrite (hero + 6-tab + overview + list + Kanban).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d HARD RULE: MedsWizardDialog (handoff said WizardShell — overridden). Reuse Pages 1–8 patterns + site-filter/brand-colour payload + dialog-generalization.
- **Deferred:** right-click context menus (actions are inline buttons), INR/monitoring modal + INR-page retirement (separable page retirement — keep the page), CSV/PDF export + generate-routine-cycle + bulk-assign-reviewer (no backend), Print, `in_progress` status on conduct-open (G5 — conduct→complete in one flow), `medications.review.conduct` permission (needs seeder+deploy reseed — keep `medications.orders.manage`), recommendations table (G2 follow-up — JSON actions[] suffices for the pipeline). Reasons: separable infra / new permissions/seeders / cross-page; core = brand 6-tab governance board + 4 wizards + deprescribing Kanban + DBI capture.
