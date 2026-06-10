# Workforce Consistency — Outstanding Items & Fresh-Context Handoff

**Date:** 2026-06-10 · **Branch:** `main` · **Shipped:** commits `8b4b095b..37c23031` (pushed, auto-deploying)
**Full background:** `docs/rostering-frontline-end-to-end-audit.md` (audit, plan, decisions, consistency matrix — read that first; this file is only what's LEFT).

## Where things stand (do not redo)

The web/desktop workforce experience (My Day, Shifts, Job Board, Rostering, Calendar, Availability, Handovers, Shift Notes, Timesheets, Conflict Queue, Attendance) is **consistent and verified** as of the commits above:

- Every surface hero is on `PageHero category="ops"` with the Rostering pattern (greeting, live eyebrow, truthful badges, stats; week-stepper + filters footer where week-scoped). My Day intentionally keeps its avatar hero; Timesheets intentionally has no week-stepper (approval worklist must not week-hide).
- Multi-step popups (handover, shift-note, template, create-timesheet) follow the Add Client wizard chrome via shared `resources/js/components/wizard/primitives.tsx`. Single-step popups follow `docs/POPUP_STYLE_GUIDE.md`; view-timesheet is the read-only-detail reference conversion.
- Attendance is in the Workforce sidebar, has the full hero, and a manager **“On the clock now”** board (`AttendanceController@index` additive payload: `weekHours`, `onClockNow` with 16h+ `is_stale` flags).
- Verified: types, build, vitest 123/123, targeted PHPUnit (suggestion audit-trail 4, attendance payload 3, attendance regression 23, handover 51, shift-note 10), route counts (rostering 28 / job-board 4 / shift-notes 6 / timesheets 19 / attendance 8), browser pass on Herd as `admin@demo.test`.
- **Trust the corrections in the audit doc over re-sweeping** — several plausible findings were falsified by direct inspection (`--category-ops` aliases `--primary`; the My Day timesheet dialog already conforms; suggestion accept/dismiss DO persist actor attribution; the dual handover paths share one model/service by design).

## Outstanding — actionable next

1. **Verify the deploy landed** on oblivionfindings.com (push was 2026-06-10). Recipe: fetch remote `/build/manifest.json` and confirm the hash changed vs pre-deploy, then spot-check `/operations/shifts` (new hero), `/operations/timesheets?create=1` (wizard chrome), `/attendance` (board). No seeders needed — no new permissions were introduced.
2. **Demo-data hygiene:** the on-clock-now board flags ~6 open sessions stuck since Mon 8 Jun on the demo DB (likely seeded/abandoned). Clock them out or clean them; the board flagging them is correct behaviour.
3. **Replace `window.prompt()`** for reject/return reasons in `resources/js/components/timesheets/view-timesheet-dialog.tsx` (~footer actions) with a small reason-collecting dialog (pattern: rostering’s reason-field dialogs, e.g. `unassign-make-open-dialog`). Polish, low risk.

## Outstanding — deliberate deferrals (decision recorded; need a driver to reopen)

4. **Create Shift dialog → full stepper wizard** (`pages/operations/shifts/components/create-shift-dialog.tsx`, ~560-line single-pane body). Deferred: broad rewrite of a heavily-tested core flow; chrome already conforms. Reopen only with appetite for a dedicated pass + regression budget.
5. **`/operations/*` → `/workforce/*` URL rename — DECLINED.** Measured blast radius: 4,443 frontend refs, 750 PHP route-name refs, 89 generated Wayfinder files, 27 e2e specs, plus emailed/bookmarked URLs. Same for `/attendance` → `/operations/attendance` (109 refs). Revisit only for URL-based permission scoping or a public API.
6. **Timesheets hero week-nav**: hero summary is hardcoded to “this week” (`TimesheetController` ~line 437). Wiring prev/next/pick-week needs a week-aware summary AND a decision on list scoping (the approval queue must never week-hide overdue items). The hero already hides the unwired chips.
7. **Add Client dialog refactor** onto `components/wizard/primitives.tsx` (it still has private copies — it IS the reference, so it matches by definition). Dedupe when next touching that file.
8. **`lib/date-format.ts` retirement** repo-wide (workforce surfaces already use `lib/datetime.ts`; other modules still import the legacy helper).
9. **Body-idiom harmonization** (optional, larger): DonutCard tab-switchers (Rostering/Shifts) vs pill tabs (Handovers/Notes/Timesheets) vs KPI strips differ per surface. Cosmetically varied but each is internally consistent; only worth a dedicated design pass.
10. **Delete `tests/Browser/Frontline/FrontlineStaffUxTest.php` after 2026-08-01**, per its header (superseded by Playwright; needs 30 consecutive green CI runs first).

## Outstanding — automation follow-ups (assessed low-risk; from audit §6)

11. `RosterPeriodPublished` event has no listener writing an audit/timeline entry (period rows do carry `published_by/at` — acceptable; add a listener if richer publish audit is wanted).
12. No end-to-end feature test for suggest → accept → apply (unit/applier coverage exists; `SuggestionAuditTrailTest` covers accept/dismiss attribution).
13. Bulk `apply-accepted` relies on per-row preflight rather than per-suggestion re-confirmation — assessed acceptable; revisit only if policy tightens.
14. **Product decision parked:** “shift note due” nudge in the end-of-shift checklist — backend has no per-shift note rule, so adding the nudge would invent policy.

## Watch-outs

- `onClockNow` scopes via `User::staff()` (same as the staff picker). If attendance ever gains **site-level** access scoping, the board must inherit it.
- Deploys skip seeders: any FUTURE permission-gated feature still needs its `*PermissionsSeeder --force` run on the server (not needed for this batch).
- Browser console shows “message channel closed” exceptions at `:0:0` on the dev machine — Chrome-extension noise, not app errors.
- Verifying a deployed frontend fix without login: grep the deployed JS chunk (read REMOTE `/build/manifest.json` for chunk names — the server hash differs from local builds).
