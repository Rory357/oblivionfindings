# Rostering — Sites/Coverage: Production-Readiness Plan

> **Scope flag from review**: "Rostering — Sites/Coverage — Partial — Integrated with roster/shift coverage; needs clearer operational resolution loops."
>
> **Constraint**: targeted production-ready fixes only. No rewrite. Strengthen the existing services, controllers, UI actions, and tests.

## 0. Why this was likely flagged "Partial — needs clearer operational resolution loops"

Evidence walked from the code, not architecture review:

1. **Two surfaces, divergent horizons.** [`RosteringController::index`](../app/Http/Controllers/RosteringController.php) (around line 347) builds `coverageSites` / `coverageAlerts` for the **whole week** via `buildSiteSummaries(weekStart, weekEnd)`. But [`ShiftAutoAlertJob::detectUncoveredShifts`](../app/Jobs/ShiftAutoAlertJob.php) (line 200) only emits Control Room signals for windows in `now() … now()+30 min`. So the rostering page knows about Tuesday afternoon's gap on Sunday morning; Control Room never does until 30 min before. Operationally there is no single resolution loop — there are two.

2. **Auto-resolve can falsely close real gaps.** [`resolveCoverageAlerts`](../app/Jobs/ShiftAutoAlertJob.php) (line 353) takes every unresolved coverage alert and resolves any whose `coverage_window_key` is missing from the **30-min lookahead**. That set is computed only from `now()+30 min` in `detectUncoveredShifts`. The resolution criterion is "the alert's window isn't in the next 30 min", not "the alert's window is now actually covered". An alert emitted at 09:55 for a 10:00–14:00 gap will be auto-resolved as soon as the lookahead has rolled past 10:30 — even with no fix.

3. **Unbounded cleanup scan.** [`resolveLegacyShiftUncoveredAlerts`](../app/Jobs/ShiftAutoAlertJob.php) (line 492) iterates `Shift::query()->where(user_id IS NOT NULL OR status IN [cancelled, completed])->each(...)`, calling `coverageStatusForShift` per row — every 5 minutes ([`routes/console.php`](../routes/console.php) line 117). That's a full-coverage-build per assigned/historical shift, with no time bound.

4. **No manager-side triage state.** Coverage gaps are computed inline on each request. [`resources/js/pages/operations/rostering/index.tsx`](../resources/js/pages/operations/rostering/index.tsx) (lines 2150–2410) renders them as cards with "Create cover shift / Create open shift / Recurring cover" actions but no "ack", "I'm working on it", or "dismiss with reason" — same gap re-renders identically across reloads with no ownership trail.

5. **Resolution audit is generic.** [`SignalProcessingService::resolveAlert`](../app/Services/ControlRoom/SignalProcessingService.php) (line 879) writes a `resolution_history[]` entry with `reason`/`source`/`metadata`, but `resolveShiftCoverageAlert` (line 569) only passes `coverage_window_key`. The shift_id, series_id, actor, and chosen action that actually closed the gap are not captured. Forensic answer to "who fixed last Tuesday's gap?" is not derivable.

6. **Partial-window detection exists but doesn't gate resolution.** [`ShiftCoverageService::buildContradictions`](../app/Services/ShiftCoverageService.php) (line 914) emits `partial_window_undercoverage`. But the auto-resolve check (`ShiftAutoAlertJob::isActionableCoverageGap`, line 424) only looks at `has_actionable_gap` / `unfilled_after_open_shifts` / `role_shortages`. As soon as `lowestAssigned >= minimum`, the gap is "resolvable" even if a recurring series later padded the dip without anyone acting.

7. **GET creates side effects.** [`ShiftController::create`](../app/Http/Controllers/ShiftController.php) (line 560) calls `createQuickFillReservation(...)` inside the form-render GET. Stale tokens accumulate when managers open the form and walk away.

8. **Reservation/role accounting blind spot.** [`CoverageReservationService::availableSlotsForWindow`](../app/Services/CoverageReservationService.php) (line 313) computes role slots against `role_shortages` (assigned-only deficit), ignoring `planned_role_shortages`. If an open shift already exists for that role, the same slot can be reserved twice.

9. **Test coverage gaps backing the above.** [`tests/Unit/Services/ShiftCoverageServiceTest.php`](../tests/Unit/Services/ShiftCoverageServiceTest.php) covers basic deficit, expired reservations, window boundary, role gap when no staff. [`tests/Feature/ShiftControlRoomSignalPipelineTest.php`](../tests/Feature/ShiftControlRoomSignalPipelineTest.php) covers no-show/late/uncovered/resolution-after-restore — but **not**: a future-window alert when the job runs early; partial-window remaining open; role-only gap remaining open after headcount fills; reservation contention; recurring drift remediation; orphan series resolution. There are zero browser/E2E tests for the rostering coverage flow.

