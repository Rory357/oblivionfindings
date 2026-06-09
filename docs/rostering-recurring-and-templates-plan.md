# Rostering: fold Recurring Series in + fix/beef-up Templates

**Date:** 2026-06-09
**Branch:** `rostering-recurring-and-templates`
**Origin:** audit of the Rostering **Templates** tab + **New template** wizard + the standalone
**Recurring series** page. Three approved slices.

---

## Slice A — Recurring Series becomes a tab (no separate page)

Mirror the Templates consolidation. `/operations/shifts/series` (Index + Show) are standalone
Inertia pages; fold them into the Rostering workspace.

- **Backend**
  - New `App\Services\Operations\ShiftSeriesPresenter` — `summary(ShiftSeries)` (list-card payload)
    and `detail(ShiftSeries)` (the full Show payload: stats, upcoming/recent occurrences, coverage
    alignment). Reused by the controller.
  - `RosteringController@index`: add `canManageSeries`; lazy `rosterSeries` (org list, eager when
    `?tab=recurring`, else `Inertia::optional` — mirrors `rosterTemplates`); lazy `seriesDetail`
    (eager when `?series={id}`, else optional) for the detail pop-up.
  - `ShiftSeriesController@index/@show` → redirect into `?tab=recurring[&series=id]` (route names +
    middleware unchanged, so old bookmarks/links keep working). `store` + `cancelFuture` kept;
    `cancelFuture` redirect re-pointed to the tab. Presentation logic moves to the presenter.
- **Frontend**
  - `components/rostering/series-pane.tsx` (`SeriesPane`: MicroStats + search/filter + card grid,
    Mon–Sun weekday strip, open/replacement badges) and `series-dialogs.tsx` (`SeriesDetailDialog`:
    pattern summary, stat tiles, upcoming/recent occurrences w/ open·cancel·reopen, coverage drift,
    cancel-future). Barrel exports.
  - Wire a `recurring` tab into `RosterTab`/`ROSTER_TABS`/`tabItems` (icon `Repeat`), lazy-load on
    switch, open the detail dialog from a card (lazy `seriesDetail` fetch) and on `?series=` landing.
  - Remove the footer **Recurring series** button; re-point the "Needs you" CTA to `?tab=recurring`
    (fixes the discoverability gate — the tab uses the same view gate as the route).
  - Delete `pages/operations/shifts/series/{Index,Show}.tsx`.

## Slice B — Templates correctness

1. **Snap apply to Monday.** `apply` now does `startOfWeek(MONDAY)` so a non-Monday `week_start`
   can't silently shift the whole pattern.
2. **Honour cadence.** Apply gains `cycles` (1–12). Each cycle stamps the one-week pattern at an
   anchor advanced by the cadence interval (weekly = 1wk, fortnightly = 2wk, monthly = 4wk). Default
   `cycles = 1` preserves today's behaviour. Idempotency key includes cycles + cadence.
3. **Wizard pickers for template-only managers.** `RosteringController` loads
   `clients/staff/serviceContexts` whenever `canManageTemplates` (not only `shifts.manageAny`), so a
   `roster_templates.create` user no longer opens the wizard to empty dropdowns.

## Slice C — New Template flow

- **Duplicate row** per shift-row (clone all fields, bump to next day) — fast multi-day patterns.
- **Review step** (Details → Shift rows → Review): Mon–Sun strip + per-day summary + total before commit.
- **Apply preview**: "Creates N shifts × C cycle(s) = X draft shifts, weeks of …" in the apply panel.
- **Delete** in the detail dialog header (gated), matching the card menu.
- **Duplicate template** in the card ⋯ menu → `POST templates/{t}/duplicate` (clone + rows).

---

## Verify
`tsc` (touched files), rostering `vitest`, `TemplateApplyTest` + new cycles/Monday cases, then a
built browser smoke on Herd (tab renders, card→detail pop-up, cancel-future, apply preview, wizard
duplicate-row + review).

**Deploy note:** no new permissions (delete already seeded). No migrations.

---

## Status — DONE, verified (uncommitted, branch `rostering-recurring-and-templates`, 2026-06-09)

All three slices implemented. Verified: `tsc` 0 errors · `npm run build` green · 28 rostering vitest ·
6 `TemplateApplyTest` (incl. new cycles + Monday-snap) + 4 `RecurringSeriesTabTest` + 2
`RosteringOpenShiftEligibilityTest` (renders the index) + 118 routing tests green · Pint clean. Browser-
smoked on Herd as Demo Admin — Recurring tab + empty state, wizard 3-step + Review + per-row Duplicate,
apply Cycles live preview (3 → "6 shifts across 3 weeks — 15/22/29 Jun"), detail Delete, kebab Duplicate,
footer "Recurring series" button gone, no console errors.

`RosteringIndexLeaveTest` fails on a stale per-worker test DB (`tenant_id` missing at User insert in
setup) — environmental (see `reference_testing`), not this change; the sibling OpenShiftEligibility test
renders the same index green.

Not yet committed / merged / deployed — awaiting review.
