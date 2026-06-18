# H&S Events register redesign — Gap Analysis (single source of truth)

> **NZ-only** (WorkSafe NZ / HSWA 2015 notifiable events s23/s24 + death; notify ASAP / preserve site / keep records 5 yrs; Ngā Paerewa NZS 8134:2021; ACC; methodologies 5-whys/fishbone/bow-tie/ICAM/TapRooT). **Web-only** (no phone frames).
> Loop file for the Events register redesign. Checklist `[ ]` / `[x]`. Seeded 18 Jun 2026 from `HEALTH_SAFETY_EVENTS_DESIGN_PROMPT.md` + `HEALTH_SAFETY_EVENTS_LIFECYCLE_PLAN.md` and the verified code audit. Build on it; re-audit live code each pass before ticking.

## Verified current state (the baseline)
- **Convergence works:** 8/11 categories wired via observers → `HsEventService::recordEvent()` (incident, near_miss, safeguarding, vehicle_incident, injury, hazard, restraint, drill_failure). **Orphans:** `exposure`, `inspection_failure`, `equipment_fault`.
- **Engine is strong (reuse, don't rebuild):** `HsInvestigation` (gated draft→in_progress→findings_recorded→under_review→completed; methodologies) + `HsInvestigationService`; `HsCorrectiveAction` (gated open→in_progress→completed→verified→closed; verify ≠ completer; auto-advances event to monitoring) + `HsCorrectiveActionService`; `HsRiskAssessment` (5×5); `HsAttachment`.
- **UI off-standard + read-only:** `events/index.tsx` (old `PageHero`, shadcn `Select` filters, **no `TabStrip`/right-click**, no source column, navigate-away); `events/show.tsx` (**full page**, shadcn `Tabs`, **read-only** — no investigation/CA/WorkSafe/closure actions; source shown as plain text).
- **Governance holes:** event **closure ungated**; WorkSafe **notification passive** (status stuck at pending); investigation/CA services have **no controllers/routes/UI**.

---

## A. Convergence completeness
- [x] A1 — Every wired category (8) has a row treatment + a visible **source/category badge**; the register is the unified convergence view (category = filter, not hidden).
- [x] A2 — **Two-way source back-link** (E-Gap 4): resolve `HsEvent.source()` to label + URL; "View originating record" on the detail Overview + row menu (incident/safeguarding/fleet/injury/hazard/restraint/drill).
- [ ] A3 — **Orphan categories** (E-Gap 5): decide wire-or-remove `exposure` / `inspection_failure` / `equipment_fault`. **Decision required (flag to user).**

## B. Backend governance (expose + gate the existing engine — NO new schema)
- [x] B1 — **`HsEventService::closeEvent()`** + closure gate (E-Gap 1): block unless required investigation completed + no open/unverified corrective actions + `closure_summary`; override w/ logged reason + permission. `HsEventController@close` + route + `hazards.manage`.
- [x] B2 — **WorkSafe notification** (E-Gap 2): service methods to record notification (→ `notified`: date/method/reference) + acknowledgement (→ `acknowledged`); reuse `NotifiableEventClassifier`; make the `worksafe-register` report actionable.
- [x] B3 — **`HsInvestigationController` + routes** (E-Gap 3) exposing `HsInvestigationService` (create/start/recordFindings/submitForReview/returnForRework/complete). Gate `hazards.manage`.
- [x] B4 — **`HsCorrectiveActionController` + routes** (E-Gap 3) exposing `HsCorrectiveActionService` (createFromRecommendation/createStandalone/start/complete/returnForRework/verify/close). Gate `hazards.manage`.
- [ ] B5 — **Auto-advance on investigation complete** (E-Gap 6): event → `corrective_action` + offer `createFromRecommendation()` for each recommendation.

## C. List (`/health-safety/events`) — hero, tabs, rows
- [x] C1 — Replace `PageHero` with **`hs-hero-kit`** treatment; governance clusters (Live / Needs attention incl. **Investigation overdue**, **Actions awaiting verification**, **WorkSafe notify due**); no compliance badges.
- [x] C2 — `TabStrip`: All · Open · Investigating · Corrective actions · WorkSafe-notifiable · Monitoring · Closed (category = filter).
- [x] C3 — Footer band: date-range `HeroSegmented`, Site + Category + Source `EntityFilter` (`onDark`), severity + worksafe filter, search.
- [x] C4 — Replace plain row-nav with **`ShiftContextMenu`** right-click (copy PRN `openRowCtx`) + row click → event detail modal. Item set per design §2c.
- [x] C5 — Row badges: category + source module, severity, status, investigation-required/overdue, actions-awaiting-verification, WorkSafe-notifiable/status.

## D. Event detail = modal governance workspace (retire navigate-away)
- [x] D1 — Build **`HsEventDialog`** on `WizardShell` read-only chrome (rail = sections, footer = Options bar); opens **over** the register + from source modules' "Open in Health & Safety". Keep `/health-safety/events/{id}` as deep-link fallback.
- [x] D2 — Rail sections: **Overview** (governance stage tracker + source back-link + WorkSafe banner w/ site-preservation) · **Investigation** (actionable) · **Corrective actions** (actionable) · **Risk** (RiskMatrix) · **Timeline** (EventTimeline) · **Evidence** (HsAttachment).
- [x] D3 — Options bar actions open modals in place; **disable + explain** gated actions.
- [x] D4 — **Modal-first sweep:** all governance actions are dialogs — no navigate-away in the normal path.

## E. Investigation workflow (actionable, gated)
- [x] E1 — **Start investigation** modal: methodology picker (5-whys/fishbone/bow-tie/ICAM/TapRooT), lead + team (guard: event open, no active investigation).
- [x] E2 — **Record findings** modal: immediate/root causes, contributing factors, recommendations (guard: ≥1 cause).
- [x] E3 — **Submit for review → Review → Complete** gated steps (reviewer/approver); show gate states.

## F. Corrective actions (actionable, verified)
- [x] F1 — **Create** action (standalone or **from a recommendation**), assign + due + priority.
- [x] F2 — **Complete** (notes/evidence) → **Verify** (enforce **verifier ≠ completer** + effectiveness) → **Close**.
- [ ] F3 — Surface the **Corrective-actions register** (sibling view) cross-linked; reflect auto-advance of the event to monitoring when all resolved.

## G. WorkSafe + closure gates (the governance "make it make sense")
- [x] G1 — **Record WorkSafe notification** modal (notified date/method/reference → status; acknowledgement → acknowledged); notify-ASAP prompt + **site-preservation** status + keep-5-years note on notifiable events.
- [x] G2 — **Gated Close event** modal: blocked-reasons state (required investigation incomplete / open-unverified actions); `closure_summary` required; override w/ reason + permission.

## H. Standardisation, a11y, scope
- [ ] H1 — Hero/tab/filter/right-click/modal idioms match the app verbatim (governance twin of the source modules; one product with dashboard/analytics).
- [ ] H2 — Semantic tokens only (no raw `oklch()`); WCAG AA; keyboard-operable; no colour-only meaning; gated actions explain why; lazy-load.
- [ ] H3 — Web-only (no phone frames); responsive reflow.
- [ ] H4 — NZ-only frameworks current (HSWA s23/s24 notifiable events; strip any RIDDOR/HSE/CQC).

---

### Decisions log
- **18 Jun 2026** — Events = the governance hub; convergence already works (8 categories). Adopt the H&S gold standard: `hs-hero-kit` hero, `TabStrip`, `ShiftContextMenu`, **detail-as-modal** (`HsEventDialog`); `/health-safety/events/{id}` kept as deep-link fallback.
- **18 Jun 2026** — The investigation + corrective-action **engine is strong and stays**; the work is **exposing it (controllers/routes/modals), gating closure + WorkSafe notification, surfacing the two-way source link**, and resolving 3 orphan categories. **No new schema expected** (models already hold the fields).
- **18 Jun 2026** — Decision required: wire-or-remove `exposure` / `inspection_failure` / `equipment_fault`.
- **18 Jun 2026 (Step 5)** — C3 footer "Source EntityFilter" intentionally folded into the existing **Category** filter: every `event_category` maps 1:1 to a source module, so a separate Source-by-module filter is redundant. Category + Site + severity + WorkSafe + date-range + search compose the footer band. Source resolution (A2) is delivered as the per-event "View originating record" jump, not a filter.
- **18 Jun 2026 (loop pass 1 — AUDIT)** — Re-verified every A–H item against live code. Confirmed: the investigation + corrective-action engine is strong and fully gated (`complete()` already auto-advances the event to `corrective_action`; CA `verify()` enforces verifier ≠ completer; closing the last CA auto-advances to `monitoring`) → **B5 backend mostly exists**, remaining E-Gap 6 work is the per-recommendation "Seed action" affordance. `HsEvent` already holds `closed_at`/`closed_by`/`closure_summary` (**B1 needs no schema**) but **lacks** `worksafe_notified_at`/`worksafe_method`/`worksafe_acknowledged_at` (**B2 needs a small additive migration** — only deviation from "no new schema"; flagged). Absent as expected: `closeEvent`/`recordWorksafeNotification`/`acknowledgeWorksafe`, both governance controllers + all write routes, the `source()` `{label,url}` resolver. UI off-standard + read-only as described (E-Gap 7). Branch `feat/health-safety-events-redesign`; step plan in `docs/health-safety-events-redesign/PROGRESS.md`. Nothing yet satisfied → no items ticked.
