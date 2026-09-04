# Checklists — Follow-up Implementation Handoff (self-contained)

**Audience:** a fresh Claude Code context with NO memory of the redesign work.
Everything needed to implement the follow-ups is in this file. Read §1–§2 first
(context + environment), then implement §3–§6, then verify with §8.

> Status: ✅ done · 🟡 partial · ❌ todo · ⚠️ caveat

---

## 0. TL;DR — what to build

> **✅ IMPLEMENTED 2026-06-05** (working tree, tsc + build + feature tests green;
> not yet committed/deployed). Per-issue notes below; remaining work is the
> **server migrate + re-seed** and **remote UI verification** (§8) — neither can
> be done locally (Herd web PHP 8.3.29 < vendor's 8.4.1 → `.test` 500s).

1. **Icons** ✅ — Library category chips + the "All" chip now render the category
   glyph (`panes/library.tsx` `chip()` takes an `Icon`; `categoryIcon(c.icon)` /
   `LayoutGrid`, tinted by tone when inactive).
2. **Schedule right-click** ✅ — new `components/checklists/context-menu.tsx`
   (`useRunContextMenu`) wired onto Schedule chips + run cards: Start/Continue,
   Reschedule (date popover), Reassign (searchable staff list), Skip. Backed by
   the **3 new endpoints** + per-run `assigned_to_user_id` + `skipped` status meta.
3. **Site-profile Checklists tab** ✅ — embedded `ChecklistsWorkspace scope="site"`
   (chosen approach). `SiteController@show` builds `checklistsData` **eagerly**
   (lazy `Inertia::optional` would be dropped by the `back()` reloads every run
   action does) + surfaces top-level `runDetail`/`templateDetail`. Old
   `SiteChecklistsTab` + `pages/checklists/_dialogs.tsx` deleted; `completeRun`
   now `back()` for modal coherence.
4. **Retire house-checklists** ✅ — GET redirects to the per-site page; controller
   + page + write routes deleted. Damage-reporting folded in via a new
   `failure_creates_damage` item flag → `SiteDamage` (FK'd to the run).
5. **Hazard gap (found during the audit, not in the original plan)** ✅ — the run
   modal advertised "failed checks raise a hazard" but the controller never
   created one (`create_hazard` was validated and ignored). `saveResponse` /
   `completeRun` now spawn `SiteHazard` **and** `SiteDamage` for flagged failures,
   idempotently via `responses.created_hazard_id` / `created_damage_id`.
6. **Re-run the seeder on the remote** ⚠️ — still required (see §2); also run the
   two new migrations (`migrate --force`).

Architectural choices already approved by the user: **embed the new workspace**
in the site profile (modernise-only is the fallback), **right-click on the
current Schedule grid**, **retire house-checklists**.

---

## 1. Context — the rebuilt Checklists module

One system, two routes, one frontend workspace, one backend data builder. **Never
fork the logic — scope by `site_id`.**

| Surface | Route | Controller | Inertia page |
|---|---|---|---|
| Org dashboard | `GET /checklists` | `Sites\ChecklistsDashboardController@index` | `checklists/index` |
| Per-site tab | `GET /sites/{site}/checklists` | `Sites\SiteChecklistController@index` | `sites/checklists/index` |

Both pages are thin wrappers that render
`resources/js/components/checklists/workspace.tsx` →
`<ChecklistsWorkspace scope={...} data={...} />`, fed by
`app/Support/ChecklistsDashboardData.php`.

### 1.1 Frontend file map — `resources/js/components/checklists/`
- `types.ts` — the **data contract** (`ChecklistsData`) and all TS types.
- `category.ts` — `catColorVar(tone)`/`catBgVar(tone)` (→ `var(--category-*)` /
  `--status-*`), `initials`, `fmtDate`, `relDay`, `runStatusMeta`.
- `icons.ts` — `CATEGORY_ICONS` registry + `categoryIcon(name)` (lucide).
- `context.tsx` — `ChecklistConfig` context (`categories, categoryMap,
  freqLabels, typeLabels, today, can, scope, openRun, openBuilder`) + `PaneCtx`
  (the scoped/filtered view handed to panes) + `freqLabel`/`typeLabel` helpers.
- `primitives.tsx` — `Dropdown` (floating/searchable), `ViewToggle` (Board/List),
  `CategoryIcon`, `CategoryDot`, `StatusBadge`, `CountBadge`, `Progress`,
  `Empty`, `SectionHead`, `SearchInput`.
- `charts.tsx` — `Donut`, `SegmentDonut`, `Sparkline`, `MiniRing`, `LegendDot`.
- `hero.tsx` — `ChecklistHero` → renders the real `PageHero` with
  **`category="ops"`** (banner colour = the Settings→Appearance accent, matching
  the Roster hero).
- `hero-footer.tsx` — `HeroFooter`: week stepper, the shared **`WeekPicker`**
  popover, search box, category `Dropdown`, org/site scope links.
- `run-cards.tsx` — `RunListRow`, `WorklistCard` (used by Due-now / Runs).
- `run-modal.tsx` — `RunModal` (centre modal; loads `runDetail` via `?run=`
  partial reload; submits via Inertia `router.post`).
- `template-builder.tsx` — `TemplateBuilderModal` (create/edit/delete; loads
  `templateDetail` via `?template=`; built to `design_styles/POPUP_STYLE_GUIDE.md`).
- `assign-checklist.tsx` — `AssignChecklistButton` (site-scoped).
- `workspace.tsx` — `ChecklistsWorkspace` orchestrator (tab/week/search/cat/
  runId/builderTarget state; renders hero + Rostering `TabStrip` + active pane +
  modals).
- `panes/` — `overview.tsx`, `due-now.tsx`, `runs.tsx`, `schedule.tsx`,
  `library.tsx`, `assignments.tsx`, `reports.tsx`.

Pages: `resources/js/pages/checklists/index.tsx` (org),
`resources/js/pages/sites/checklists/index.tsx` (site).

### 1.2 Backend
- `app/Support/ChecklistsDashboardData.php` — `forOrg()` / `forSite(Site)` return
  the contract. Also computes `stats`, `reports`, and (when the request has the
  query param) `runDetail` (`?run=`) and `templateDetail` (`?template=`) for the
  modals via Inertia partial reloads.
- `app/Http/Controllers/Sites/ChecklistsDashboardController.php@index` → `forOrg`.
- `app/Http/Controllers/Sites/SiteChecklistController.php` — `@index` → `forSite`;
  plus `runs`, `showRun`, `startRun`, `saveResponse` (bumps scheduled→in_progress),
  `completeRun`, `assignChecklist`, `removeAssignment`, `createRun`.
- `app/Http/Controllers/Sites/SiteChecklistTemplateController.php` — `store` /
  `update` (combined template+items **upsert**, return `back()`) / `destroy`.
  Standalone template index/create/edit pages were **retired**.
- `config/checklists.php` — taxonomy: `categories` (9 × `key/label/short/icon/
  tone/blurb`), `frequency_labels`, `type_labels`. Single source of truth.
- Models: `SiteChecklistTemplate` (`category`, `settings` json), 
  `SiteChecklistTemplateItem` (`failure_creates_hazard`, `responses()` relation),
  `SiteChecklistAssignment`, `SiteChecklistRun`, `SiteChecklistResponse`.

### 1.3 Data contract (`ChecklistsData`, from `types.ts`)
```
categories: Category[]                 // {key,label,short,icon,tone,blurb}
frequencyLabels / typeLabels: Record<string,string>
today: string                          // server date 'YYYY-MM-DD'
templates: ChecklistTemplate[]         // +category, flags{hazard,photo,sign}, spotlight, items_count, assignments_count
activeRuns / recentRuns: ChecklistRun[]// run +template{category,flags}, site, assignee, status, scheduled_date, pct, is_overdue
assignments: ChecklistAssignment[]     // +template(category), assignee, frequency, site
sitesOverview: SiteOverview[]          // org only: per-site counts + on_track_rate
reports: { complianceByCategory[], trend[8], topFailures[] }
stats: { onTrack, overdue, dueToday, inProgress, scheduled, completed30, failures }
runDetail: RunDetail | null            // when ?run=
templateDetail: TemplateDetail | null  // when ?template=
can: { view, manageTemplates, schedule, run }
```

### 1.4 Current run/template routes (`routes/sites.php`)
- `GET /checklists/runs/{run}` → `sites.checklists.showRun` (full [id] page).
- `POST /checklists/runs/{run}/start` → `startRun` (⚠️ redirects to the [id] page).
- `POST /checklists/runs/{run}/responses` → `saveResponse` (`back()`).
- `POST /checklists/runs/{run}/complete` → `completeRun` (redirects to per-site index).
- `POST /sites/{site}/checklists/assignments/{assignment}/run` → `createRun`.
- `POST /sites/checklists/templates` → `store`; `PUT|DELETE
  /sites/checklists/templates/{template}` → `update`/`destroy` (all `back()`).
- Permissions: `checklists.view` / `.run` / `.schedule` / `.manage_templates`.
  The `admin` role has all; `support_worker` has none of checklists.

---

## 2. Environment & workflow (read before deploying)

- **Remote** `https://oblivionfindings.com` (web SAPI **php8.5-fpm** — fine).
  Deploy = push to `main`; a webhook auto-pulls **and** auto-builds (`npm ci` +
  `vite build`, ~5–8 min). Poll until `public/build/manifest.json` is fresh and
  no `vite build` process is running, then verify.
- **Migrations & seeders are MANUAL on the server** (the webhook skips them):
  `php artisan migrate --force` and
  `php artisan db:seed --class=SitesModuleSeeder --force`.
- ⚠️ **Seeder not currently applied on the remote** — live Library shows only
  **5 uncategorised templates** ("All 5") though the full library is ~40/9-cat.
  Run the seeder and confirm it persists across any nightly demo reset.
- ⚠️ **NZ timezone (UTC+12/13):** never use `toISOString().slice(0,10)` for week
  math — it shifts the day back. Use the local helpers `ymd`/`startOfWeek`/
  `addDaysWP` from `@/components/rostering/week-picker`.
- ⚠️ **Local Herd web PHP is 8.3.29** but `vendor/` needs ≥8.4.1, so
  `oblivionfindings.test` 500s on every route — **local browser verification is
  impossible**. CLI php is ≥8.4 (so `artisan migrate`/`db:seed`/`test` work).
  Verify UI on the **remote** via the connected Chrome ("Browser 1"), logged in
  as `admin@demo.test`. Benign console noise to ignore: "A listener indicated an
  asynchronous response… message channel closed" (browser-extension artifact).
- ⚠️ **Shared checkout** — another agent may be in the repo. Before committing:
  `git branch --show-current` (expect `main`), **stage explicit paths** (never
  `git add -A`), commit with the `Co-Authored-By: Claude Opus 4.8` trailer.
- Hard line: do **not** SSH with a password or submit login forms — ask the user
  to run server commands / log in.
- Verify locally before pushing: `npx tsc --noEmit` (0 errors) and `npm run build`.

---

## 3. Issue A — Category icons ❌

**Problem:** `panes/library.tsx` `chip()` (≈L182–197) renders only `{label}` +
count — no icon, no colour on inactive chips. The icon registry is fine; all 9
config icon names resolve in `lucide-react@0.475.0`.

**Do:**
1. In `panes/library.tsx`, add a leading icon to each chip: resolve via
   `categoryIcon(c.icon)` from `../icons` for category chips, and `LayoutGrid`
   for the "All" chip. Tint the icon with `catColorVar(c.tone)` when inactive and
   `primary-foreground` when active. Pass the icon component through the `chip()`
   helper (add an `Icon?` param).
2. Re-seed (§2), then **visually verify `CategoryIcon` tiles** render the real
   glyph (not the `ClipboardList` fallback) on: Library cards/rows, run cards,
   run-modal header, Overview "On-track by category" rings, Assignments rows.
3. If any tile shows the fallback, fix the **name** in `config/checklists.php`
   (must match a lucide export exactly, case-sensitive).

Files: `panes/library.tsx`, `icons.ts`, `config/checklists.php`.

---

## 4. Issue B — Schedule right-click + run context menu ❌

**Chosen approach:** add a floating context menu to the **existing** Schedule
grid (`panes/schedule.tsx`) and the run cards (`run-cards.tsx`) — do NOT try to
embed the full site-calendar engine (its grid is reusable but its right-click
`QuickAddMenu` is event-creation-specific; reference only:
`pages/sites/calendar/SiteCalendar.tsx` `QuickAddMenu` ≈L3225–3386, `onContext`
≈L876–881, and `_parts.tsx` views).

**Frontend:**
1. Build a generic floating context-menu primitive (model it on the calendar's
   `QuickAddMenu` mechanics: fixed `x/y`, viewport-edge repositioning, close on
   outside-click/Esc/scroll, roving-focus keyboard). Put it in
   `components/checklists/context-menu.tsx` (or promote to `components/ui/`).
2. Wire `onContextMenu` on Schedule run chips (`panes/schedule.tsx`) and on
   `RunListRow`/`WorklistCard` (`run-cards.tsx`) → open the menu with items:
   **Start/Continue** (`openRun(id)` from context), **View** (`openRun(id)`),
   **Reschedule**, **Reassign**, **Skip**.
3. Only show actions whose backend exists. Per the "hide unbuilt actions" rule,
   gate Reschedule/Reassign on `can.schedule`, Skip on `can.run`, and ship them
   only once §4 backend lands. Wire each via Inertia (`router.patch`/`router.post`,
   `preserveScroll: true`).
   - Reschedule → a small date popover (reuse `WeekPicker` or a date input) →
     `PATCH /checklists/runs/{run}/schedule`.
   - Reassign → a searchable user `Dropdown` → `PATCH /checklists/runs/{run}/assign`.
   - Skip → confirm → `POST /checklists/runs/{run}/skip`.

**Backend (new — none exist today):**
1. **Migration:** add nullable `assigned_to_user_id` (foreignId, nullOnDelete) to
   `site_checklist_runs` so a single run can be reassigned independently of its
   assignment.
2. **`SiteChecklistController`** methods (authorize `update`/`view` on `$run->site`,
   return `redirect()->back()`):
   - `rescheduleRun(Request, Run)` — validate `scheduled_date` (date) → update.
   - `reassignRun(Request, Run)` — validate `assigned_to_user_id`
     (`nullable|exists:users,id`) → update.
   - `skipRun(Request, Run)` — set `status = 'skipped'`.
3. **Routes** (`routes/sites.php`, checklists block, `permission:checklists.schedule`
   for reschedule/reassign, `checklists.run` for skip):
   - `PATCH /checklists/runs/{run}/schedule` → `sites.checklists.rescheduleRun`
   - `PATCH /checklists/runs/{run}/assign`   → `sites.checklists.reassignRun`
   - `POST  /checklists/runs/{run}/skip`     → `sites.checklists.skipRun`
4. **`ChecklistsDashboardData`** — set run `assignee` =
   `run.assignedTo?->name ?? run.assignment?->assignedTo?->name ?? 'Unassigned'`
   (load the new `assignedTo` relation on `SiteChecklistRun`); add a `skipped`
   tone/label in `runStatusMeta` (`category.ts`).
5. Feature tests for each endpoint (auth gate + effect). Run non-parallel:
   `php artisan test --filter=...` (NOT `--parallel` — per-worker DBs aren't
   migrated here).

---

## 5. Issue C — Site-profile Checklists tab 🟡

**Problem:** `resources/js/pages/sites/show.tsx` Checklists tab (def ≈L1658)
renders a bespoke **pre-redesign** `SiteChecklistsTab` (≈L883–1411) using the old
`CreateTemplateDialog` (lazy from `@/pages/checklists/_dialogs`, ≈L262 — creates
**uncategorised** templates) and an old `StartRunDialog`. Links target the new
routes, but the experience is the old one. Data is built server-side by
`SiteController@show` → `buildSiteChecklistsSummary($site, $user)` (`checklistsSummary`
prop, ≈L680).

**Chosen approach: embed the new workspace (full parity).**
1. Backend: in `SiteController@show`, replace (or augment) `checklistsSummary`
   with the full per-site payload: `(new \App\Support\ChecklistsDashboardData(
   $request))->forSite($site)` passed as a prop (e.g. `checklistsData`). The
   `?run=`/`?template=` partial reloads must work on the profile route too —
   `forSite` already reads those query params, so they will.
2. Frontend: in `show.tsx`, replace `<SiteChecklistsTab .../>` in the Checklists
   `TabsContent` with
   `<ChecklistsWorkspace scope={{ mode:'site', site, backHref:'/sites/'+site.id }}
   data={checklistsData} />`. Delete `SiteChecklistsTab` and the old
   `CreateTemplateDialog`/`StartRunDialog` imports/usages from `show.tsx`.
3. Caveat: `show.tsx` is large and the workspace renders its own hero. If a full
   in-tab hero is too heavy, use the **fallback** below.

**Fallback (lighter): modernise the summary.** Keep a compact summary tab but
(a) swap `CreateTemplateDialog` → `TemplateBuilderModal`
(`components/checklists/template-builder.tsx`), (b) swap the old start dialog →
the new `RunModal` (or deep-link to `/sites/{id}/checklists?run=`), (c) route
"Assign" through `AssignChecklistButton`. Net: no uncategorised templates, no old
dialogs.

Either way, once `pages/checklists/_dialogs.tsx` has no importers, delete it.

---

## 6. Issue D — Retire house-checklists 🟡

Legacy parallel system: `GET /sites/{site}/house-checklists` →
`HouseChecklistController` → `pages/sites/checklists/house-index.tsx` (per-site
templates, run/complete, **damage reporting**).

**Chosen approach: retire.**
1. Grep the whole `resources/js` for `house-checklists` links and remove/redirect
   them.
2. Make `GET /sites/{site}/house-checklists` redirect to
   `/sites/{site}/checklists` (keep the route briefly as a redirect, or delete it
   and the page).
3. **Preserve the unique bit:** house-checklists can spawn a `SiteDamage` from a
   failed item. The new run modal already raises a `SiteHazard` on a flagged fail
   (`responses.created_hazard_id`); add the **damage** path too (failed condition
   item with the right flag → `SiteDamage` FK'd to `checklist_run_id`) so nothing
   is lost. Then delete `HouseChecklistController` + `house-index.tsx` + routes.
4. Feature-test that the redirect works and damage creation still functions.

---

## 7. Outstanding backlog (everything else)

- ⚠️ **Re-seed remote** + ensure persistence across demo resets (§2).
- ❌ Library chip icons (§3); recheck `CategoryIcon` tiles post-seed.
- ❌ Schedule right-click + run context menu (§4) + reschedule/reassign/skip
  endpoints + per-run `assigned_to_user_id`.
- 🟡 Site-profile tab → new workspace (§5); delete `pages/checklists/_dialogs.tsx`.
- 🟡 Retire/redirect house-checklists; fold in damage reporting (§6).
- 🟡 **Reports realism** — `complianceByCategory`/`trend`/`topFailures` are simple
  aggregates in `ChecklistsDashboardData::reports()`; revisit weighting + add
  empty-state copy once real run history exists.
- 🟡 **Run-modal photo capture** is a placeholder toggle (stores the string
  `'photo'`), not a real upload — wire to the media pipeline if photo evidence is
  required.
- 🟡 **Drag-to-reschedule** on the Schedule grid (stretch; depends on §4).
- 🟡 **Per-site Library** shows the full global catalog; consider filtering to the
  site's `applicable_to_type`.
- 🟡 **Completed-run navigation** — `completeRun` redirects to the per-site index;
  fine, but the run modal closing already reloads. Confirm UX is coherent when
  completing from the org dashboard.
- ⚠️ **Pre-existing, unrelated failing test:** `SitesModuleIntegrationTest > sites
  global calendar route renders` (asserts an `events` prop the `calendar/global`
  controller doesn't pass). Not part of this work — leave or fix separately.

---

## 8. Verification checklist

1. `npx tsc --noEmit` → 0 errors; `npm run build` → exit 0.
2. `php artisan test --filter=ChecklistsDashboardTest` (+ any new tests) green,
   non-parallel.
3. Push to `main` (staged paths only), wait for the webhook build, then on the
   server: `php artisan migrate --force` + `php artisan db:seed --class=SitesModuleSeeder --force`.
4. Remote UI (Chrome MCP, `admin@demo.test`):
   - Library chips show category icons; cards/tiles show correct glyphs (post-seed).
   - Schedule tab: right-click a run → menu; Reschedule/Reassign/Skip work and
     reflect on reload.
   - `/sites/{id}/checklists` AND the **site profile → Checklists tab** both show
     the new workspace (banner = ops accent, 7 tabs, builder modal, run modal).
   - `/sites/{id}/house-checklists` redirects to the new page.
   - Banner week button opens the `WeekPicker` and shows the correct (NZ) week.

---

## 9. Key reference paths

- Module: `resources/js/components/checklists/**`, pages
  `resources/js/pages/checklists/index.tsx`, `resources/js/pages/sites/checklists/index.tsx`.
- Backend: `app/Support/ChecklistsDashboardData.php`,
  `app/Http/Controllers/Sites/{ChecklistsDashboardController,SiteChecklistController,SiteChecklistTemplateController}.php`,
  `config/checklists.php`, `routes/sites.php`, models `app/Models/SiteChecklist*.php`.
- Reuse: `@/components/rostering/week-picker` (WeekPicker + `ymd`/`startOfWeek`/
  `addDaysWP`), `@/components/rostering/tab-strip` (TabStrip),
  `@/components/page` (PageHero), `design_styles/POPUP_STYLE_GUIDE.md`.
- Site calendar (right-click reference only): `resources/js/pages/sites/calendar/{SiteCalendar,_parts}.tsx`.
- Site profile: `resources/js/pages/sites/show.tsx`, `app/Http/Controllers/SiteController.php@show` (`buildSiteChecklistsSummary`).
- House checklists (to retire): `app/Http/Controllers/Sites/HouseChecklistController.php`,
  `resources/js/pages/sites/checklists/house-index.tsx`, routes in `routes/sites.php`.
- Tests: `tests/Feature/Checklists/ChecklistsDashboardTest.php` (pattern to copy).