10. **Cross-org scoping at boundaries.** `ShiftCoverageService::buildRangeCoverage` reads `SiteCoverageRequirement::active()` and `Shift::query()` with no org/tenant filter; relies entirely on site_id matching. Worth tightening as a hardening pass; existing posture is system-wide.

This is consistent with "Integrated, but the operational resolution loop is not closed" — the pieces work individually, the round-trip from "manager sees gap" to "system says fixed by X with audit" doesn't.

---

## 1. Current state — preserve

These are good and should be left alone:

- **`ShiftCoverageService` decomposition**: rule expansion → shift/series matching → slice-by-slice scan → contradictions → fill_intent. Strongly typed, deterministic, slice-minutes pluggable. (`buildRangeCoverage` is the right shape.)
- **`gap_kind` and `imbalance_kind` taxonomy** (`ShiftCoverageService.php` lines 821–882) and the matching UI labels (`rostering/index.tsx` lines 416–457). Good operator vocabulary.
- **Idempotency keys**: `buildShiftIdempotencyKey`, `buildCoverageWindowKey`, `buildCoverageDeficitSignature` (`ShiftSignalService.php` lines 83–130). Tested and stable.
- **Pessimistic-lock + token revalidation** in `CoverageReservationService` (concurrency primitive is sound).
- **Signal/alert state-transition logic** (`no_show → late_start`) in `SignalProcessingService::addSignalToAlert` (lines 354–412). Already audit-logged.
- **`SiteCoverageRequirement` CRUD** (`SiteComplianceController.php` lines 273–365). Out of scope.
- **Recurring `coverage_type/role_requirements` schema and `CoverageRoleService`**. Sound.

---

## 2. Production gaps — code-evidenced

| # | Gap | Evidence |
|---|---|---|
| G1 | Auto-resolve fires on lookahead miss, not on actual coverage check | `ShiftAutoAlertJob.php:353-377` + 30-min build on line 200 |
| G2 | Legacy cleanup scans entire shifts table every 5 min | `ShiftAutoAlertJob.php:492-520`, scheduled `everyFiveMinutes()` (`routes/console.php:117`) |
| G3 | No manager-side ack / triage state — same gap re-renders forever | `RosteringController.php:347-365`, no ack model anywhere (`grep` confirms) |
| G4 | Coverage alert resolution metadata loses the actor/shift/action that closed the gap | `SignalProcessingService.php:569-590` |
| G5 | Partial-window flag detected but doesn't gate auto-resolve | `partial_window_undercoverage` at `ShiftCoverageService.php:914`, unused by `ShiftAutoAlertJob.php:424` |
| G6 | Reservation created on GET (form render side-effect) | `ShiftController.php:560-575` |
| G7 | Role-slot accounting uses assigned-only shortage, not planned | `CoverageReservationService.php:313-336` |
| G8 | No tests for: future-window false-resolve, partial-window persistence, role-only deficit persistence, reservation contention, recurring-drift remediation | `tests/Unit/Services/ShiftCoverageServiceTest.php`, `tests/Feature/ShiftControlRoomSignalPipelineTest.php` |
| G9 | No browser/E2E test for the rostering coverage flow | grep on `tests/Browser` confirms |
| G10 | "review_existing_supply" / "rebalance_existing_supply" gap kinds offer no in-context action | `rostering/index.tsx:460-464` hides creation buttons; `conflicts.tsx:735` same |

---

## 3. Minimal target state — what "production ready" means here

A site coverage gap has one **observable lifecycle** with one source of truth:

1. **Detected** (`ShiftCoverageService`, planning horizon = the rostering view week, not 30 min).
2. **Surfaced** with provenance: who detected, when, gap_kind, contradictions, current planned/assigned/role state.
3. **Owned**: a manager can `ack` (claim), `dismiss with reason` (record an explicit decision to not act), or do nothing.
4. **Acted on** via one of the existing five paths: assign staff to an existing open shift, create a new open shift, create a recurring cover, retag/replace an open shift's role mix, or review existing supply.
5. **Confirmed resolved** only when the rule is **actually satisfied for the full window AND role mix** — checked against the alert's own window, not the next-30-min lookahead.
6. **Audited end-to-end**: detect → ack → action → resolution metadata includes the actor, the shift_id/series_id, and the chosen action.

