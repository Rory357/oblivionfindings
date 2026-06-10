# Workforce Consistency — Outstanding Items & Fresh-Context Handoff

**Date:** 2026-06-10 · **Branch:** `main` · **Shipped:** commits `8b4b095b..37c23031` (pushed, auto-deploying)
**Full background:** `docs/rostering-frontline-end-to-end-audit.md` (audit, plan, decisions, consistency matrix — read that first; this file is only what's LEFT).

> **STATUS UPDATE (2026-06-10, later the same day):** every actionable item below was completed
> end-to-end in the follow-up batch `de39e0c3..9dee2740` (+ docs commit), including the items that
> were deferred-pending-appetite — the user approved reopening 4, 6 and 9. Per-item outcomes are
> annotated inline as **✅ DONE**. Items 5, 10, 13, 14 keep their recorded decisions. Verified:
> tsc, build, vitest 130/130, change-scoped Pest (attendance 69, timesheets 206+3, publish audit 2+4,
> suggestions e2e 2), Herd browser smoke of every changed surface, then remote deploy + live
> spot-checks + the demo stuck-session cleanup on oblivionfindings.com.

## Where things stand (do not redo)

The web/desktop workforce experience (My Day, Shifts, Job Board, Rostering, Calendar, Availability, Handovers, Shift Notes, Timesheets, Conflict Queue, Attendance) is **consistent and verified** as of the commits above:

- Every surface hero is on `PageHero category="ops"` with the Rostering pattern (greeting, live eyebrow, truthful badges, stats; week-stepper + filters footer where week-scoped). My Day intentionally keeps its avatar hero; ~~Timesheets intentionally has no week-stepper~~ (now wired — see item 6).
- Multi-step popups (handover, shift-note, template, create-timesheet, **and now create/edit-shift**) follow the Add Client wizard chrome via shared `resources/js/components/wizard/primitives.tsx`. Single-step popups follow `docs/POPUP_STYLE_GUIDE.md`; view-timesheet is the read-only-detail reference conversion.
- Attendance is in the Workforce sidebar, has the full hero, and a manager **“On the clock now”** board (`AttendanceController@index` additive payload: `weekHours`, `onClockNow` with 16h+ `is_stale` flags) — now with a manager **End session** action.
- **Trust the corrections in the audit doc over re-sweeping** — several plausible findings were falsified by direct inspection (`--category-ops` aliases `--primary`; the My Day timesheet dialog already conforms; suggestion accept/dismiss DO persist actor attribution; the dual handover paths share one model/service by design).

## Outstanding — actionable next

1. **✅ DONE — Verify the deploy landed** on oblivionfindings.com. Verified login-free via remote `/build/manifest.json` chunk greps (“On the clock now” + `is_stale` in the attendance chunk, create-timesheet wizard strings in the timesheets chunk, “Week covered” in the shifts hero chunk).
2. **✅ DONE — Demo-data hygiene:** built the admin end-session action (see below) and used it on the live demo board to close the stuck sessions (open since Mon 8 Jun) with reason “Stale demo session — administrative close”. Audit rows + draft timesheets verified.
3. **✅ DONE — Replace `window.prompt()`** — the respite ReasonDialog was promoted to shared `resources/js/components/reason-dialog.tsx` (required reason, Loader2 while submitting) and now backs timesheet reject/return in BOTH the view-dialog footer and the index row context menu. Commit `9c0e2c8b`. (eMAR `MarCharts.tsx` ×2 and My Day ×1 prompts remain — they belong to those modules’ own plans, see `docs/my-day-audit-fix-plan.md`.)

## Formerly deferred — reopened with user approval 2026-06-10, now shipped

4. **✅ DONE — Create Shift dialog → full stepper wizard** (commit `7e33a3ca`). Handover-wizard chrome: rail with free-jump steps + readiness card, progress strip, Back/Continue footer; steps type → who&where → schedule → repeat (create-only) → tasks → review (recap grid with per-row Edit jumps + server-error summary). Chrome-only restructure — useForm shape, payloads, routes, eligibility preview and override dialog untouched; vitest updated (4 tests).
5. **`/operations/*` → `/workforce/*` URL rename — DECLINED (unchanged).** Measured blast radius: 4,443 frontend refs, 750 PHP route-name refs, 89 generated Wayfinder files, 27 e2e specs, plus emailed/bookmarked URLs. Same for `/attendance` → `/operations/attendance` (109 refs). Revisit only for URL-based permission scoping or a public API.
6. **✅ DONE — Timesheets hero week-nav** (commit `d1045193`). The stepper writes the EXISTING `from`/`to` filters (Shifts-page precedent); `TimesheetController::resolveSummaryWeek()` makes the hero summary follow any exact Mon–Sun pair, else current week; “All weeks” chip clears. **Default stays unfiltered, so the approval queue never week-hides by default** (locked in by `TimesheetHeroWeekScopeTest`). Pick-week reuses the shared rostering WeekPicker.
7. **✅ DONE — Add Client dialog refactor** onto `components/wizard/primitives.tsx` (commit `de39e0c3`): the ten byte-identical private copies were deleted in favour of imports (−303 lines); ConsentChip + PhotoField stay local.
8. **✅ DONE — `lib/date-format.ts` retirement** repo-wide (commit `439e7547`): added Auckland-pinned `formatDateLong` / `formatDateTimeLong` to `lib/datetime.ts` (records keep their year), migrated all 49 importers, deleted the legacy helper. Accepted deltas: “Not set”→“—”, “09:00am”→“9:00 am”, browser-tz→Pacific/Auckland.
9. **✅ DONE — Body-idiom harmonization** (commit `9dee2740`): one tab idiom — the shared `components/rostering/tab-strip.tsx` (new `ariaLabel` prop) now renders the status tabs on Shifts (local underline copy deleted), Handovers, Shift Notes and Timesheets. DonutCards stay as data-viz selector cards. Shifts a11y contract (`tablist` named “Shift views”) preserved.
10. **Delete `tests/Browser/Frontline/FrontlineStaffUxTest.php` after 2026-08-01**, per its header (superseded by Playwright; needs 30 consecutive green CI runs first). *(Unchanged — time-gated.)*

## Outstanding — automation follow-ups (from audit §6)

11. **✅ DONE** — `RosterPeriodPublished` now has a sync listener (`app/Listeners/Rostering/RecordRosterPeriodPublishedAudit.php`, commit `27eb28a5`) writing an `AuditLogger` row `rostering.period.published` (actor, republished flag, site, week, version, shift count). Deliberately an audit entry, NOT a client TimelineEvent, per the recorded decision. Pest-tested.
12. **✅ DONE** — end-to-end feature test for suggest → accept → apply now exists: `tests/Feature/Rostering/SuggestionEndToEndTest.php` (commit `f33ff25b`) drives the real routes (permission middleware + feature flag + org checks + applier) and asserts the shift lands on the suggested candidate with accept/apply attribution intact, + a 403 case.
13. Bulk `apply-accepted` relies on per-row preflight rather than per-suggestion re-confirmation — assessed acceptable; revisit only if policy tightens. *(Unchanged.)*
14. **Product decision parked:** “shift note due” nudge in the end-of-shift checklist — backend has no per-shift note rule, so adding the nudge would invent policy. *(Unchanged.)*

## New in the follow-up batch (not in the original handoff)

- **Admin end-session** (commit `1c8795f5`): `AttendanceService::adminEndSession()` + `POST /attendance/sessions/{session}/end`, gated by `timesheets.manageAny` (the exact permission that shows the board — **no new permissions, no seeder run needed**). Closes at the rostered shift end when past (else now), clamps a days-old open break below elapsed so the safety invariant can’t strand the session, completes an in-progress shift via the forced path, syncs the draft timesheet, records `closed_by` + meta + an `attendance.session.adminEnded` audit row. Board rows get an **End session** button opening the shared ReasonDialog. 6 Pest tests.

## Watch-outs

- `onClockNow` scopes via `User::staff()` (same as the staff picker). If attendance ever gains **site-level** access scoping, the board (and the end-session action) must inherit it.
- Deploys skip seeders: any FUTURE permission-gated feature still needs its `*PermissionsSeeder --force` run on the server (not needed for this batch either — end-session reuses `timesheets.manageAny`).
- Browser console shows “message channel closed” exceptions at `:0:0` on the dev machine — Chrome-extension noise, not app errors.
- Verifying a deployed frontend fix without login: grep the deployed JS chunk (read REMOTE `/build/manifest.json` for chunk names — the server hash differs from local builds).
