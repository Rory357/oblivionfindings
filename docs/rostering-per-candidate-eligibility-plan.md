# Rostering Per-Candidate Eligibility Reasons — Implementation Plan

Status: active — `docs/rostering-redesign-followups.md` lists this as the recommended next step (highest user-facing value of the remaining items).

## Problem

The Open Shifts pane renders candidate suggestion chips via a JS-side filter (`suggestStaffForOpenShift` in `resources/js/pages/operations/rostering/index.tsx`). The JS filter only considers:

- Shift conflicts (overlap with another shift the user is already assigned to)
- Time off (overlap with an approved `StaffTimeOff`)

It does **not** consider the rest of the backend eligibility rules — compliance/training hard-stops, coverage roles, driver licence expiry, H&S training, fatigue, site assignment, or coverage overfill. As a result:

- Managers see chips for staff who will fail eligibility when the Assign action posts to `/operations/shifts/{shift}/assign` (the server-side handler runs the full check and 422s).
- There is no warning indicator for chips that are eligible-but-with-caveats (e.g. expiring compliance, tight turnaround).
- The doc's design called for "per-suggestion-chip detail" so the chip strip becomes a real triage surface.

## Approach

Additive payload + UI overlay — no refactor of the existing JS suggestion pipeline.

1. **Backend**: in `RosteringController::index`, after the open shifts list is built, evaluate `ShiftStaffEligibilityService::evaluate($shift, $user)` for each open shift × candidate combination. Pre-filter candidates with `ShiftStaffEligibilityService::candidatesFor($shift)` so we only evaluate the cheap-prefilter shortlist (already excludes hard-stop expired compliance, off-site staff, and inactive profiles). Cap at the top N candidates by available capacity to keep query budget bounded.
2. **Emit** a new top-level prop `openShiftEligibility` shaped as:
   ```ts
   { [shift_id: number]: { [user_id: number]: { status: 'warning' | 'blocked', reasons: string[] } } }
   ```
   "Eligible" candidates are omitted entirely (default UI = unstyled chip). Blocked entries take precedence over warning entries.
3. **Frontend**: in `index.tsx::suggestStaffForOpenShift`, look up the eligibility entry per (shift_id, candidate_user_id) and attach it to the suggestion object. In `open-shifts-pane.tsx`, render:
   - `eligible` (no entry) — current chip behaviour
   - `warning` — amber ring + ⚠ icon + `title` attribute with joined reasons. Still clickable to Assign.
   - `blocked` — slate-grey strikethrough + 🚫 icon + tooltip. Click is suppressed (Assign would 422 anyway).
4. **Tests**: extend the existing Pest feature test surface with a case proving the controller emits `openShiftEligibility` when a candidate has an expired hard-stop requirement. Extend `rostering-redesign-followups.test.tsx` with three render-state cases.
5. **Doc**: in `docs/rostering-redesign-followups.md`, move per-candidate eligibility reasons out of "Needs a richer backend payload" into a "Shipped" section, and update Recommended next step #1.

## Scope (in)

- Backend `openShiftEligibility` map keyed by shift_id then user_id.
- UI chip rendering for warning + blocked states.
- Tests for both server payload and client rendering.
- Doc refresh.

## Scope (out)

- Replacing the JS-side suggestion sort (capacity-ascending stays).
- Override workflow — the existing `/operations/shifts/{shift}/assign` handler already supports override prompts.
- Re-evaluating eligibility after the Assign click — server-side validation in the existing controller handles this.
- Surfacing eligibility on completed/scheduled shift cards (only Open shifts pane in this pass).

## Files

Backend
- `app/Http/Controllers/RosteringController.php` — add `buildOpenShiftEligibility()` helper, include result in Inertia payload.
- `tests/Feature/Rostering/RosteringOpenShiftEligibilityTest.php` — new feature test.

Frontend
- `resources/js/pages/operations/rostering/index.tsx` — add `openShiftEligibility` prop type, propagate into `suggestStaffForOpenShift` output and `OpenShiftCard.suggestions[]`.
- `resources/js/components/rostering/open-shifts-pane.tsx` — extend the chip type with optional `eligibility`; render the three states.
- `resources/js/components/rostering/rostering-redesign-followups.test.tsx` — three new test cases.

Doc
- `docs/rostering-redesign-followups.md` — mark this item done, update Recommended next step.
- `docs/rostering-per-candidate-eligibility-plan.md` — this file; deleted on completion, or kept as historical record.

## Performance considerations

Each `evaluate()` call runs roughly 10 underlying queries. For ~5 open shifts × ~8 prefiltered candidates = 40 evaluations = ~400 queries worst case. Pre-filter with `candidatesFor()` already prunes anyone failing hard-stop compliance / not on site. Cap at 8 candidates per shift on the eligibility pass.

If page-load cost regresses noticeably, follow-up work would eager-load compliance statuses + future bookings once per page-load and pass them to the rules — out of scope for this pass.

## Implementation steps

1. Add helper `buildOpenShiftEligibility(array $openShifts): array` to `RosteringController`. Iterate, pre-filter with `candidatesFor`, evaluate the top 8 per shift, collect `{status, reasons}` for warning/blocked candidates only. Swallow exceptions per candidate.
2. In `RosteringController::index`, call the helper after open shifts are built; add `'openShiftEligibility' => $openShiftEligibility` to the Inertia payload.
3. Add the new prop to the TS `Props` type in `index.tsx`. Extend the `eligible.push({...})` shape in `suggestStaffForOpenShift` to merge in `eligibility` lookups. The chip type already supports an arbitrary `meta` string but we'll add a typed `eligibility` field for clarity.
4. Update `OpenShiftCard.suggestions[]` type in `open-shifts-pane.tsx`. Render the three states.
5. Add the Pest test. Use the existing rostering test scaffold; create a User + an open shift + an expired hard-stop requirement on that user, hit the rostering index endpoint, assert the payload.
6. Add Vitest test cases.
7. Update follow-ups doc.

## Verification plan

Local:
- `npm run test -- resources/js/components/rostering/rostering-redesign-followups.test.tsx resources/js/components/timesheet-status-badge.test.tsx resources/js/components/staff-status.test.tsx`
- `npm run types`
- `npm run build`
- `php artisan test tests/Feature/Rostering/RosteringOpenShiftEligibilityTest.php`
- `php artisan test tests/Feature/ShiftControllerTest.php --filter=duplicate_shift` (regression)
- `php artisan test tests/Feature/Routing/LegacyShiftNamesRemovedTest.php` (regression)

Live (after deploy):
- Seed a candidate with an expired hard-stop compliance requirement and an open shift for the same site/week.
- Open `https://oblivionfindings.com/operations/rostering` as `admin@demo.test`, navigate to the Open shifts tab.
- Confirm the blocked candidate's chip renders with strikethrough + tooltip showing the compliance reason.
- Confirm an eligible candidate renders unchanged.
- Clean up seed data via SSH + tinker.

## Rollout

- One feature branch `feat/per-candidate-eligibility`.
- One commit on the branch (or two if the doc update is logically separated).
- Merge back to `main` with `--no-ff` to preserve the feature as a single merge commit.
- Push `main`; deploy is auto.

## Rollback

The change is additive on the payload side (new prop, nothing existing is removed) and degrades gracefully on the UI side (missing `openShiftEligibility` falls back to the previous behaviour). Reverting the merge commit is sufficient to roll back.
