# Checklists — Profile De-dup + Remaining Items (Codex implementation plan)

**Audience:** a fresh Codex context with NO memory of the prior work. Everything
needed is here. Read §1–§2 (context + environment), then implement §3 (primary)
and §4 (high), then §5–§7 as scoped, then verify with §8.

> Status legend: ✅ done · 🟡 partial · ❌ todo · ⚠️ caveat
> Priority legend: **P0** must-do (the owner asked) · **P1** high (a shipped
> feature is currently broken) · **P2** medium · **P3** low/optional.

> This plan supersedes §5 ("Site-profile Checklists tab") of the earlier doc
> `docs/checklists-followups-audit-and-plan-2026-06-05.md`. That doc's §3/§4/§6
> are **fully implemented and tested** — do NOT redo them. The line numbers below
> were verified against the working tree at commit `cf2f8099`; re-confirm with a
> quick grep before editing, since they drift.

---

## 0. TL;DR — what to build

1. **P0 — De-duplicate the Site-profile Checklists tab.** It currently embeds the
   ENTIRE `ChecklistsWorkspace` (big hero banner + 7 tabs), which just duplicates
   the dedicated `/sites/{site}/checklists` page. Replace it with a **compact
   summary card + a prominent "Open full checklists page" button**. The full hero
   + workspace stays ONLY on `/checklists` and `/sites/{site}/checklists`.
2. **P1 — Fix the "skipped run" dead-end.** Skipping a run (shipped feature)
   currently makes it vanish from every pane with no way to view or un-skip it.
   Surface skipped runs in the Runs pane and add a Restore (un-skip) path.
3. **P2 — Retire the orphaned legacy run pages** (`runs.tsx`, `runs/[id].tsx` and
   their controller methods) — unreachable from the new UI and missing the
   `failure_creates_damage` flag.
4. **P3 — Polish:** portal the right-click menu; filter `assignableUsers` to
   active staff.
5. **Backlog (optional, only if asked):** reports weighting, real photo upload,
   drag-to-reschedule.

---

## 1. Context — the rebuilt Checklists module

One system, three surfaces, one workspace, one backend builder. **Never fork the
logic — scope by `site_id`.**

| Surface | Route | Controller | Inertia page | Hero? |
|---|---|---|---|---|
| Org dashboard | `GET /checklists` | `Sites\ChecklistsDashboardController@index` → `forOrg()` | `checklists/index` | **yes (keep)** |
| Per-site full page | `GET /sites/{site}/checklists` | `Sites\SiteChecklistController@index` → `forSite()` | `sites/checklists/index` | **yes (keep)** |
| **Site-profile tab** | `GET /sites/{site}` (Checklists tab) | `SiteController@show` | `sites/show` | **NO — remove (this plan)** |

- Shared workspace: `resources/js/components/checklists/workspace.tsx`
  (`ChecklistsWorkspace`) → renders `hero.tsx` (the banner) + a 7-tab Rostering
  `TabStrip` + panes in `resources/js/components/checklists/panes/` + `RunModal`
  + `TemplateBuilderModal`.
- Backend builder: `app/Support/ChecklistsDashboardData.php`
  (`forOrg()` / `forSite(Site)`) returns the `ChecklistsData` contract
  (`resources/js/components/checklists/types.ts`).
- Run actions live on `SiteChecklistController` (`saveResponse`, `completeRun`,
  `rescheduleRun`, `reassignRun`, `skipRun`) — all return `redirect()->back()`.
- Reusable components (`RunListRow`, `WorklistCard`, `AssignChecklistButton`,
  `CategoryIcon`, `StatusBadge`, …) **all call `useChecklistConfig()` and throw
  outside `<ChecklistConfigProvider>`** — important for §3 (see the warning there).

---

## 2. Environment & workflow (read before coding)

- **Remote** `https://oblivionfindings.com`. Deploy = push to `main`; a webhook
  auto-pulls **and** auto-builds (`npm ci` + `vite build`, ~5–8 min). **Migrations
  & seeders are MANUAL on the server** (`php artisan migrate --force`, etc.).
- ⚠️ **`back()` + Inertia partial reloads:** every run-action endpoint returns
  `redirect()->back()`, which triggers a **full Inertia reload of the current
  page**. Consequence for the profile: any prop the profile tab needs must
  survive a full reload — i.e. a **normal prop, never `Inertia::optional`** (an
  optional prop is dropped on a full `back()` reload and the tab would blank).
