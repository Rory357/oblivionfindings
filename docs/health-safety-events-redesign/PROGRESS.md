# Health & Safety Events register redesign — PROGRESS tracker

> Self-paced `/loop` from the **"Events Inestigations Page" design drop** (`.design-drops/health-safety-events-redesign/`).
> **NZ-only** (WorkSafe NZ / HSWA 2015 s23/s24/death; notify ASAP / preserve site / keep ≥5 yrs; Ngā Paerewa NZS 8134:2021; ACC; 5-whys/fishbone/bow-tie/ICAM/TapRooT — no RIDDOR/HSE/CQC). **Web-only.**
> Branch `feat/health-safety-events-redesign` (off `main`, in the **MAIN repo** — not a worktree).
> Single source of truth for the A–H checklist: `docs/HEALTH_SAFETY_EVENTS_GAP_ANALYSIS.md`. Spec: `.design-drops/.../HANDOFF.md`.
> Scope: **expose + gate + standardise** the existing strong, gated governance engine. **No rebuild.** No new schema except the one small additive WorkSafe-notification migration in Step 3 (flagged).

## Audit baseline (verified against live code, 18 Jun 2026)
- **Engine is strong + gated (reuse, don't rebuild):** `HsInvestigationService` (draft→in_progress→findings_recorded→under_review→completed, all guarded; `complete()` already auto-advances the event to `corrective_action`), `HsCorrectiveActionService` (open→in_progress→completed→verified→closed; **verify ≠ completer enforced**; closing the last action **auto-advances the event to monitoring**; `createFromRecommendation`/`createStandalone`), `HsRiskAssessment` (5×5 bands), `NotifiableEventClassifier` (HSWA s23/s24/death → `{notifiable,category,authority,reason}`).
- **HsEvent columns present:** `closed_at`/`closed_by`/`closure_summary` (→ close needs **no schema**), `worksafe_notifiable`/`worksafe_status`/`worksafe_reference`. **Absent:** `worksafe_notified_at` / `worksafe_method` / `worksafe_acknowledged_at` (→ Step 3 needs a tiny additive migration — flagged).
- **Missing (the work):** `HsEventService::closeEvent/recordWorksafeNotification/acknowledgeWorksafe`; `HsInvestigationController` + `HsCorrectiveActionController` + all write routes; `source()` `{label,url}` resolver; seed-from-recommendations affordance; 3 orphan categories (`exposure`/`inspection_failure`/`equipment_fault`).
- **UI is off-standard + read-only:** `events/index.tsx` (`PageHero`, shadcn `Select`, no `TabStrip`/right-click/source column, navigate-away); `events/show.tsx` (full page, shadcn `Tabs`, read-only).

## Templates being mirrored (gold standard, just shipped)
- List: `resources/js/pages/safeguarding/index.tsx` (hs-hero-kit footer band, `TabStrip`, detail-over-list via `router.get(…,{only:['detail']})`, `openRowCtx` mirroring the Options bar 1:1).
- Detail-as-modal: `resources/js/components/incidents/incident-detail-dialog.tsx` (`WizardShell` read-only chrome, `section`+`action` panes, **flash.error guardrail**).
- Chrome: `@/pages/health-safety/components/hs-hero-kit`, `@/components/rostering` (`TabStrip`/`EntityFilter`/`ShiftContextMenu`), `@/components/wizard/{shell,primitives}`, `@/components/health-safety/{risk-matrix,event-timeline}`.

## Step plan (each: backend → route → modal → permission `hazards.manage` → acceptance; tick the gap analysis on completion)

- [x] **Step 1 — Foundation: list + detail-modal shell (read-only, gold standard).** [no schema] ✅ verified (tsc 0 · eslint 0 · php-lint 0 · 5 feature tests / 63 assertions green)
  - 1a Backend: `HsEventController::buildEventDetail()`; `index()` upgraded (hero counts, `tabCounts`, filters incl. category/site/severity/worksafe + search, rows payload w/ source + governance flags, `detail` when `?event=`); `show()` → thin shell rendering the dialog. (Source EntityFilter deferred to Step 5 with its resolver.)
  - 1b Frontend: new `@/components/health-safety/event-detail-dialog.tsx` (`HsEventDialog` — 6 read-only sections + stage tracker + WorkSafe banner + SoD note + Options bar) · rewrote `events/index.tsx` (hs-hero-kit + `TabStrip` + footer filters + standardised rows + `ShiftContextMenu` + open-over-list) · thin `events/show.tsx`.
  - Test: `tests/Feature/HealthSafety/HsEventRegisterTest.php` (payload, worksafe-tab scope, detail-over-list, deep-link shell, gating).
  - Acceptance ✅: register reads as the H&S gold standard; row/right-click opens the modal over the list; `/health-safety/events/{id}` deep-link renders the same modal on a thin shell; `HsEventBackboneTest` (observer/service) un-regressed.
- [ ] **Step 2 — Gated close (Item 1 / E-Gap 1, G2).** [no schema] `HsEventService::closeEvent()` + gate; `HsEventController@close` + `POST …/events/{event}/close`; Close-event ActionPane (blocked-reasons + required summary + logged override). Acceptance: incomplete required investigation / unverified action ⇒ cannot close except via logged override; clean event closes with a summary.
- [ ] **Step 3 — WorkSafe notify/acknowledge (Item 2 / E-Gap 2, G1).** [**SCHEMA** — additive: `worksafe_notified_at`, `worksafe_method`, `worksafe_acknowledged_at`] `recordWorksafeNotification()`/`acknowledgeWorksafe()` (reuse `NotifiableEventClassifier`); routes `…/worksafe/notify` + `/acknowledge`; WorkSafe ActionPane (notify-ASAP + site-preservation + keep-5-yrs); make `worksafe-register` report actionable. Acceptance: pending→notified (date/method/ref persisted)→acknowledged; register row offers the action while pending.
- [ ] **Step 4 — Investigation + corrective-action controllers (Item 3 / E-Gap 3, E+F).** [no schema] `HsInvestigationController` + `HsCorrectiveActionController` exposing the existing gated services; routes; investigation wizard pane (methodology/lead/team → findings → submit/review/complete) + CA panes (create standalone/from-rec, complete, verify w/ completer-exclusion, close). Acceptance: each modal action hits its transition; a forbidden transition surfaces the service's gate error in-UI, not a raw write.
- [ ] **Step 5 — Source back-link (Item 4 / E-Gap 4, A2).** [no schema] resolve `HsEvent::source()` → `{label,url}` in `buildEventDetail()` + row menu; "View originating record" (incident/safeguarding/fleet/injury/hazard/restraint/drill). Acceptance: every wired event jumps to its origin; orphans render disabled + explained.
- [ ] **Step 6 — Seed-from-recommendations (Item 6 / E-Gap 6 remainder, B5).** [no schema] "Seed action" per recommendation in the Investigation pane (`createFromRecommendation`). Acceptance: completing an investigation moves the event to corrective_action (already does) and each recommendation is one click from a corrective action.
- [ ] **Step 7 — Orphan categories (Item 5 / E-Gap 5, A3). ⚠️ STOP-AND-ASK the user.** Recommendation: wire `exposure` (hazardous-substance) + `equipment_fault` (Fleet/asset observer), **remove** `inspection_failure` unless a site-inspection module is planned. Acceptance: no category is both a selectable filter and impossible to create.
- [ ] **Step 8 — Polish, a11y, tests, regression, merge+deploy+verify (H).** Feature tests per item; tsc/eslint/build clean; axe AA; confirm no regression to dashboard/analytics heroes or the source modules' "Open in Health & Safety" jump; merge `--no-ff`; Chrome-verify on .com.

## Decisions log
- **18 Jun 2026** — Audit complete; engine confirmed strong + gated. Build in the MAIN repo on `feat/health-safety-events-redesign`. Foundation (list + read-only modal shell) is Step 1 (backend payload + frontend land together — they're coupled); write-action items 2–6 follow the "backend method → route → modal" discipline, each growing the dialog's Options bar (no stubs — actions appear only when their backend lands). Orphan-category decision deferred to Step 7 (stop-and-ask).

## Commits
- (none yet)
