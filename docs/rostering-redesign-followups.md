# Rostering Redesign Follow-ups

Status as of the Codex implementation pass on 2026-05-27, plus the Claude audit + live verification pass that immediately followed: the rostering redesign has been audited, Codex's follow-up gaps have been implemented, two real bugs from that pass were caught and fixed, and the result has been exercised end-to-end on `oblivionfindings.com`. The manager rostering page now maps the controller payload more completely, and the roster → worker shift → attendance → draft timesheet loop remains backed by the existing services rather than a rewrite.

### Post-Codex audit fixes (Claude, 2026-05-27)

After Codex's pass landed on `main`, an audit turned up three issues that were patched in commits `ba7a9884` and `c6435e62` before being verified on live:

1. **HR leave tenant leak** — `RosteringController::index()` queried `HrLeaveRequest::query()` globally for both `approvedLeave` and `pendingLeave`. Other HR controllers (`LeaveController`) consistently use `->forTenant($tenantId)`. Without it the rostering page would surface other tenants' leave on a multi-tenant deploy. Added `->forTenant($auth->tenant_id)` to both queries.
2. **`RosteringIndexLeaveTest` could not pass as written** — the test role granted `rostering.viewAny` + `shifts.manageAny`, but `pendingLeave` is gated on `hr.leave.approve` or `hr.leave.manage`. The User factory also does not default `tenant_id`, so the manager mismatched the leave fixtures' `tenant_id` once the `forTenant` scope was applied. Added `hr.leave.approve` to the role and set `tenant_id = 1` on the manager.
3. **Pluralisation grammar** — the live hero description rendered "1 shifts across 6 sites, and 1 timesheets waiting on you.", the signal rail said "1 timesheets pending", and the new Staff `EntityFilter` placeholder produced "Search 27 staffs…" because the component concatenated `${label}s`. Added an optional `pluralLabel` prop to `EntityFilter`, wired `"staff"` for the Staff filter, and pluralised the hero description + the timesheet signal with the standard `=== 1 ? '' : 's'` pattern.

### Live verification (Claude, 2026-05-27)

Verified end-to-end on `https://oblivionfindings.com/operations/rostering` signed in as `admin@demo.test`:

- Hero, donut overview cards, 6-tab strip, and signal rail all render with real data from the deployed payload (`canApproveLeave: true`, `analytics.dailyCoverage` populated, `stats.timesheets_pending` correct).
- Staff (27), Client (35), and Site (6) filters all open as searchable dropdowns and round-trip through `filterPayload()`.
- Week picker popover opens with banner + calendar + this week / done footer.
- Right-click context menu on a completed Codex Roster Loop shift shows the expected three-item menu (Open shift detail, View timesheet, Report incident) and each item maps to its real route.
- All grammar fixes confirmed live after the deploy hash rolled from `Dt1eywfD` → `C1OHydm2`.

---

## Current verdict

The redesign is not just a visual shell. The end-to-end workflow exists in code:

1. Managers create, assign, publish, cancel, or unassign shifts through the canonical `/operations/shifts/*` and `/operations/rostering/*` routes.
2. Published/assigned shifts surface to workers on `/my-day` through `MyTasksController`.
3. `/my-day` clock-in posts to `/attendance/clock-in`, creates an `HrAttendanceSession`, and starts the linked shift through `ShiftLifecycleService`.
4. `/my-day` clock-out posts to `/attendance/clock-out`, completes the linked shift, and calls `DraftTimesheetService::fromAttendanceSession()`.
5. Workers submit timesheets through `/my-tasks/timesheet/{timesheet}/submit` or `/operations/timesheets/{timesheet}/submit`.
6. Managers approve, return, or reject timesheets through `/operations/timesheets/*`.
7. The rostering page reads pending payroll work back through `stats.timesheets_pending` and links managers to `/operations/timesheets`.

The follow-up implementation focuses on mapping returned roster data into the redesigned UI. No database migrations, route renames, or shift/timesheet service rewrites were needed.

---

## Implemented in this pass

### Backend payload

- `app/Http/Controllers/RosteringController.php`
    - Added `pendingLeave` for users with HR leave approval/manage access, scoped to the visible two-week leave window.
    - Added `canApproveLeave` so HR leave Approve/Decline buttons follow the actual HR route permission gate instead of only shift-management access.
    - Normalised approved and pending HR leave payloads with `id`, `user_id`, `user`, `leave_type`, `reason`, `status`, `starts_at`, and `ends_at`.
    - Added `timesheet_id` to each shift payload so completed shifts can link directly to their timesheet.
    - Kept existing shift lifecycle, attendance, and draft timesheet services unchanged.

