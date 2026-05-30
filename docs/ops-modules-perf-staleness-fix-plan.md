# Ops Modules — Performance & Staleness Fix Plan

**Branch:** `fix/ops-perf-staleness`
**Scope:** Rostering, Shifts, Timesheets, Availability, Job Board
**Source:** Verified audit (36-agent workflow, every finding adversarially re-checked). Only findings that survived an independent skeptic re-reading the cited code are included. Three originally-flagged items were **debunked** and are explicitly out of scope (see below).

> Caveat: the audit was static analysis (route → controller → service → query → page tracing). Query counts are derived from reading the loops, not profiled. Phase 4 (test on live) is where we confirm real behaviour.

---

## Root cause (drives most of the slowness)

`ShiftStaffEligibilityService::evaluate()` runs 11 rules per `(shift, staff)` pair and is called in nested loops from **three** surfaces (Rostering dashboard, Shift detail `show()`, Job Board). Several rules **re-query the DB per pair even though a batch loader already eager-loaded the data**, and the heaviest one (`checkOverfill`) calls `ShiftCoverageService::buildRangeCoverage()` — ~5 queries + nested slice math — which depends only on the *shift*, so it is recomputed identically for every staff member. `ShiftCoverageService` has **zero memoization**.

**Therefore Phase 1 (shared core) is done first and alone.** Making coverage memoized per-request and making the rules read eager-loaded relations fixes the High-severity slowness in Rostering, Shift-detail, and Job Board **without touching those controllers** (backward-compatible internal changes).

---

## Debunked — explicitly NOT changing

| Item | Why it stays |
|---|---|
| `/my-roster` (`RosterController`) | Legitimate, distinct **frontline worker** surface (own shifts), live + tested. Not legacy/duplicated. Leave alone. |
| Duplicate `shift-context-menu.tsx` | Shifts page imports the local copy; not a live problem. |
| "Eager `buildAvailabilitySummary` defeats Inertia::optional" | The eager path is **required** — the frontend has no mount-time fetch; deferring would render an empty pane. |
| "Coverage service result discarded on availability tab" | `coverageSites` feeds an always-visible header donut; not discarded. |

---

## Phase 1 — Shared performance core (1 agent, foundational, sequential)

Files: `app/Services/ShiftCoverageService.php`, `app/Services/ShiftStaffEligibilityService.php`, `app/Services/Eligibility/Rules/FatigueRule.php`, `app/Domain/Hr/Services/ComplianceMatrixService.php`, `app/Services/ShiftConflictService.php`

| Fix | Detail |
|---|---|
| **Request-scoped coverage memoization** | Memoize `coverageStatusForShift` / `coverageForShift` / `buildRangeCoverage` keyed by `(siteId, rangeStart, rangeEnd, sliceMinutes)`. Verify the service is not bound as a singleton (default = per-request → instance-array cache is safe). Auto-fixes Rostering "built twice", Shift-detail "N+2", Job Board "per-card overfill", and the per-pair coverage rebuild. |
| **Eligibility rules read eager-loaded data** | `evaluateMany` must preload every relation the rules consume; rules must consume them: `checkTimeOff` uses `$user->staffTimeOff` when loaded; `ComplianceMatrixService` reads preloaded `hrComplianceStatuses(.requirement)`; conflict/turnaround use a per-batch preloaded shift set or per-user memo. |
| **FatigueRule batching** | Load each candidate's shifts for the relevant window once (or memoize per user within the request) and compute daily/weekly/rest/consecutive in PHP instead of ~15–20 per-pair queries. Best-effort with a safe fallback; **eligibility outcomes must not change.** |

**Hard constraint:** backward-compatible signatures, identical eligibility results, `php -l` clean.

---

## Phase 2 — Module fixes (4 agents in parallel, disjoint files)

### Rostering + Availability
Files: `app/Http/Controllers/RosteringController.php`, `routes/staff.php`, `resources/js/pages/staff/show.tsx` (+ `StaffAvailabilityController` if redirecting)