Nothing about this requires a new module — every primitive already exists. The plan is to bridge them.

---

## 4. Small PR plan (no rewrite)

Each PR is independently mergeable, behind no feature flag (existing flags around publish/auto-schedule are unrelated). All small.

### PR A — Tighten the coverage-alert auto-resolve to a real coverage check

**Scope**: `app/Jobs/ShiftAutoAlertJob.php` only.

**Change**:
- In `resolveCoverageAlerts`, replace the "key not in 30-min active set ⇒ resolve" rule with: for each unresolved coverage alert, parse its stored `coverage_window_key` window range from context, call `ShiftCoverageService::findCoverageWindow(site_id, starts_at, ends_at, rule_id)`, resolve **only if** `has_actionable_gap == false` AND `has_actionable_imbalance == false` AND `partial_window_undercoverage` is not in `contradictions`.
- If the window is wholly in the past AND no observation snapshot exists, mark resolved with `source = 'window_elapsed'` (distinct from `coverage_restored`) so it's filterable in audit.

**Acceptance criteria**:
- `test_coverage_alert_for_future_window_does_not_self_resolve_when_job_runs_at_unrelated_time` — emit gap for window 14:00 on day+1, run job at 09:00, assert alert remains `open`.
- `test_coverage_alert_resolves_only_when_actual_window_is_now_covered` — gap, fill, run job, assert resolved with `source='coverage_restored'`.
- `test_partial_window_gap_does_not_resolve` — 10:00–14:00 rule, only 10:00–12:00 shift, assert alert stays open with `partial_window_undercoverage` in metadata.
- All existing `ShiftControlRoomSignalPipelineTest` tests pass.

**Risk**: tighter resolution criteria → alerts may stay open longer than today. Acceptable; today's "resolved" is silently false.

**Rollback**: revert the file. Behavior reverts to current 30-min-lookahead heuristic.

---

### PR B — Bound `resolveLegacyShiftUncoveredAlerts`

**Scope**: `app/Jobs/ShiftAutoAlertJob.php:492-520`.

**Change**: Reverse the lookup. Start from `ControlRoomAlert::unresolved()->where(alert_type='Shift Uncovered')->whereNotNull(JSON shift_id in context)`, extract the shift_ids, then `Shift::whereIn(id, …)->each`. Cap at e.g. 500 alerts per run. Drop the table-wide scan.

**Acceptance criteria**:
- Unit test: with no unresolved per-shift uncovered alerts, the loop iterates **zero** shifts (assert via spy on `coverageStatusForShift`).
- Existing pipeline tests pass.

**Risk**: very low. The legacy cleanup only ever needed to re-check shifts referenced by alerts.

**Rollback**: revert.

---

### PR C — Manager-side coverage gap acknowledgement & dismiss-with-reason

**Scope**:
- New migration: `coverage_gap_acknowledgements` table — `id`, `organization_id`, `site_id`, `coverage_requirement_id`, `coverage_window_key` (string), `window_starts_at`, `window_ends_at`, `state` (`acked|dismissed`), `reason` (nullable for ack, required for dismiss), `actor_user_id`, `created_at`, `cleared_at` (nullable).
- New model `App\Models\CoverageGapAcknowledgement`.
- New endpoints `POST /operations/rostering/coverage/{key}/ack`, `POST /…/dismiss`, `DELETE /…/clear` (in `RosteringController` or a dedicated `CoverageGapController` — recommend the latter for keeping `RosteringController` from growing further).
- `ShiftCoverageService::buildSiteSummaries` joins active acks by `coverage_window_key` so each alert payload carries `acknowledgement: {state, actor, reason, since}`.
- UI: small badge + popover on each alert card in `rostering/index.tsx` (around line 2150) and `conflicts.tsx`. No layout rewrite.
- `AuditLogger::log('rostering.coverage.ack' | 'rostering.coverage.dismiss' | 'rostering.coverage.clear', $ack, [...])` per transition.

**Acceptance criteria**:
- Feature test: ack persists across reload; auto-resolve clears the alert but the ack remains as audit (state ⇒ `cleared_at` set).
- Feature test: dismiss requires `reason`; rendered alerts show the dismissal but in muted style.
- Audit log assertions on the three transitions.

**Risk**: medium — new table & UI surface. Mitigation: ack is purely advisory; absence does not change resolution logic. If reverted, alerts behave exactly as today.

**Rollback**: drop migration, controller, UI badges. Coverage detection unchanged.

