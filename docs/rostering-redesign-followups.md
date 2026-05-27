# Rostering Redesign Follow-ups

Status as of the Codex implementation pass on 2026-05-27, plus the Claude audit + live verification pass that immediately followed, plus the follow-up actions pass on 2026-05-27 (`1c6aefda`) that shipped Duplicate, Reopen cancelled shift, the top-level Coverage gaps panel, candidate hours sublines, the Time-off empty-state copy, and broadcast-wording removal — followed by a Claude follow-up audit that turned up and fixed three small wiring issues against the new menu items. The manager rostering page now maps the controller payload more completely, and the roster → worker shift → attendance → draft timesheet loop remains backed by the existing services rather than a rewrite.

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

### Follow-up actions pass (2026-05-27, `1c6aefda`)

Six of the items previously listed under "Needs backend work first" / "Open product decisions" shipped together as `1c6aefda feat(rostering): complete follow-up actions`:

1. **Duplicate shift as draft** — new `POST /operations/shifts/{shift}/duplicate` route on `ShiftController` with `shifts.create` permission and a roster-period boundary guard. Creates an unassigned `draft` copy that carries over `client_id`, `site_id`, `service_context_id`, `shift_type`, sleepover/on-call flags, expected break, location, notes, coverage roles, and shift tasks (reset to incomplete). Wired into the week-grid context menu on `scheduled`, `draft`, and `in_progress` rows as "Duplicate as draft" with a confirm prompt and `preserveScroll`. Two new feature tests cover the happy path and the cross-period rejection.
2. **Reopen cancelled shift** — wired the existing `PATCH /operations/shifts/{shift}/reopen` (handled by `ShiftLifecycleService::reopen`) into the week-grid menu on `cancelled` rows as a primary "Reopen cancelled shift" item with a confirm prompt. The handler stays cancelled-only — completed-shift reopen still has no backend support.
3. **Coverage gaps top-level panel** — the `coverageAlerts` flat list returned by the controller now renders as a "Coverage gaps this week" callout above the Coverage tab grid, with per-alert site/rule/window/shortage/planned context. The duplicate top-level prop has been promoted from a product question to a real surface.
4. **Candidate hours subline on suggestion chips** — `suggestStaffForOpenShift` now flows `hours` through to each chip, and the Open Shifts pane renders "32h this week" under the candidate name. The weekly cap line ("Up to 30 Apr") is still not wired.
5. **Time-off empty state copy** — the Time off pane subtitle now reads "All caught up · no pending requests" when there are zero pending requests.
6. **Broadcast wording removal** — the unsupported per-shift "Broadcast" button was removed from the Open Shifts pane, and the corresponding signal-rail open-shifts copy now reads "Need cover this week — assign from eligible staff." No broadcast endpoint exists for rostering shifts (the `/control-room/broadcast` module is unrelated).

