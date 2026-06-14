# eMAR Redesign — Page Plan: Self-Administration (`/emar/self-admin`)

## 0. Identity
- **Route:** `GET /emar/self-admin` → `emar.self_admin` (`EmarController@selfAdmin` :1952).
- **Inertia page:** `resources/js/pages/emar/SelfAdmin.tsx` (rewrite).
- **Write endpoints — ALL EXIST:** `emar.self_admin.store` (:2864), `.update` (:2909), `.destroy` (:2937, hard-delete → fix to soft).
- **Model:** `MedicationSelfAdminAssessment` — 5 capacity scores (1–5, total /25), 6 capability booleans, `outcome` (independent/prompted/supervised/administered = Cat 1–4), `totalScore`/`outcomeLabel`/`isReassessmentDue` accessors. Missing: consent, people, supports, storage, supersede, agreement, per-med scope, SoftDeletes.
- **Goal:** flat table + inline Dialogs → brand hero + 5-tab `TabStrip` + a single 5-step wizard (new/view/reassess), all on `MedsWizardDialog` (§3d), fixing 3 server bugs and surfacing consent-first NZ-MOH-category workflow.

## Key findings / bugs (§5)
- **Bug 1 — consent not enforced:** `storeSelfAdmin` computes outcome purely by score; a person who declines (willing=false) can still land Cat 1. **Fix:** centralise `computeOutcome($wishes,$willing,$total)` → force `administered` when wishes/willing false. Used by store AND update.
- **Bug 2 — stale category:** `updateSelfAdmin` takes a client-passed `outcome` and never recomputes from scores. **Fix:** recompute server-side, ignore client outcome.
- **Bug 3 — hard delete:** `destroySelfAdmin` removes a clinical row. **Fix:** SoftDeletes; reassess **supersedes** (new record links `supersedes_id`, old retained, excluded from the live register).
- **Uncollected/unsent:** `reassessment_trigger` never collected; `risk_factors`/`support_needed`/`safe_storage_notes`/`assessor_notes` stored but never shipped → ship full nested rows so the modal hydrates (no separate GET endpoint).

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats Self-managing/Supervised/Due/Independent%, badges, footer search + site) | `PageHero` + `brandColour` | flat payload + KPIs |
| Tabs (assessments/reassess/agreements/permed/activity) | `TabStrip` | client-side facets |
| Register table / reassess cards / agreements list / per-med cards / activity feed | inline | `assessments[]` (+ derived) |
| New / View / Reassess assessment (5-step) | **BUILD** `AssessmentWizardDialog` | `self_admin.store` (new+reassess) / `.update` |
| Sign agreement (1-step) | **BUILD** `SignAgreementDialog` | `self_admin.update` |
| Per-medication scope (1-step) | **BUILD** `MedScopeDialog` | `self_admin.update` |

## 2. Hero spec
Eyebrow live-ping `SELF-ADMINISTRATION OVERSIGHT · live`; title "Self-administration across {site underlined / N clients}"; description "Independence first; staff step in only where the risk assessment says so."; stats **Self-managing (Cat 1+2) · Supervised (Cat 3) · Due now · Independent %**; badges reassessments-due/unsigned; footer search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | parity | `selfAdmin()`: flat payload (drop pagination) + full nested rows + `?site_id` brand colour + sites | feature: brand colour + payload |
| bugs | outcome/delete | `computeOutcome()` (consent-first) in store+update; recompute on update; SoftDeletes + supersede | feature: declined→administered; update recomputes; destroy soft-deletes |
| schema | missing workflow data | **migration** `wishes_to_self_administer` (bool) + `people_involved`/`support_adjustments`/`med_scope` (json) + `storage_location` (string) + `reassessment_interval_months` (int) + `supersedes_id` (FK self) + agreement (`agreement_signed_at`/`agreement_signed_by`/`ordering_responsibility`/`agreement_responsibilities`) + SoftDeletes | feature: store persists consent/people/supports |
| derived | tabs | agreements (Cat 1/2 + agreement fields), per-med (med_scope), activity (assessed/reassessed/signed feed), live register excludes superseded | — |

## 4. Cross-module (§6)
- Self-admin category gates whether a client self-manages — surfaced as category pills. Sidebar "Self-Admin" → `/emar/self-admin` (unchanged). Reassessment supersede chain keeps history.

## 5. Retire → fold into modals
- Inline new/edit Dialogs → one `MedsWizardDialog` 5-step wizard (new/view/reassess) + Sign-agreement + Med-scope modals. No standalone pages. No route changes (destroy stays, now soft).

## 6. Execution checklist
- [ ] Backend: migration (workflow cols + SoftDeletes); model (fillable/casts/SoftDeletes/computeOutcome/relations); `selfAdmin()` rebuild (flat + brand + derived + supersede filter); store (consent-first + supersedes_id), update (recompute + agreement + med_scope), destroy (soft). Tests.
- [ ] Frontend: `_self-admin-dialogs.tsx` (5-step wizard + SignAgreement + MedScope); `SelfAdmin.tsx` rewrite (hero + 5-tab + register/reassess/agreements/permed/activity).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d HARD RULE: MedsWizardDialog (handoff said WizardShell — overridden). Reuse Pages 1–10 patterns + brand-colour payload.
- **Folded into columns, not new tables** (handoff suggested `self_admin_med_scope` + `self_admin_agreement` tables): `med_scope` JSON + agreement_* columns on the assessment keep the Per-med and Agreements tabs real without two new tables; promoting to tables (queryable per-med history) is a backlog refinement.
- **Deferred:** assess/sign **Policy** (who may assess) — kept `medications.orders.manage` gate; agreement "Send to sign" notification fan-out (the Sign action records signature directly); full audit-log-backed Activity (derived feed from records instead). Reasons: separable infra / new policy+seeders; core = brand 5-tab board + consent-first 5-step wizard + supersede reassess + agreement + per-med scope + 3 bug fixes.