---

### PR D — Capture the action that resolved the gap

**Scope**:
- `app/Services/CoverageReservationService.php::fulfill` (line 149): emit a domain event `CoverageSupplyAdded` with `{coverage_window_key, shift_id, actor_id, action}` (action derived from the reservation's `reason` field — `quick_fill` / `shift_store` / `assignment` / `job_board_claim` / etc.).
- `app/Services/ControlRoom/SignalProcessingService.php::resolveShiftCoverageAlert` (line 569): accept structured `metadata` (already does) and **always** include `resolution.actor_user_id`, `resolution.shift_id` (or `series_id`), and `resolution.action` when called from this event handler.
- A small listener that calls `resolveShiftCoverageAlert(...)` when the resolved coverage check (PR A) finds `has_actionable_gap == false` AND a `CoverageSupplyAdded` event matches the same window in the last N minutes.

**Acceptance criteria**:
- Feature test: navigate the coverage-create flow, create a shift that closes the gap, run the alert job, assert the resolved alert's `context.resolution` contains `shift_id`, `created_by`, and a non-null `action`.
- The `'Coverage-gap alert resolved …'` reason string is preserved for backwards compatibility.

**Risk**: low. The metadata is additive; existing callers of `resolveShiftCoverageAlert` don't need changes.

**Rollback**: revert. Resolution still works, just with less metadata.

---

### PR E — Partial-window resolution guard

**Scope**: `app/Services/ShiftCoverageService.php` and `app/Jobs/ShiftAutoAlertJob.php::isActionableCoverageGap` (line 424).

**Change**:
- Add `partial_window_uncovered_slices: [{starts_at, ends_at, missing}]` to the window summary returned by `summarizeWindow` (already computed inside the slice loop; just expose).
- Extend `isActionableCoverageGap` so partial-window contradictions count as actionable.
- Extend the resolved-window check from PR A so it is "not resolved" if `partial_window_uncovered_slices` is non-empty.
- Surface partial slices in the UI's contradictions chip ("12:00–14:00 still uncovered").

**Acceptance criteria**:
- Unit test in `ShiftCoverageServiceTest`: 10:00–14:00 rule, single 10:00–12:00 shift, assert `partial_window_uncovered_slices` includes 12:00–14:00 with `missing >= 1`.
- Feature test: alert remains open and surfaces partial slices in the rostering payload.

**Risk**: low. Existing slice logic already has the data; this exposes it.

**Rollback**: revert. Behavior reverts to lowestAssigned-only check.

---

### PR F — Move reservation creation off GET

**Scope**: `app/Http/Controllers/ShiftController.php::create` (line 505) and the `buildCoverageCreateHref` flow in `rostering/index.tsx` (line 528).

**Change**:
- New `POST /operations/coverage/reservations` that takes `{site_id, coverage_rule_id, starts_at, ends_at, role_key?}` and returns `{token, expires_at}`.
- The rostering UI buttons issue this POST first via Inertia, then navigate with the token in URL/POST body.
- `ShiftController::create` no longer creates reservations; it only validates an inbound token if present.
- Add idempotency: re-clicking the same gap reuses an unexpired reservation for the same actor.

**Acceptance criteria**:
- Feature test: GET to `/operations/shifts/create?coverage_rule_id=…` does NOT create a `coverage_reservations` row.
- Feature test: POST to `/operations/coverage/reservations` does, and re-POST returns the same row's token if unexpired.
- UI smoke test: clicking "Create cover shift" still lands on the create form with token populated.

**Risk**: medium — breaks any deep-link bookmarks that rely on the old GET side-effect. None observed in code; flag for QA.

**Rollback**: revert the controller + UI changes; the POST endpoint can stay no-op.

---

### PR G — Tests, observability, and one E2E

**Scope**: tests only; no production behavior change beyond what A–F land.

**Change**:
- `tests/Unit/Services/ShiftCoverageServiceTest.php`: partial-window slice exposure, role-only deficit persistence after headcount fills, multi-rule worst-window selection, overstaffing rejection when `allow_overstaffing=false`, recurring drift / orphan series payload shape.
- `tests/Feature/ShiftControlRoomSignalPipelineTest.php`: future-window false-resolve fix, partial-window persistence across runs, resolution-metadata actor capture (PR D), legacy-cleanup bound (PR B).
- `tests/Feature/Rostering/CoverageGapAckControllerTest.php`: ack lifecycle, dismiss requires reason, audit lines.
- `tests/Browser/Operations/RosteringCoverageTest.php`: seed a coverage gap → render `/operations/rostering` → assert card visible → click "Create open shift" → confirm gap card disappears on next render.
- A `Log::info('coverage.alert.resolved', ['source' => …, 'actor_id' => …, 'window_key' => …])` line in `SignalProcessingService::resolveAlert` for daily resolution-source histograms in production.

**Acceptance criteria**: CI green; ≥1 browser test for coverage flow exists.

**Risk**: trivial.

---

## 5. Operational resolution loop — explicit mapping after PR A–G

This is the closed loop, with each step grounded in code-after-changes:

| Step | Where it happens | What enforces it |
|---|---|---|
| Manager sees the gap | Rostering dashboard alert card (`rostering/index.tsx:2150`) | `RosteringController::index` → `buildSiteSummaries` |
| Manager triages | Ack / dismiss-with-reason badge | PR C — `CoverageGapController` |
| Manager picks an action | Existing five buttons: fill open shift, create open shift, recurring cover, retag/replace, review supply | Already exists; PR F just makes the reservation honest |
| System reserves the slot | `CoverageReservationService::reserveForCoveragePayload` / `reserveForAssignment` | Existing; PR F shifts the trigger to POST |
| Manager submits the shift | `ShiftController::store` / `assign` fulfills the reservation | Existing |
| System emits resolution event | `CoverageSupplyAdded` from `fulfill` | PR D |
| Auto-job re-checks the actual window | `ShiftAutoAlertJob` per-alert recheck | PR A |
| Partial fill stays open | Slice-level check | PR E |
| Alert resolved with audit | `SignalProcessingService::resolveAlert` writes `resolution.{actor_id, shift_id, action}` | PR D |
| Cross-surface consistency | Same `coverage_window_key` and same resolved status visible on both rostering and Control Room | Existing keys; PR A makes auto-resolve honest |

---

## 6. Risk notes & rollback

- **PR A is the load-bearing fix.** It changes when alerts resolve. Historic open alerts may unblock once the recheck runs. Mitigation: dry-run report mode for one cron cycle (log what *would* resolve vs current behavior) before flipping. Rollback = revert.
- **PR B** changes a hot path's query shape. Risk = a missed edge case where a per-shift alert exists without `shift_id` in context. Add a fallback that logs and skips.
- **PR C** adds DB writes. Schema-only migration, additive. No backfill needed.
- **PR D** depends on event ordering — make the listener idempotent and tolerant of receiving the event before the alert exists (queue retry).
- **PR E** is a data-shape addition. UI consumers must tolerate the new field (already optional in `CoverageAlert` type).
- **PR F** is the only one with a user-visible behavior change (POST before navigation). Smoke-test the journey before ship.
- **Multi-tenancy hardening** is **out of scope** for this plan but noted: `ShiftCoverageService::buildRangeCoverage` and `SiteCoverageRequirement::active()` should accept an org_id boundary in a follow-up. Don't bundle here.

---

## 7. Do not touch / do not redo

- **Do not redo `ShiftCoverageService` core algorithm** (rule expansion, slice scan, contradictions, fill_intent). It is correct.
- **Do not redo idempotency keys** (`buildCoverageWindowKey`, `buildShiftIdempotencyKey`, `buildCoverageDeficitSignature`).
- **Do not redo `SignalProcessingService::ingest/process/createAlertFromSignal`**. Coverage signals route through it cleanly already.
- **Do not redo the `gap_kind` / `imbalance_kind` taxonomy**. Extend metadata; keep the names.
- **Do not redo `CoverageReservationService` lock + token mechanics**. PR F only changes the *trigger* (GET → POST) and adds idempotency for re-clicks. The transactional core stays.
- **Do not redo `SiteCoverageRequirement` schema or `SiteComplianceController` CRUD**.
- **Do not redo the rostering React page structure** (`resources/js/pages/operations/rostering/index.tsx` — 5,452 lines). Add a small ack badge + popover to existing alert cards; don't refactor the dashboard.
- **Do not touch publish/auto-schedule/replacement workflows** — `RosteringFeatureFlags`, `RosterPublishingService`, `RosterSuggestionService`, `ShiftReplacementService`. They are unrelated to the coverage resolution loop.
- **Do not change the `ShiftAutoAlertJob` cadence** (5 min). It's adequate; PR B/A make it cheap and correct.

---

The architecture supports production. The gaps are plumbing and protocol around the resolution loop, not the model. Seven small PRs, A and B first as bug-class fixes, C–G as the loop-closing work.