### Rostering page mapping

- `resources/js/pages/operations/rostering/index.tsx`
    - Added Staff and Client filters beside the existing Site filter.
    - Consumes `clients`, `pendingLeave`, `replacementQueue`, `recurringPatterns`, `complianceBadges`, `analytics.dailyCoverage`, and `stats.cancelled`.
    - Maps pending HR leave into the Time off pane and posts Approve/Decline to `/hr/leave/{id}/approve` or `/hr/leave/{id}/decline` with `preserveScroll` when the user has HR leave approval/manage permission.
    - Adds recurring-series signal rows to the right rail, linking to `/operations/shifts/series`.
    - Adds cancelled shifts to the Shifts donut.
    - Passes detailed `eligibilityAlerts.blocked` and `eligibilityAlerts.warnings` into the Open shifts pane watchlist.
    - Normalises compliance badge payloads whether they arrive as a keyed record or controller list.
    - Passes `timesheet_id` to grid shift rows for direct timesheet navigation.
    - Opens an inline Resolve overlap dialog from the Week grid context menu, with the conflict queue retained as the fallback review surface.

### Component library

- `resources/js/components/rostering/entity-filter.tsx`
    - New reusable single-select filter built on Popover + Command.
- `resources/js/components/rostering/open-shifts-pane.tsx`
    - Renders active replacement requests separately from unassigned open shifts.
    - Adds requester/current staff/reason context and a Find cover action.
    - Renders detailed eligibility blocker/warning reasons as a shift-level watchlist.
- `resources/js/components/rostering/week-grid-pane.tsx`
    - Renders compliance chips beside staff names.
    - Adds a clear empty roster state.
    - Routes edit actions to `/operations/shifts/{id}/edit`.
    - Routes completed-shift timesheet actions to `/operations/timesheets/{timesheet_id}/edit` when available.
    - Carries overlap peer details into the inline conflict dialog.
    - Removes or hides menu labels that implied unsupported one-click actions.
- `resources/js/components/rostering/resolve-conflict-dialog.tsx`
    - New inline dialog for overlapping shifts with Reassign, Unassign, Open shift, and Open conflict queue actions.
- `resources/js/components/rostering/capacity-heatmap-pane.tsx`
    - Renders the same compliance chips in the capacity view.
- `resources/js/components/rostering/analytics-pane.tsx`
    - Renders `analytics.dailyCoverage` as a daily filled/open coverage panel.
- `resources/js/components/rostering/time-off-pane.tsx`
    - Carries source metadata so HR leave rows can use the real leave request ID.

### Test coverage added

- `resources/js/components/rostering/rostering-redesign-followups.test.tsx`
    - Covers replacement request rendering and Find cover callback.
    - Covers Week grid compliance badges and empty state.
    - Covers Capacity heatmap compliance badges.
    - Covers Analytics daily coverage rendering.
    - Covers inline conflict dialog actions.
    - Covers detailed eligibility blocker/warning rendering.
- `tests/Feature/Rostering/RosteringIndexLeaveTest.php`
    - Documents the backend expectation that manager roster loads include pending HR leave.
    - See the local caveat below before treating a nested-worktree Pest failure as a product failure.

---

## Prop mapping after implementation

| Prop / contract                        | Status      | Notes                                                                                                                                                         |
| -------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `canApproveLeave`                      | OK          | Gates HR leave Approve/Decline buttons against the real `/hr/leave/*` route permissions.                                                                      |
| `filters.site_id` / `filters.site_ids` | OK          | Existing multi-site filter remains wired through `SiteFilter` and `filterPayload()`.                                                                          |
| `filters.staff_id`                     | OK          | New Staff filter renders in the hero footer and round-trips through `filterPayload()`.                                                                        |
| `filters.client_id`                    | OK          | New Client filter consumes the `clients` prop and round-trips through `filterPayload()`.                                                                      |
| `clients`                              | OK          | Used for the Client filter.                                                                                                                                   |
| `stats.cancelled`                      | OK          | Added to the Shifts donut with muted cancelled styling.                                                                                                       |
| `shifts[].timesheet_id`                | OK          | Returned by the controller and used for direct completed-shift timesheet links.                                                                               |
| `replacementQueue[]`                   | OK          | Rendered in the Open shifts pane as active replacement requests.                                                                                              |
| `recurringPatterns[]`                  | OK          | Surfaced in `SignalRail` as recurring-series operational signals.                                                                                             |
| `approvedLeave[]`                      | OK          | Still overlays approved HR leave in Time off.                                                                                                                 |
| `pendingLeave[]`                       | OK          | Returned by the controller and rendered as pending Time off requests for users who can approve/manage HR leave.                                               |
| `complianceBadges`                     | OK          | Rendered in Week grid and Capacity heatmap staff rows.                                                                                                        |
| `analytics.dailyCoverage`              | OK          | Rendered in Analytics as filled/open coverage per day.                                                                                                        |
| `coverageAlerts[]`                     | Intentional | The UI still renders alerts from `coverageSites[].alerts`; the top-level array is duplicate summary data and is not required for the redesigned panes.        |
| `eligibilityAlerts.blocked/warnings`   | OK          | Counts and detailed shift-level blocker/warning rows are surfaced in the Open shifts pane. True per-candidate reasons would require a richer backend payload. |