- ⚠️ **NZ timezone (UTC+12/13):** never use `toISOString().slice(0,10)` for date
  math — use the local helpers `ymd`/`startOfWeek`/`addDaysWP` from
  `@/components/rostering/week-picker`.
- ⚠️ **Local browser verification is impossible** (Herd web PHP 8.3.29 < vendor's
  ≥8.4.1 → `oblivionfindings.test` 500s on every route). CLI php is ≥8.4 so
  `artisan migrate`/`db:seed`/`test` work. Verify UI on the **remote** after
  deploy. Run tests **non-parallel** (`php artisan test --filter=…`, NOT
  `--parallel` — per-worker DBs aren't migrated here).
- ⚠️ **Shared checkout** — stage explicit paths (never `git add -A`); commit with
  the `Co-Authored-By: Claude Opus 4.8` trailer. Verify before pushing:
  `npx tsc --noEmit` (0 errors) + `npm run build` (exit 0).

---

## 3. P0 — De-duplicate the Site-profile Checklists tab

**Problem.** `resources/js/pages/sites/show.tsx` (Checklists `TabsContent`,
≈L1876–1894) renders the FULL `<ChecklistsWorkspace scope={{mode:'site',…}}
data={checklistsData} />` — its big hero banner + 7 tabs are an exact duplicate of
the dedicated `/sites/{site}/checklists` page. Backend `SiteController@show`
(≈L394–399) builds the entire `forSite($site)` payload **eagerly on every profile
load** (≈30+ queries incl. the catalog, an 8-week×2 trend loop, `assignableUsers`),
even when the user never opens the tab.

**Chosen approach: revert to a lightweight summary + a link-out button.** The full
workspace exists one click away; the profile only needs a glanceable summary.

### 3.1 Backend — `app/Http/Controllers/SiteController.php@show`
1. **Delete** the eager build (`$checklistsData = (new ChecklistsDashboardData(
   $request))->forSite($site);`, ≈L394–399).
2. **Replace** the three return keys (≈L688–690: `checklistsData`, `runDetail`,
   `templateDetail`) with a single:
   `'checklistsSummary' => $this->buildSiteChecklistsSummary($site, $user),`
3. **Re-add** the private method `buildSiteChecklistsSummary(Site $site, $user):
   array`. It existed before the embed and is recoverable verbatim:
   `git show cf2f8099^:app/Http/Controllers/SiteController.php` (method ≈L892–980;
   it returns `stats{active_assignments,scheduled,in_progress,overdue,
   completed_30d}`, `assignments[]`, `recentRuns[]` (limit 5), `availableTemplates[]`,
   `can{view,schedule,run}`). Modernise it minimally:
   - Add `'on_track'` to `stats` using the same formula as
     `ChecklistsDashboardData` `SiteOverview.on_track_rate` (completed30 vs
     currently-overdue → `($c+$o)===0 ? 100 : round($c*100/($c+$o))`).
   - Add a small `dueSoon` list: `SiteChecklistRun::where('site_id',$site->id)
     ->whereIn('status',['scheduled','in_progress'])->with('template:id,name')
     ->orderBy('scheduled_date')->limit(5)->get()` mapped to
     `{id,template_name,scheduled_date,status,is_overdue}` (overdue =
     `scheduled_date < today && status != completed`). This drives the
     "Due / overdue" list in the card.
4. Remove the now-unused `use App\Support\ChecklistsDashboardData;` import (≈L33)
   **only if** nothing else in the controller references it (grep first).
5. **Do NOT** read `?run=`/`?template=` here and **do NOT** surface
   `runDetail`/`templateDetail` from `show()` — the compact tab opens no modals,
   so they're dead weight. (They remain on the two dedicated routes via
   `ChecklistsDashboardData`, untouched.)

### 3.2 Frontend — `resources/js/pages/sites/show.tsx`
1. **Remove** the imports `ChecklistsWorkspace` + `ChecklistsData` (≈L111–112).
2. **Define** a small local type and swap the prop (≈L605) +
   destructure (≈L861): `checklistsData?: ChecklistsData` →
   `checklistsSummary?: SiteChecklistsSummary` (shape it to match §3.1's return:
   `{stats, assignments, recentRuns, dueSoon, availableTemplates, can}`). Put the
   type near the other local types in `show.tsx`.
