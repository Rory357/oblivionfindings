# Rostering Redesign Follow-ups

Status as of the Codex implementation pass on 2026-05-27: the rostering redesign has now been audited and the main follow-up gaps from this file have been implemented in this worktree. The manager rostering page now maps the controller payload more completely, and the roster -> worker shift -> attendance -> draft timesheet loop remains backed by the existing services rather than a rewrite.

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

### 1. Rich one-click roster actions

The previous checklist included labels such as Duplicate, Copy to another day, Make recurring, Broadcast to staff, Auto-fill best match, Publish draft, Reopen for correction, and Mark as ended early. Those were not reintroduced because no verified safe route contract exists for them from this audit.

Recommended next step: add these only when each action has a controller endpoint, permission check, and focused feature test.

### 2. Candidate-specific eligibility reasons

The existing controller payload provides shift-level `eligibilityAlerts.blocked` and `eligibilityAlerts.warnings`, and those are now rendered in the Open shifts pane. It does not provide per-open-shift, per-candidate blocker/warning reasons for each suggestion chip.

Recommended next step: only add per-candidate reasons after the backend returns suggestion-level eligibility detail keyed by `shift_id` and `candidate_user_id`.

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

- Browser verification was not completed from this nested Claude worktree because Herd and Composer autoload resolve back to the parent checkout, not this worktree. That would risk verifying the wrong app.
- The new Pest feature test documents the backend `pendingLeave` expectation, but in this nested worktree Composer autoload resolves `App\Http\Controllers\RosteringController` from `C:\Users\steph\Herd\oblivionfindings\app\Http\Controllers\RosteringController.php` instead of the nested worktree. Run it from a non-nested worktree or with local dependencies before treating a failure as a product regression.

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