---

## Route and menu audit

The context menu now only exposes actions that map to current routes or existing safe navigation targets.

| Menu action                                  | Current behaviour                                                                                                           |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| View shift                                   | Visits `/operations/shifts/{id}`.                                                                                           |
| Edit shift / Edit draft                      | Visits `/operations/shifts/{id}/edit`.                                                                                      |
| Assign / Reassign                            | Opens the shift detail surface where the existing assignment workflow lives.                                                |
| Unassign                                     | Posts `/operations/shifts/{id}/unassign`.                                                                                   |
| Cancel                                       | Patches `/operations/shifts/{id}/cancel`.                                                                                   |
| Request replacement / Cover with replacement | Visits shift detail for the existing replacement workflow.                                                                  |
| Report incident                              | Visits `/incidents/create?shift_id={id}`.                                                                                   |
| Resolve overlap                              | Opens the inline Resolve overlap dialog with reassign/unassign actions and a link to the conflict queue.                    |
| View timesheet                               | Visits `/operations/timesheets/{timesheet_id}/edit` when `timesheet_id` is available; otherwise falls back to shift detail. |

These labels were deliberately not kept as inert menu items: Duplicate, Copy to another day, Make recurring, Broadcast to staff, Auto-fill best match, Publish draft, Reopen for correction, and Mark as ended early. They need real backend routes or a separate product decision before being reintroduced.

---

## End-to-end loop audit

| Loop step                            | Live code path                                                                                             | Audit result                                                                                                                         |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Manager opens roster                 | `GET /operations/rostering` -> `RosteringController::index` -> `operations/rostering/index`                | Working. Scheduler-scoped route with `role_scope:my-day` and `rostering.viewAny`.                                                    |
| Manager filters roster               | `RosteringIndexRequest::siteFilter()` plus staff/client request filters                                    | Working. Site, staff, and client controls are now available from the redesigned page.                                                |
| Manager reviews leave pressure       | `approvedLeave`, `pendingLeave`, and `timeOffs` feed the Time off pane                                     | Working. Pending HR leave rows can now approve/decline through HR leave endpoints when the user has HR leave approval/manage access. |
| Manager handles replacement requests | `replacementQueue[]` feeds the Open shifts pane                                                            | Working. Replacement requests are separate from unassigned shifts.                                                                   |
| Manager resolves overlaps            | Week grid context menu opens `ResolveConflictDialog`; Unassign posts to `/operations/shifts/{id}/unassign` | Working. The full conflict queue remains available for deeper review.                                                                |
| Manager sees compliance risk         | `complianceBadges` feeds Week grid and Capacity heatmap                                                    | Working. Expired and expiring compliance states are visible beside staff names.                                                      |
| Manager assigns open shift           | UI posts `/operations/shifts/{shift}/assign` from Open shifts suggestions                                  | Working for existing suggestion chips.                                                                                               |
| Manager unassigns/cancels            | UI posts unassign and PATCHes cancel                                                                       | Working through existing routes.                                                                                                     |
| Manager publishes roster             | UI posts review/publish/republish/unpublish through `/operations/rostering/periods/{period}/*`             | Working route contract.                                                                                                              |
| Worker sees shift                    | `/my-day` resolves visible shifts through `MyTasksController`                                              | Working. `/my-day` remains the canonical worker surface.                                                                             |
| Worker clocks in                     | `/attendance/clock-in` -> `AttendanceService::clockIn()`                                                   | Working. Starts linked draft/scheduled shift.                                                                                        |
| Worker clocks out                    | `/attendance/clock-out` -> `AttendanceService::clockOut()`                                                 | Working. Completes shift and creates/updates draft timesheet.                                                                        |
| Worker submits timesheet             | `/my-tasks/timesheet/{timesheet}/submit` or `/operations/timesheets/{timesheet}/submit`                    | Working. My Day path keeps allocation validation.                                                                                    |
| Manager approves payroll             | `/operations/timesheets/approvals` and `/operations/timesheets/{timesheet}/approve\|return\|reject`        | Working. Rostering links pending timesheets to `/operations/timesheets`.                                                             |

