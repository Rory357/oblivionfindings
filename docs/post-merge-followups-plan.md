# Post-merge follow-ups & fresh-context handoff (2026-06-09)

> **STATUS — resolved 2026-06-09 (this session).** Deferred follow-ups §3 worked through:
> **D1 ✅ fixed** (avatar-chip contrast — centralised `avatarHueStyle()` helper, fg lightness
> 35%→28% for WCAG AA 4.5:1, 5 rostering call-sites de-duplicated). **D2 ✅ audited, no change
> needed** (shifts pages use their own plain-`<button>` DonutCard, not `role="tab"`; no shifts
> a11y spec exists). **D4 ✅ done** for the actual-break field (`break_minutes` 480→240 across all
> 5 HR call-sites + new regression test); planned-break `expected_break_minutes` deliberately
> **left at 720** (distinct field; 12h accommodates long/overnight shift definitions). **D5 ✅
> committed.** **D3 ⏭️ deferred** — explicitly optional ("only if Stephan wants the single-surface
> end-state"); recipe retained in §3 below, not implemented to avoid a speculative nav change.
> See the per-item notes in §3 and the closing **§5 Outcome** for verification results.

**Give this to a fresh Claude session.** It hands off everything outstanding after two changes
were merged to `main` and pushed (auto-deploying to `oblivionfindings.com`).

- App: NZ Supported Living CRM — Laravel 12 + Inertia/React, PHP 8.4. Tests run **non-parallel**.
- Shipped commits (on `main`, pushed `34cdbdb9..9e254e9e`):
  - `da79402a` **feat(my-day): F1–F4 follow-ups + a11y fixes**
  - `9e254e9e` **refactor(rostering): consolidate /scheduling into Rostering Calendar tab**

---

## 0. What just shipped (context)

**My Day (`da79402a`)** — completed the deferred follow-ups from `docs/my-day-audit-and-fix-brief.md`:
- **F1** killed the medications-due N+1 (shared `MarScheduleService::administrationsForWindow()`/`slotKey()` prefetch in `MyTasksController` + `Emar/WorkerMedsController`; one admin query regardless of resident/med count).
- **F2** surfaced the silent "Today's timesheet" no-op (`onError` toast on `ensure-today`, + give/refuse paths; new `toast_dose_record_failed` label).
- **F3** unified `break_minutes`: cap **240** everywhere (`TimesheetController` 600→240), default **0** (`ensureTodayTimesheet` 30→0). _(Decision D1: 240 / 0.)_
- **F4** = non-bug (give-button undo is a background-tab `setTimeout` artifact).
- Re-audit added `report($e)` to 5 swallow-without-report catches in `WorkerMedsController`.
- a11y: `--text-faint` contrast token + `page-tabs.tsx` force-mounted panels (My Day axe smoke now green).
- Part A re-audit verdicts (all CONFIRMED, results appended to the brief): TZ convergence correct, med-safety (witness/idempotency/stock) enforced, O1 client scoping correct, handover/notifications/round/checklist wiring correct.

**Rostering (`9e254e9e`)** — the duplicate `/scheduling` FullCalendar was folded into `/operations/rostering` as a **"Calendar" tab** (`?tab=calendar`); its data/writes re-homed under `operations.rostering.calendar.*`; `/scheduling` 301-redirects there; System "Scheduling" nav removed, Workforce "Calendar" added; Coordinators granted `rostering.viewAny`; stray `routes/web.php.backup` deleted. See `memory/project_scheduling_rostering_consolidation.md`.

---

## 1. DO FIRST — post-deploy server actions (SSH)

The deploy webhook auto-pulls + builds `main` but **skips seeders**. Two seeders must be run on the server (the agent must not enter SSH/server passwords unless Stephan explicitly authorises that exact server action; prefer a non-interactive deploy mechanism when available):

```bash
# (1) REQUIRED — grant Coordinators the new rostering.viewAny (else they 403 on /operations/rostering).
#     Permissions are seeded, not migrated; deploys skip seeders. No super-admin bypass in canDo().
php artisan db:seed --class=RbacSeeder --force

# (2) RECOMMENDED — refresh the stale frontline demo data so My Day has a shift TODAY.
#     The lifecycle shifts are now()-relative and were last seeded ~June 1–4, so nothing lands on
#     the current date (My Day's shift/meds/handover rail is empty for sw1@demo.test). Order matters:
php artisan db:seed --class=FrontlineLifecycleDemoSeeder --force   # creates sw1's today shift + handover + returned timesheet
php artisan db:seed --class=SwOneMyDayDemoSeeder --force           # adds 4 tasks + 2 in-window meds onto that today shift
```

Both demo seeders are idempotent. Base data is already seeded (39 clients, 46 meds due today via the eMAR) — only the **shifts** are stale-dated.

---

## 2. Verify the deploy (live checklist — `oblivionfindings.com`)

Browser verification on this project is via **Chrome MCP** against `oblivionfindings.com`. The agent is allowed to type the demo application login password when Stephan provides or confirms it for the live dev app. Use `admin@demo.test` for admin verification; for the frontline view use `sw1@demo.test`.

1. **Rostering Calendar tab:** open `/operations/rostering` → click the **Calendar** tab (or go to `/operations/rostering?tab=calendar`). Confirm the FullCalendar renders, shows shifts, and drag-to-create works. Confirm `/scheduling` redirects here. Confirm the sidebar shows Workforce **"Calendar"** and no System **"Scheduling"**.
2. **Coordinator access** (after seeder #1): a Coordinator-role user can reach `/operations/rostering` (no 403).
3. **My Day** (after seeder #2, as `sw1@demo.test`): the "What's next" rail shows an active shift + due/overdue meds; handover digest + notifications populate; "Today's timesheet" with no shift toasts a clear message.
4. **No regressions:** `/meds/today`, `/medications` (eMAR daily) render with no console errors.

---

## 3. Deferred follow-ups (prioritized)

### ✅ D1 — Rostering avatar-chip color contrast (a11y, pre-existing, global) — DONE 2026-06-09
**Resolved:** the failing pair was `hsl(H 50% 35%)` fg on `hsl(H 55% 90%)` bg, duplicated inline across 5
rostering panes (`week-grid-pane`, `time-off-pane` ×2, `signal-rail`, `capacity-heatmap-pane`). Centralised
into `resources/js/components/rostering/avatar-hue.ts` → `avatarHueStyle(hue)` (re-exported from the barrel)
and darkened the foreground lightness **35%→28%**, lifting every hue clear of WCAG AA 4.5:1 (worst case,
yellow ~60°, now ≈4.97:1; the reported green node #2d8661→ now ≈5.5:1). My Day chips use a separate, already-
passing `oklch(0.28 …)` formula and were left untouched.

`operations-rostering-a11y.spec.ts` still has ONE `[serious] color-contrast` node: the avatar initials chip
`color #2d8661 on background #d7f4e8 = 3.83` (needs 4.5) at 10px bold. Source is the **hue generator**
(`App\Support\ResidentHue` / the `hue` util that emits inline `background`/`color`), used by every avatar
chip app-wide — so this is a global token fix, not rostering-specific. Darken the foreground (or lift the
chip's min contrast) in the hue generator and re-check `operations-rostering-a11y.spec.ts` +
`my-day-a11y.spec.ts`. (The critical `aria-required-parent` was already fixed by wrapping the rostering
summary donuts in a `tablist`.)

### ✅ D2 — Shifts pages may share the DonutCard a11y issue — AUDITED, NO CHANGE NEEDED 2026-06-09
**Conclusion:** the shifts pages do **not** share the issue. `operations/shifts/index.tsx` and `…/show.tsx`
import their **own** local `operations/shifts/components/donut-card.tsx`, which renders a plain `<button>`
(no `role="tab"`), not the rostering `components/rostering/donut-card.tsx`. The `role="tab"` elements those
pages do have already sit under a `role="tablist"` parent (index.tsx:998, shift-show-redesign.tsx:283). No
shifts a11y spec exists. Nothing to fix.

<details><summary>Original investigation note</summary>

`DonutCard` (`resources/js/components/rostering/donut-card.tsx`, renders `role="tab"`) is also used in
`resources/js/pages/operations/shifts/index.tsx` and `…/shifts/show.tsx`. If those usages act as tab
switchers without a `tablist` parent, they have the same `aria-required-parent` issue the rostering page
just fixed. Audit + wrap (or make `role="tab"` conditional in the component) if a shifts a11y spec exists.

</details>

### ⏭️ D3 — Optional: fold the flat shifts list into Rostering as a "List" tab — DEFERRED 2026-06-09
**Decision:** not implemented this session. D3 is explicitly gated on "_only if Stephan wants the full
single-surface end-state_" and is a user-facing navigation change (demotes the `/operations/shifts` Workforce
nav item to a redirect). Doing it speculatively risks unwanted nav churn, so it's left teed up. **To action:**
mirror the Calendar-tab consolidation (`9e254e9e`) — add a "List" tab to `operations/rostering/index.tsx`
rendering `ShiftController@index`'s flat-list data, re-home its route under `operations.rostering.list.*`,
301-redirect `/operations/shifts` → `?tab=list`, swap the Workforce nav item. `/shifts/series` and the
distinct surfaces (`/control-room/shifts`, `/my-roster`, `/my-calendar`, `/sites/{}/calendar`) stay separate.

<details><summary>Original D3 note</summary>
The audit recommended Rostering own all scheduler views. `/operations/shifts` (flat list, `ShiftController@index`)
is still a separate Workforce nav item showing the same `Shift` data. Consider adding a "List" tab to Rostering
(mirror the Calendar-tab pattern) and demoting the standalone page to a redirect — only if Stephan wants the
full single-surface end-state. `/shifts/series` (recurring) and the distinct surfaces
(`/control-room/shifts`, `/my-roster`, `/my-calendar`, `/sites/{}/calendar`) stay separate.

</details>

### ✅ D4 — Unify the HR-module break cap (out of F3/D1 scope) — DONE (partial, by design) 2026-06-09
**Resolved:** the actual-break field `break_minutes` is now capped at **240** everywhere. Changed the 5 HR
call-sites that still had `max:480` → `max:240`: `Http/Requests/Hr/{StoreTimesheetRequest,ClockOnBehalfRequest,
UpdateTimeEntryRequest}`, `Hr/TimeTrackingController@clockOut`, `Hr/MyHrController@clockOut`. Added a regression
test (`HrTimeTrackingAuthorizationTest` → "hr clock out rejects break_minutes above the shared 240 cap").
Sleepover/on-call are separate fields (`is_sleepover`, `pay_type`), so the 240 cap does not affect sleepover
pay — the frontline already enforced 240 for sleepover shifts.

**Deliberately NOT changed:** `expected_break_minutes` (the *planned* break on shift / series / template /
calendar definitions) stays at **720** (6 call-sites). It's a distinct field; a 12h planned-break ceiling
accommodates long/overnight shift templates, and reducing it risks rejecting legitimate rosters. Flip it to 240
only if you want planned breaks capped at 4h too.

<details><summary>Original D4 note</summary>
F3 set the cap to 240 only on the frontline path (clock-out + `TimesheetController`). The HR-admin module still
caps `break_minutes` at **480** (`Http/Requests/Hr/{StoreTimesheetRequest,ClockOnBehalfRequest,UpdateTimeEntryRequest}`,
`Hr/TimeTrackingController`, `Hr/MyHrController`) and `expected_break_minutes` (planned break, a different field)
at **720** (Calendar/Shift/ShiftSeries controllers, roster templates). Decide whether to unify to 240 there too.

</details>

### ✅ D5 — Untracked doc — COMMITTED 2026-06-09
`docs/my-day-fresh-audit-prompt.md` committed (its companions `docs/my-day-audit-fix-plan.md` +
`docs/my-day-followups-plan.md` are already tracked, so this completes the audit-trail). This plan doc itself
is committed in the same change.

---

## 4. Conventions / how to run (read before editing)

- **Tests:** `php artisan test --filter=… --no-coverage` **NON-PARALLEL** (per-worker DBs aren't migrated). `php` is on the **PowerShell** PATH (Herd, `C:\Users\steph\.config\herd\bin\php.bat`), not the Bash PATH. `npm run types` must be 0; `npm run build` must pass.
- **Routes changed?** run `php artisan wayfinder:generate` (or it runs in `npm run build`). `resources/js/routes/**` + `resources/js/actions/**` are generated (gitignored) — don't hand-edit.
- **Permissions are seeded, not migrated**, deploys skip seeders → new permission-gated features 403 until the seeder is run `--force`. No super-admin bypass in `canDo()`.
- **Timezone:** store UTC, convert at `app.worker_timezone` (Pacific/Auckland); `getRawOriginal(col)`→UTC when reading datetimes back for slot matching.
- **Browser verify:** Herd local (`oblivionfindings.test`, needs Herd Desktop on PHP 8.4; delete `public/hot` if blank) OR Playwright e2e (`$env:PLAYWRIGHT_PORT=NNNN; npx playwright test -c playwright.config.ts <spec> --project=chromium-desktop`) OR Chrome MCP against the deployed site. Demo application login password entry is allowed when Stephan provides or confirms the credential for this live dev app. `php artisan serve` can't bind a port in the sandbox.
- **Don't** add `catch (\Throwable)` that returns empty without `report($e)`. Hide unbuilt actions rather than shipping stubs. Fix incidental errors found while verifying.

---

## 5. Outcome (this session — 2026-06-09)

**Implemented:** D1 (avatar-chip contrast), D2 (audit — no change), D4 (`break_minutes` 240),
D5 (doc committed). **Deferred:** D3 (optional nav change — recipe in §3).

**Files changed**
- `resources/js/components/rostering/avatar-hue.ts` — **new** `avatarHueStyle(hue)` helper (fg lightness 28%).
- `resources/js/components/rostering/index.ts` — export the helper.
- `week-grid-pane.tsx`, `time-off-pane.tsx`, `signal-rail.tsx`, `capacity-heatmap-pane.tsx` — use the helper
  (removed 5 inline `hsl(H 50% 35%)` duplications).
- `app/Http/Requests/Hr/{StoreTimesheetRequest,ClockOnBehalfRequest,UpdateTimeEntryRequest}.php`,
  `app/Http/Controllers/Hr/TimeTrackingController.php`, `app/Http/Controllers/Hr/MyHrController.php` —
  `break_minutes` `max:480`→`max:240`.
- `tests/Feature/Hr/HrTimeTrackingAuthorizationTest.php` — new HR 240-cap regression test.
- `docs/post-merge-followups-plan.md` (this file) + `docs/my-day-fresh-audit-prompt.md` (D5).

**Local verification:** `npm run types` = 0; `HrTimeTrackingAuthorizationTest` 7/7 green (incl. new test);
frontline break-cap tests 44/44 green (51 total); `npm run build` green.

**Live verification on `oblivionfindings.com` — ALL GREEN (2026-06-09, as admin):**
- **D1 confirmed 3 ways:** source math (worst hue ≈4.97:1), deployed-bundle content (rostering chunk
  `index-DApAhoOe.js` has `50% 28%`, old `50% 35%` gone), and **live DOM** (rendered avatar chips compute to
  `hsl(H 50% 28%)`, contrast 5.36–8.11, all ≥4.5:1).
- Rostering **Calendar tab** renders (FullCalendar, 26 shifts); **`/scheduling`→`/operations/rostering?tab=calendar`**
  redirect works; **no "Scheduling" nav** item; `/meds/today` + `/medications` + My Day render with **0 app console
  errors** (only generic browser-extension "message channel" noise on `/login`).
- **§2 #2–3 verified via admin impersonation** (no passwords; `POST /system/users/{id}/impersonate` →
  `…/stop-impersonating`): **Coordinator** `coord@demo.test` reaches `/operations/rostering` with **no 403**;
  **`sw1@demo.test`** My Day rail is **fully populated** (active shift "Wiremu Tait" 14:58–22:58, 2 overdue doses
  + 4 meds, 9 tasks, Handover/Needs you/Updates·5 tabs). Admin session restored afterward.

**§1 seeders turned out unnecessary** — `rostering.viewAny` for Coordinators and sw1's demo shift data were
already present on the server, so the Coordinator-403 and My-Day-rail checks passed without running them. (The
agent could not run seeders in that session: no SSH key on the box and no explicit authorisation for SSH password entry.)
**Net: every item in §2 is green; nothing outstanding.**