Vitest coverage was extended in the same commit: candidate hours subline, no-broadcast assertion, the cancelled-shift reopen menu (and confirms cancel doesn't surface twice), the duplicate-as-draft menu for scheduled shifts, the top-level coverage-gaps panel, and the new Time-off caught-up copy. Total: 32 tests across the three focused files.

### Claude follow-up audit fixes (2026-05-27)

A read-only audit of `1c6aefda` against the doc's "out-of-scope" list turned up three small issues that were patched immediately on `main`:

1. **`onReopenShift` menu item shown to non-managers** — `onDuplicateShift` was gated on `props.canManageAny` but `onReopenShift` was not, so non-managers saw the menu item and got a 403 only on click. Gated both prop bindings identically in `resources/js/pages/operations/rostering/index.tsx`.
2. **`reopenOccurrence` missed site-scope assertion** — every other write handler on `ShiftController` calls `$this->assertCanAccessShift($auth, $shift)` after the permission check, but `reopenOccurrence` only checked the global `shifts.manageAny` permission. With the new menu surface, a manager with `manageAny` in one site could reopen cancelled shifts in another site. Added the assertion.
3. **Unused `return_to` in `reopenShift` JS call** — the JS posted `{ return_to: '/operations/rostering' }` to the controller but the controller returns `back()` and never reads it. Dropped the param.

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
| Duplicate as draft                           | Posts `/operations/shifts/{id}/duplicate` with a confirm prompt. Available on scheduled, draft, and in-progress rows for users with `shifts.create`. |
| Reopen cancelled shift                       | Patches `/operations/shifts/{id}/reopen` with a confirm prompt. Only surfaced on cancelled rows for users with `shifts.manageAny` and site access.   |

These labels are still deliberately not kept as inert menu items: Copy to another day, Make recurring, Broadcast to staff, Auto-fill best match, Publish draft, Reopen for correction (completed shifts), and Mark as ended early. They need real backend routes or a separate product decision before being reintroduced.

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
| **Copy to another day** (date picker)  | `buildShiftActions()` scheduled / draft branch  | Duplicate-as-draft is shipped. A separate "Copy to day…" variant that takes a target date would reuse the same controller but needs a date-picker affordance. Treat as polish on top of Duplicate.                                                       |
| **Make recurring** (one-off → series)  | `buildShiftActions()` scheduled branch          | New `POST /operations/shifts/{shift}/promote-to-series` that hands off to `ShiftSeriesService`. Must define the recurrence input UI before wiring.                                                                                                      |
| **Broadcast to staff**                 | `buildShiftActions()` open branch + Open pane   | A notification service that pushes "shift needs cover" alerts to an eligible pool. No comparable broadcast endpoint exists for rostering shifts.                                                                                                        |
| **Auto-fill best match** (single)      | `buildShiftActions()` open branch               | Per-shift assign-best variant of `RosterSuggestionService` (`POST /operations/shifts/{shift}/auto-fill`). Today's `Auto-schedule` button is week-level only.                                                                                            |
| **Publish draft** (per shift)          | `buildShiftActions()` draft branch              | Currently publishing is per roster period via `/operations/rostering/periods/{period}/publish`. A per-shift draft → published transition would need a new `Shift.status` state machine or a wrapper that bumps just the relevant period.               |
| **Reopen for correction** (completed)  | `buildShiftActions()` completed branch          | `PATCH /operations/shifts/{shift}/reopen` exists but is cancelled-only (`if ($shift->status !== 'cancelled') return back()`). Reopening completed shifts would need a new state-machine path on `ShiftLifecycleService` plus an audit trail entry.       |
| **Mark as ended early** (in-progress)  | `buildShiftActions()` in_progress branch        | Closest existing route is `PATCH /operations/shifts/{shift}/complete`. Would need an `ended_early_reason` field on the request and a service-side audit trail entry.                                                                                    |

### Needs a richer backend payload

| Item                                          | What's needed                                                                                                                                                                                                                                                  |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Candidate availability cap line**           | Partial. The "Xh this week" subline now renders on each suggestion chip from `props.capacity` (shipped in `1c6aefda`). The optional "Up to 30 Apr" availability cap is still not wired — needs an `available_until` field on the candidate payload. |

Resolved: **per-candidate eligibility reasons** shipped via the additive `openShiftEligibility` payload — `RosteringController::index` now evaluates `ShiftStaffEligibilityService::evaluate()` for the cheap-prefilter shortlist of candidates per open shift and emits `openShiftEligibility[shift_id][user_id] = { status, reasons }` for warning / blocked candidates only. The Open Shifts pane renders warning chips with an amber Warn pill + tooltip and renders blocked chips struck-through, non-clickable, with a Blocked pill + tooltip. Eligible candidates are omitted from the payload and render unchanged.

### Open product decisions (no work until decided)

| Question                                                                    | Why it's open                                                                                                                                                                                                                                                                                                                                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pending HR leave: approve/decline inline vs. deep-link to `/hr/leave`?       | Today the Time off pane shows pending HR leave but the approve/decline buttons currently `router.visit('/hr/leave')` rather than posting directly. Routes `POST /hr/leave/{leaveRequest}/approve|decline` exist; the only question is whether managers should be able to approve from the rostering page or be forced to switch to the HR leave surface (where SLA, escalation, and audit trail context lives). |

Resolved in `1c6aefda`: the empty Time off pane subtitle now reads "All caught up · no pending requests", and the top-level `coverageAlerts` array now drives a real "Coverage gaps this week" panel above the Coverage tab grid.

### Tooling caveats (not product issues)

- **Nested-worktree Pest collisions** — `RosteringIndexLeaveTest.php` lives in both the parent checkout and the nested Claude worktree (the worktree carries an identical copy), so Pest's test-suite walker finds the same test case from two paths and bails. Run Pest from a non-nested checkout. Vitest is unaffected.
- **Composer autoload from nested worktree** — when Vite/Herd serves the nested worktree's JS, `App\Http\Controllers\RosteringController` still resolves from the parent checkout. PHP-level behaviour observed locally will reflect the parent, not the worktree. Verify PHP changes from a non-nested checkout (which is exactly what live verification at `oblivionfindings.com` does).

### Out-of-scope / wontfix (deliberate)

- "Tweaks" panel from the original design bundle — prototype tooling only.
- The full 5,718-line Ops dashboard layout that the redesign replaced — the new shell intentionally collapses that surface area. Tests still pass against the preserved `data-test` attributes (`rostering-publish-panel`, `rostering-review-publish`, `rostering-confirm-publish`, `rostering-suggest-assignments`, etc.).

### Recommended next step

1. Add an `available_until` field to the candidate payload so the "Up to 30 Apr" availability cap line can join the existing "Xh this week" subline.
2. Decide on Make recurring as a roadmap item — needs a controller + service design plus a recurrence input UI before wiring. (Duplicate-as-draft is shipped; "Copy to another day" is the cheap polish that builds on it.)
3. Decide whether completed-shift reopen is in scope. The existing route is cancelled-only; reopening a completed shift would need a new state-machine path plus an audit entry, and is a separate product call from the cancelled-reopen that just shipped.

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

### Follow-up actions pass commits (2026-05-27)

```text
1c6aefda feat(rostering): complete follow-up actions
```

Files touched by `1c6aefda`:

```text
Modified:
  app/Http/Controllers/ShiftController.php                                ← +duplicate() action
  resources/js/components/rostering/coverage-pane.tsx                     ← +CoverageAlertSummary panel
  resources/js/components/rostering/index.ts                              ← re-export
  resources/js/components/rostering/open-shifts-pane.tsx                  ← +hours subline, -Broadcast button
  resources/js/components/rostering/rostering-redesign-followups.test.tsx ← +6 new tests
  resources/js/components/rostering/time-off-pane.tsx                     ← +caught-up empty state copy
  resources/js/components/rostering/week-grid-pane.tsx                    ← +Duplicate + Reopen menu items
  resources/js/pages/operations/rostering/index.tsx                       ← duplicate/reopen handlers, coverageAlerts wiring, signal copy
  routes/operations.php                                                   ← +operations.shifts.duplicate
  tests/Feature/ShiftControllerTest.php                                   ← +2 duplicate-shift feature tests
```

### Follow-up audit fixes (Claude, 2026-05-27)

```text
  app/Http/Controllers/ShiftController.php               ← +assertCanAccessShift in reopenOccurrence
  resources/js/pages/operations/rostering/index.tsx      ← gate onReopenShift on canManageAny; drop unused return_to
  docs/rostering-redesign-followups.md                   ← reflect 1c6aefda + the three audit fixes
```

### Per-candidate eligibility reasons pass (Claude, 2026-05-27)

Implements the doc's Recommended next step #1 — surface backend eligibility detail on the suggestion chips so the Open Shifts pane becomes a real triage surface (vs only filtering on JS-level shift conflicts + time off).

```text
Modified:
  app/Http/Controllers/RosteringController.php                              ← +buildOpenShiftEligibility() helper; +openShiftEligibility payload
  resources/js/pages/operations/rostering/index.tsx                         ← +openShiftEligibility prop; merge into suggestStaffForOpenShift output
  resources/js/components/rostering/open-shifts-pane.tsx                    ← +OpenShiftCandidateEligibility; render warning/blocked chip states with tooltip
  resources/js/components/rostering/rostering-redesign-followups.test.tsx   ← +3 chip-render tests
  docs/rostering-redesign-followups.md                                      ← move per-candidate eligibility to "Resolved"; update Recommended next step
New:
  docs/rostering-per-candidate-eligibility-plan.md                          ← implementation plan
  tests/Feature/Rostering/RosteringOpenShiftEligibilityTest.php             ← +2 Pest tests for payload shape
```