---

## Remaining known work

### Needs backend work first (no route contract today)

The week-grid context menu deliberately omits these labels until each has a controller endpoint + permission check + a focused feature test. The product decision for each is open.

| Action                                 | Where it would slot in                          | What's missing                                                                                                                                                                                                                                          |
| -------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Duplicate shift** / **Copy to day**  | `buildShiftActions()` scheduled / draft branch  | New `POST /operations/shifts/{shift}/duplicate` (and/or `?date=YYYY-MM-DD`) on `ShiftController`, with a permission like `shifts.duplicate` or reuse `shifts.create`. Must respect roster-period boundaries.                                            |
| **Make recurring** (one-off → series)  | `buildShiftActions()` scheduled branch          | New `POST /operations/shifts/{shift}/promote-to-series` that hands off to `ShiftSeriesService`. Must define the recurrence input UI before wiring.                                                                                                      |
| **Broadcast to staff**                 | `buildShiftActions()` open branch + Open pane   | A notification service that pushes "shift needs cover" alerts to an eligible pool. No comparable broadcast endpoint exists yet.                                                                                                                         |
| **Auto-fill best match** (single)      | `buildShiftActions()` open branch               | Per-shift assign-best variant of `RosterSuggestionService` (`POST /operations/shifts/{shift}/auto-fill`). Today's `Auto-schedule` button is week-level only.                                                                                            |
| **Publish draft** (per shift)          | `buildShiftActions()` draft branch              | Currently publishing is per roster period via `/operations/rostering/periods/{period}/publish`. A per-shift draft → published transition would need a new `Shift.status` state machine or a wrapper that bumps just the relevant period.               |
| **Reopen for correction** (completed)  | `buildShiftActions()` completed branch          | The route `PATCH /operations/shifts/{shift}/reopen` already exists. Only the menu item + a "this is audit-tracked, click again to confirm" guard are missing. ~10 minutes once the confirm UX is agreed.                                                |
| **Mark as ended early** (in-progress)  | `buildShiftActions()` in_progress branch        | Closest existing route is `PATCH /operations/shifts/{shift}/complete`. Would need an `ended_early_reason` field on the request and a service-side audit trail entry.                                                                                    |

### Needs a richer backend payload

| Item                                          | What's needed                                                                                                                                                                                                                                                  |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Per-candidate eligibility reasons**         | `ShiftStaffEligibilityService::evaluate()` currently returns shift-level blocker/warning lists. The Open Shifts pane shows these as a watchlist. To get the per-suggestion-chip detail the design called for, the service needs to return reasons keyed by `(shift_id, candidate_user_id)`. |
| **Candidate availability sub-line**           | Suggestion chips today show just `name` + "best match" tag. The chip could carry "44h this week" or "Up to 30 Apr" if the payload included a candidate availability summary alongside each suggestion. The data already exists on `props.capacity` for hours and could be cross-referenced. |

### Open product decisions (no work until decided)

| Question                                                                    | Why it's open                                                                                                                                                                                                                                                                                                                                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pending HR leave: approve/decline inline vs. deep-link to `/hr/leave`?       | Today the Time off pane shows pending HR leave but the approve/decline buttons currently `router.visit('/hr/leave')` rather than posting directly. Routes `POST /hr/leave/{leaveRequest}/approve|decline` exist; the only question is whether managers should be able to approve from the rostering page or be forced to switch to the HR leave surface (where SLA, escalation, and audit trail context lives). |
| Empty Time off pane subtitle                                                | When there are no pending requests the subtitle still reads "Awaiting your decision · oldest first" which is misleading. Cosmetic — swap to "All caught up · no pending requests" when empty.                                                                                                                                                                                                                       |
| `coverageAlerts` top-level prop                                             | Returned by the controller as a flat denormalised list, but the UI consumes the same data from `coverageSites[].alerts`. Either drop the top-level field or surface it as a flat "all coverage gaps this week" panel.                                                                                                                                                                                                |

### Tooling caveats (not product issues)