| Sev | Fix |
|---|---|
| Med | `buildEligibilityAlerts` (~1137): evaluate each future shift only against **its own assignee** (the diagonal actually read), not the cartesian product. |
| Med | Tab-aware short-circuit: when `tab === 'availability'`, skip manager blocks the availability pane doesn't consume (eligibility alerts/open-shift, historical trend). **Keep** `coverageSites` (header donut) + `staffAvailabilitySummary`. Verify prop usage in `index.tsx` first. |
| Med | **Staleness:** consolidate the duplicate per-staff availability page. Investigate whether the rostering availability tab supports the same per-staff editing. If yes → redirect `/staff/{user}/availability` into the tab and drop the standalone link/page. If no → keep the standalone page as canonical but **reconcile the `start_time/end_time` vs `starts_at/ends_at` field mismatch**. Report the decision; do not delete functionality. |
| Low | Collapse 4-week trend into one `GROUP BY` query + org-scope it. |
| Low | Org-scope `staff`/`clients`/`sites` lists (keep dropdowns working). |

### Shifts (detail page is the slow one — index is fine)
Files: `app/Services/ShiftAssignmentRecommendationService.php`, `app/Http/Controllers/ShiftController.php`, new migration `2026_05_30_000001_add_starts_at_index_to_shifts.php`

| Sev | Fix |
|---|---|
| High | `forShift` (~42): shortlist candidates **before** scoring (use existing `candidatesFor()` / scoped+limited query); batch the 3 per-staff aggregates (weekly minutes, site familiarity, client consistency) into grouped queries; route eligibility through `evaluateMany()` (relation-aware after Phase 1). Preserve top-N output + scoring. |
| Med | `show()`: reuse coverage (auto-memoized by Phase 1; drop any obviously redundant recompute). |
| Low | New migration: index `shifts(starts_at)` (or `(starts_at, status)`) for the bare week-range + `orderBy`. |

### Timesheets
Files: `app/Models/Timesheet.php`, `app/Http/Controllers/TimesheetController.php`

| Sev | Fix |
|---|---|
| High | `effectiveClientAllocations` (258): memoize on the model; `dominantAllocationMethod` reuses it; when eager-loaded `clientAllocations` is empty, fall through to the synthesized row **without** re-querying. Net 0 extra queries/row. |
| Med | **Staleness:** add `shift.tasks:id,shift_id,is_completed` to the index `with()` (228) and fix the count to use **`is_completed`** (confirmed column) not `completed`. Restores real task progress. |
| Med | Collapse the ~18 tab/hero COUNTs into one scoped `selectRaw('status, count(*)')->groupBy('status')`; reuse for both; drop duplicates. |
| Low | Org-scope `clients`/`sites`; trim hero collection work where safe. |

### Job Board
Files: `app/Http/Controllers/Operations/JobBoardController.php`, new migration `2026_05_30_000002_add_org_status_expires_index_to_shift_open_positions.php`

| Sev | Fix |
|---|---|
| Med | `countPastShiftsHere` (756): one grouped query over the page's `client_id`s, mapped back per card. |
| Med | Hoist viewer capabilities (`canApprove`/`canManageAny`/`canViewAny`) out of the per-card loop — compute once, pass booleans into `formatPositionForViewer`. |
| Low | `availableSkills` (896): short-TTL per-org cache (invalidate on position write) or avoid full JSON decode each load. |
| Low | New migration: composite index `shift_open_positions(organization_id, status, expires_at)`. |

(Per-card eligibility — the dominant cost — is already fixed by Phase 1.)

---

## Phase 3 — Review (1 adversarial reviewer per group)

Each reviewer `git diff main -- <group files>`, confirms every targeted finding is actually fixed in the diff, and hunts for introduced defects: logic/behaviour changes, broken signatures, memo correctness/staleness, wrong columns, Inertia props the frontend reads that were dropped, eligibility-result drift. Returns verdict + defect list. `php -l` on changed PHP.

## Phase 4 — Test on live (main thread, after review + defect fixes)

1. `php artisan test` for affected suites (Roster, Shift, Timesheet, Job Board, eligibility/coverage).
2. `npm run types` / build for the TS change.
3. Load the 5 pages on local Herd (`oblivionfindings.test`) and confirm no errors + improved load.

**Remote deploy (`oblivionfindings.com`) is NOT in scope without explicit sign-off.**

---

## Risk notes
- Coverage memoization correctness hinges on the service being per-request (verify binding).
- Eligibility outcomes must be byte-identical — the review pass specifically checks for drift.
- Availability page consolidation must not remove per-staff editing — reconcile, don't blindly delete.
- All work stays on `fix/ops-perf-staleness`; no commit/push without request.