3. **Replace** the Checklists `TabsContent` body (≈L1877–1894) with a compact,
   **link-out** summary rendered by a new local component
   `SiteChecklistsSummaryCard({ summary, siteId })`:
   - **Header row:** title "Checklists" + the required CTA on the right:
     `<Button asChild><Link href={`/sites/${siteId}/checklists`}>Open full
     checklists page<ArrowRight/></Link></Button>`.
   - **KPI strip** from `summary.stats` (Active · Scheduled · In progress ·
     Overdue · Completed 30d · On-track %). Use the `Card` primitives already
     imported in `show.tsx`; mirror the look of `panes/overview.tsx`'s `miniKpi`
     (≈L22–31) but as **plain local markup**.
   - **"Due / overdue"** list (≤5) from `summary.dueSoon` and a **"Recent runs"**
     list (≤5) from `summary.recentRuns` — plain rows (template name · relative
     date · status pill). Each row is a `<Link href={`/sites/${siteId}/checklists`}>`
     (informational; no modal).
   - **Empty states** when arrays are empty ("No checklists assigned yet" with the
     same CTA).
   - Gate on `summary.can.view`; if false, render nothing (or a muted "You don't
     have access to checklists for this site").
   - Show a `Loading…` `Card` when `summary` is undefined (mirrors the existing
     guard, though the prop is eager so it'll be present).

   **⚠️ Do NOT reuse `RunListRow`/`WorklistCard`/`CategoryIcon`/`AssignChecklistButton`
   here** — they call `useChecklistConfig()` and throw without
   `ChecklistConfigProvider`. Use plain markup + `StatusBadge` is also
   context-free? No — keep it simple with local Tailwind. (If polished run cards
   are truly wanted, the cost is wrapping the subtree in `ChecklistConfigProvider`
   with a full config incl. `openRun:()=>{}`, `openBuilder:()=>{}`,
   `assignableUsers:[]`, `scope:{mode:'site',site,backHref}` — but those cards then
   mount the right-click menu whose actions `back()`-reload the profile. Not worth
   it; plain markup wins.)

### 3.3 Keep the dedicated pages intact
No changes to `resources/js/pages/checklists/index.tsx`,
`resources/js/pages/sites/checklists/index.tsx`, `workspace.tsx`, `hero.tsx`, or
`ChecklistsDashboardData`. The run-modal/template-builder partial reloads keep
working there because those controllers still surface `runDetail`/`templateDetail`.

### 3.4 Test
Add a `SiteController` feature test: `GET /sites/{house}` as an admin →
`assertInertia` has `checklistsSummary.stats` and **`->missing('checklistsData')`**
+ `->missing('runDetail')`. (Pattern: copy `tests/Feature/SiteControllerTest.php`
setup.)

---

## 4. P1 — Fix the "skipped run" dead-end

**Problem.** `skipRun` sets `status='skipped'`, but a skipped run is excluded from
**both** payload arrays — `activeRuns` filters `whereIn('status',
['scheduled','in_progress'])` (`ChecklistsDashboardData.php` ≈L101) and
`recentRuns` filters `where('status','completed')` (≈L123). Every pane reads only
those two arrays, so a skipped run **disappears from the entire UI**, and there is
**no un-skip endpoint**. The `runStatusMeta` "Skipped" branch (`category.ts`
≈L81–83) and the context-menu's `skipped` guard (`context-menu.tsx` ≈L146/L177)
are currently dead code. Skip is therefore a destructive one-way action.

**Do:**
1. **Surface skipped runs.** In `ChecklistsDashboardData::build()`, add a
   `skippedRuns` array (site-scoped when `$siteId`): `whereIn('status',['skipped'])
   ->with(['site:id,name,type','template:id,name,frequency','assignedTo:id,name',
   'assignment.assignedTo:id,name'])->orderByDesc('updated_at')->limit(40)` mapped
   like `activeRuns` (assignee = `assignedTo?->name ?? assignment?->assignedTo?->name
   ?? 'Unassigned'`, include `assigned_to_id`). Add `skippedRuns: ChecklistRun[]`
   to the `ChecklistsData` contract (`types.ts`) and to `PaneCtx`
   (`context.tsx`) + the workspace's `ctx` build (`workspace.tsx`).
2. **Show them in the Runs pane.** `resources/js/components/checklists/panes/runs.tsx`:
   add `'skipped'` to `STATUS_OPTIONS` (≈L9–15) and to the status filter
   (`matchS`, ≈L31–36); include `ctx.skippedRuns` in the rendered list (a
   "Skipped" group, or merged into history with the filter). The existing
   `RunListRow` already renders the "Skipped" badge via `runStatusMeta`.
3. **Add a Restore (un-skip) path.**
   - Backend: `SiteChecklistController@restoreRun(Request, SiteChecklistRun $run)`
     → `authorize('update', $run->site)`; if `status==='skipped'` set
     `status='scheduled'`; `return back()->with('success','Checklist run restored.')`.
   - Route (`routes/sites.php`, run block): `POST /checklists/runs/{run}/restore`
     → `sites.checklists.restoreRun`, `middleware('permission:checklists.schedule')`.
   - Frontend: in `context-menu.tsx`, when `run.status==='skipped'` show a
     **"Restore to scheduled"** item (icon e.g. `RotateCcw`) that
     `router.post('/checklists/runs/${run.id}/restore', {}, {preserveScroll:true,
     onSuccess:onClose})`; and gate the existing destructive items so a skipped
     run only offers Restore + View. (Keep the existing Skip hidden for already
     skipped runs — the current `showSkip` guard already does
     `!skipped`, ≈L182.)
4. **Test** (`ChecklistRunActionsTest`): skip → assert it appears in
   `forSite()['skippedRuns']`; restore → assert `status==='scheduled'` and gone
   from `skippedRuns`; restore requires `checklists.schedule` (support_worker →
   403).

---

## 5. P2 — Retire the orphaned legacy run pages

**Problem.** The new workspace never links to the full-page run flow — all run
interaction is `openRun(id)` → `RunModal`. The legacy pages
`resources/js/pages/sites/checklists/runs.tsx` (paginated index) and
`resources/js/pages/sites/checklists/runs/[id].tsx` (full run sheet) + their
controller methods (`SiteChecklistController` `runs` ≈L40, `showRun` ≈L60,
`startRun` ≈L109) are unreachable from the product UI (only by typing the URL).
Worse, `[id].tsx`/`showRun` only know `failure_creates_hazard` and have **no
`failure_creates_damage`** (≈`[id].tsx` L63, `showRun` payload ≈L96) — a
half-migrated surface (damage is still created server-side by
`raiseFollowUpsForFailures`, but this UI won't warn or flag it).

**Do (verify `git grep` for stragglers first):**
1. Delete `resources/js/pages/sites/checklists/runs.tsx` and
   `resources/js/pages/sites/checklists/runs/[id].tsx`.
2. Convert routes to redirects (mirror the house-checklists redirect at
   `routes/sites.php` ≈L155):
   - `GET /checklists/runs/{run}` (`showRun`, ≈L463) →
     `redirect('/sites/'.$run->site_id.'/checklists?run='.$run->id)`.
   - `GET /sites/{site}/checklists/runs` (`runs`, ≈L139) → redirect to
     `/sites/{site}/checklists`.
   - Drop the `POST …/runs/{run}/start` route (`startRun`, ≈L466) — nothing calls
     it (the new save path bumps `scheduled→in_progress` inside `saveResponse`).
3. Re-point `createRun` (≈L433/L448, currently `redirect()->route(
   'sites.checklists.showRun', …)`) to
   `redirect('/sites/'.$site->id.'/checklists?run='.$run->id)` so a started run
   opens in the modal on the full page. (Optional but recommended: make the
   workspace read `?run=` on mount — `workspace.tsx` `useState(runId)` seeded from
   `new URLSearchParams(location.search).get('run')` — so the deep-link actually
   opens the modal. If you skip this, the run still loads its `runDetail`; the user
   just clicks it.)
4. Remove the dead `runs`/`showRun`/`startRun` methods from
   `SiteChecklistController`.
5. **Tests:** `tests/Feature/SitesModuleIntegrationTest.php` has
   `test_checklist_run_detail_page_contract_is_valid` (≈L130) that asserts the
   `showRun` page — update it to assert the redirect (or remove it). `git grep
   showRun tests/` and fix any other references.

> Scope note: §5 is independent of §3/§4. If you want a tight first PR, ship §3 +
> §4 and do §5 as a follow-up.

---

## 6. P3 — Polish

### 6.1 Portal the right-click menu (correctness)
`context-menu.tsx` `FloatingMenu` uses `position:fixed` (good — escapes
`overflow:hidden`) but is rendered as a **child of the run card**, and
`WorklistCard` has `hover:-translate-y-0.5` (`run-cards.tsx` ≈L103). A transformed
ancestor re-roots `position:fixed`, so the menu can mis-position. Wrap the menu in
`createPortal(<div …/>, document.body)` (React 19 `react-dom`), or hoist
`{menu.element}` out of the card subtree. Keep all existing behaviour (Esc /
outside-click / scroll-close / arrow roving / `role=menu`).

### 6.2 Filter `assignableUsers` to active staff
`ChecklistsDashboardData::assignableUsers()` (≈L248–257) returns all org users
(capped 500). Add `->whereNotNull('approved_at')` (and/or an `is_active`/role
constraint if one exists) so the Reassign picker doesn't list unapproved/inactive
accounts. Org-scoping is already correct — no cross-tenant leak.

---

## 7. Backlog (optional — only if the owner asks)

- 🟡 **Reports weighting** — `ChecklistsDashboardData::reports()`
  (`complianceByCategory`/`trend`/`topFailures`) are simple aggregates; revisit
  weighting once there's real run history. (Empty-state copy already added.)
- 🟡 **Real photo capture** — `run-modal.tsx` `RunInput` for `response_type==='photo'`
  stores the literal string `'photo'` (≈L398–413). Wire to the media/upload
  pipeline + persist a real `photo_path` if photo evidence becomes required.
- 🟡 **Drag-to-reschedule** on the Schedule grid (`panes/schedule.tsx`) — stretch;
  the context-menu Reschedule already covers the need.

---

## 8. Verification checklist

1. `npx tsc --noEmit` → 0 errors; `npm run build` → exit 0.
2. Tests (non-parallel): new `SiteController` summary test (§3.4),
   `ChecklistRunActionsTest` incl. restore (§4.4), updated
   `SitesModuleIntegrationTest` (§5.5), and existing
   `tests/Feature/Checklists/ChecklistsDashboardTest.php` still green.
3. Push to `main`; after the webhook build, run any new migration (§4 adds none;
   none expected) — there are **no schema changes** in this plan.
4. Remote UI (logged in as `admin@demo.test`):
   - **Site profile → Checklists tab** shows a **compact summary + "Open full
     checklists page" button** — NO hero banner, NO 7 tabs. The button →
     `/sites/{id}/checklists` (the full workspace with hero).
   - `/checklists` and `/sites/{id}/checklists` still show the full hero +
     workspace (unchanged).
   - Schedule → right-click a run → **Skip**; confirm the run now appears in the
     **Runs** pane under "Skipped" and offers **Restore to scheduled**, which
     returns it to the schedule.
   - (If §5 shipped) `/checklists/runs/{id}` and `/sites/{id}/checklists/runs`
     redirect to the per-site page; the full-page run sheet is gone.

---

## 9. Key file / line index (verified at `cf2f8099`; re-grep before editing)

**§3 Profile de-dup:**
- `resources/js/pages/sites/show.tsx` — imports ≈L111–112; prop ≈L605;
  destructure ≈L861; tab trigger ≈L1079; **embedded workspace ≈L1876–1894**.
- `app/Http/Controllers/SiteController.php@show` — **eager build ≈L394–399**;
  return keys **≈L688–690**; `use ChecklistsDashboardData` ≈L33.
- Recover `buildSiteChecklistsSummary` from
  `git show cf2f8099^:app/Http/Controllers/SiteController.php` (≈L892–980).
- Markup to mirror: `panes/overview.tsx` `miniKpi` ≈L22–31; helpers
  `category.ts` `relDay` ≈L54 / `runStatusMeta` ≈L74.

**§4 Skipped runs:**
- `app/Support/ChecklistsDashboardData.php` — `activeRuns` ≈L100–119 (filter L101),
  `recentRuns` ≈L122–141 (filter L123); add `skippedRuns` near there.
- `resources/js/components/checklists/types.ts` (`ChecklistsData`, `PaneCtx` is in
  `context.tsx`); `workspace.tsx` ctx build ≈L85–96.
- `panes/runs.tsx` `STATUS_OPTIONS` ≈L9–15, `matchS` ≈L31–36.
- `context-menu.tsx` `showSkip` guard ≈L182; add Restore item near L177.
- `SiteChecklistController` (add `restoreRun` near `skipRun` ≈L229);
  `routes/sites.php` run block ≈L475–483.

**§5 Orphaned pages:**
- `resources/js/pages/sites/checklists/runs.tsx`,
  `resources/js/pages/sites/checklists/runs/[id].tsx` (no `failure_creates_damage`,
  ≈L63).
- `SiteChecklistController` `runs` ≈L40, `showRun` ≈L60, `startRun` ≈L109,
  `createRun` redirect ≈L433/L448; `routes/sites.php` ≈L139–140 & ≈L463–468.
- `tests/Feature/SitesModuleIntegrationTest.php` `test_checklist_run_detail_page_contract_is_valid` ≈L130.

**§6 Polish:**
- `context-menu.tsx` `FloatingMenu` ≈L30–82; `run-cards.tsx` `WorklistCard`
  transform ≈L103, `{menu.element}` ≈L85/L163.
- `ChecklistsDashboardData::assignableUsers` ≈L248–257.

**Reference (do NOT change — keep working):** `ChecklistsDashboardData` `runDetail`
≈L366–416 / `templateDetail` ≈L423–464 (still used by the two dedicated routes);
run-action endpoints all `back()` (`saveResponse`/`completeRun`/`rescheduleRun`/
`reassignRun`/`skipRun`).

---

## 10. Codex implementation notes - 2026-06-05

### Implemented scope
- Replaced the embedded full `ChecklistsWorkspace` on `sites/show.tsx` with a compact site-profile summary card fed by `SiteController::buildSiteChecklistsSummary()`.
- `SiteController@show` no longer builds or returns the full `checklistsData`, `runDetail`, or `templateDetail` payload for the site profile. It returns a permission-shaped `checklistsSummary` instead, and avoids leaking summary data when the viewer cannot access checklists.
- Added skipped-run support to the shared checklists workspace contract:
  - `ChecklistsDashboardData` now returns `skippedRuns`.
  - The Runs tab includes skipped runs and exposes them for restore.
  - The run context menu hides schedule/run actions for skipped runs and shows `Restore to scheduled`.
  - The context menu is portaled to `document.body` to avoid fixed-position drift inside transformed cards.
- Added `POST /checklists/runs/{run}/restore` with `checklists.schedule` middleware.
- Retired the orphaned standalone run pages:
  - Deleted `resources/js/pages/sites/checklists/runs.tsx`.
  - Deleted `resources/js/pages/sites/checklists/runs/[id].tsx`.
  - `GET /checklists/runs/{run}` now redirects to `/sites/{site}/checklists?run={run}`.
  - `GET /sites/{site}/checklists/runs` now redirects to `/sites/{site}/checklists`.
  - Removed the dead `runs`, `showRun`, and `startRun` controller methods.
- Updated `createRun()` so it deep-links into the unified workspace modal via `?run=`.
- The workspace now reads `?run=` on mount with an SSR-safe `typeof window` guard.
- Filtered checklist assignable users to approved users.

### Shift auto-run implementation
- Added `SiteChecklistScheduler::ensureRunsForShiftLocalDay(Shift $shift)`.
- The scheduler creates due checklist runs for the shift site and local shift start day, using `config('app.worker_timezone', 'Pacific/Auckland')`.
- Duplicate protection is per `assignment_id` + local `scheduled_date` using `firstOrCreate`.
- New runs inherit the shift assignee when present, otherwise the assignment assignee.
- Wired the scheduler into these shift creation paths:
  - `ShiftController@store`
  - `ShiftController@duplicate`
  - `CalendarController@storeShift`
  - `ShiftSeriesController@store`
- No schema changes or migrations were added.

### Verification
- `vendor\bin\pint.bat app/Http/Controllers/SiteController.php app/Http/Controllers/CalendarController.php app/Http/Controllers/ShiftSeriesController.php app/Http/Controllers/ShiftController.php tests/Feature/ShiftControllerTest.php` - passed.
- `php artisan test tests/Feature/ShiftControllerTest.php` - passed, 33 tests / 147 assertions.
- `php artisan test tests/Feature/SiteControllerTest.php --filter site_show_uses_compact_checklists_summary_not_full_workspace_payload` - passed, 1 test / 17 assertions.
- `php artisan test tests/Feature/Checklists/ChecklistsDashboardTest.php tests/Feature/Checklists/ChecklistRunActionsTest.php` - passed, 20 tests / 153 assertions.
- `php artisan test tests/Feature/SitesModuleIntegrationTest.php --filter legacy_checklist_run_pages_redirect_to_unified_workspace` - passed, 1 test / 4 assertions.
- `php artisan test tests/Feature/SitesModuleIntegrationTest.php --filter create_run_reuses_existing_unfinished_run` - passed, 1 test / 4 assertions.
- `git diff --check` - passed.
- `php artisan wayfinder:generate` - required before frontend type checks in this worktree; generated helpers are ignored by git.
- `npm run types` - passed after Wayfinder generation.
- `npm run build` - passed.
- `npx vite build --ssr` - passed.