- **Nested-worktree Pest collisions** — `RosteringIndexLeaveTest.php` lives in both the parent checkout and the nested Claude worktree (the worktree carries an identical copy), so Pest's test-suite walker finds the same test case from two paths and bails. Run Pest from a non-nested checkout. Vitest is unaffected.
- **Composer autoload from nested worktree** — when Vite/Herd serves the nested worktree's JS, `App\Http\Controllers\RosteringController` still resolves from the parent checkout. PHP-level behaviour observed locally will reflect the parent, not the worktree. Verify PHP changes from a non-nested checkout (which is exactly what live verification at `oblivionfindings.com` does).

### Out-of-scope / wontfix (deliberate)

- "Tweaks" panel from the original design bundle — prototype tooling only.
- The full 5,718-line Ops dashboard layout that the redesign replaced — the new shell intentionally collapses that surface area. Tests still pass against the preserved `data-test` attributes (`rostering-publish-panel`, `rostering-review-publish`, `rostering-confirm-publish`, `rostering-suggest-assignments`, etc.).

### Recommended next step

1. Wire `PATCH /operations/shifts/{shift}/reopen` back into the completed-shift context menu with a confirm prompt — route already exists. ~10 min.
2. Decide on Duplicate / Make recurring as a roadmap item; both are real product features, not just UI polish, and warrant their own controller + service design.
3. Extend `ShiftStaffEligibilityService::evaluate()` to return per-candidate detail — highest user-facing value of the remaining items because it turns the suggestion chips into a real triage surface.

---

## Local verification completed

Commands run from `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\gallant-lovelace-4fb6dd`:

```powershell
npm run test -- resources/js/components/rostering/rostering-redesign-followups.test.tsx
npm run test -- resources/js/components/rostering/rostering-redesign-followups.test.tsx resources/js/components/timesheet-status-badge.test.tsx resources/js/components/staff-status.test.tsx
npm run types
npm run build
C:\Users\steph\.config\herd\bin\php.bat -l app\Http\Controllers\RosteringController.php
C:\Users\steph\.config\herd\bin\php.bat -l tests\Feature\Rostering\RosteringIndexLeaveTest.php
git diff --check
```

Results:

- Focused rostering component tests passed.
- Focused component regression set passed: 3 files, 26 tests.
- TypeScript check passed.
- Vite production build passed.
- PHP syntax checks passed for the changed controller and new feature test.
- `git diff --check` passed.

### Local caveats

- The new Pest feature test documents the backend `pendingLeave` expectation, but in this nested worktree Composer autoload resolves `App\Http\Controllers\RosteringController` from `C:\Users\steph\Herd\oblivionfindings\app\Http\Controllers\RosteringController.php` instead of the nested worktree. Run it from a non-nested worktree or with local dependencies before treating a failure as a product regression.
- Live verification on `oblivionfindings.com` was completed during the Claude audit pass (see the "Live verification" section near the top). The grammar fixes, HR leave tenant scope, and Codex's full follow-up wiring are confirmed shipped at hash `C1OHydm2`.

---

## Files changed by this implementation

```text
Modified:
  app/Http/Controllers/RosteringController.php
  docs/rostering-redesign-followups.md
  resources/js/components/rostering/analytics-pane.tsx
  resources/js/components/rostering/capacity-heatmap-pane.tsx
  resources/js/components/rostering/index.ts
  resources/js/components/rostering/open-shifts-pane.tsx
  resources/js/components/rostering/resolve-conflict-dialog.tsx
  resources/js/components/rostering/time-off-pane.tsx
  resources/js/components/rostering/week-grid-pane.tsx
  resources/js/pages/operations/rostering/index.tsx

New:
  resources/js/components/rostering/entity-filter.tsx
  resources/js/components/rostering/rostering-redesign-followups.test.tsx
  tests/Feature/Rostering/RosteringIndexLeaveTest.php
```

### Post-Codex audit commits (Claude, 2026-05-27)

```text
c6435e62 fix(rostering): pluralisation polish across filter + signals + hero
ba7a9884 fix(rostering): scope HR leave queries by tenant; fix pendingLeave test
27d87542 feat(rostering): complete redesign follow-up wiring   ← Codex
6c3698cf feat(rostering): redesign index page with hero, donut cards, tabs, signal rail
```

Modified by the audit pass:

```text
  app/Http/Controllers/RosteringController.php           ← +forTenant() scope on leave queries
  resources/js/components/rostering/entity-filter.tsx    ← +pluralLabel prop
  resources/js/pages/operations/rostering/index.tsx      ← pluralisation in hero + signals; pluralLabel wired
  tests/Feature/Rostering/RosteringIndexLeaveTest.php    ← +hr.leave.approve permission; +tenant_id on manager
```
