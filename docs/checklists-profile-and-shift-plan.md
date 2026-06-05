# Checklists — Profile functional tab + Checklists on the shift (2 PRs)

**Audience:** a fresh implementer (Codex). Self-contained. Read §A (shared context
+ environment), then ship **PR 1** and **PR 2**. Each PR is independent and can be a
separate branch/PR off `main`.

## PR overview & sequence

| PR | Goal | Touches | Risk |
|---|---|---|---|
| **PR 1 — Profile functional tab** | The site-profile Checklists tab embeds the *functional* workspace (see + **complete** runs there) but **without** the duplicated hero banner — compact light header + "Open full checklists page" button. | `SiteController@show`, `sites/show.tsx`, `workspace.tsx`, new `embedded-header.tsx` | Low |
| **PR 2 — Checklists on the shift** | A due checklist shows on **My Day** for every shift at that site that day, and a rostered worker can **complete it inline**; it stays until someone finishes it. | `RbacSeeder` (perms), `SiteChecklistController` (authz), `MyTasksController`, `my-day/*`, extract `RunDetailPresenter` | Med (security-sensitive perms) |

**Recommended order: PR 1 first** (smaller, fixes the owner's immediate "it just
redirects" complaint, no permission changes), then **PR 2** (bigger, changes seeded
permissions). They are nearly orthogonal (PR 1 touches the profile/workspace; PR 2
touches My Day + permissions) — only `ChecklistsDashboardData.php` is shared, and only
if PR 2 extracts `runDetail` (see PR 2 §2.3); do PR 1 first to avoid a refactor race.

> Supersedes the **profile-tab** part of `docs/checklists-profile-dedup-and-followups-plan.md`
> (commit `61cc4c6f`), which over-corrected the profile tab to a read-only summary that
> just links out. Its §4/§5/§6 (skipped-run fix, legacy-page retire, polish) were
> implemented by Codex (`0f3635e9`) and are **done** — do not redo them.

> Status legend: ✅ done · 🟡 partial · ❌ todo · ⚠️ caveat. Lines verified on `main`
> at `0f3635e9`; re-grep before editing.

---

## §A. Shared context & environment (read once, applies to both PRs)

**The module.** One system, surfaces share `resources/js/components/checklists/workspace.tsx`
(`ChecklistsWorkspace`) fed by `app/Support/ChecklistsDashboardData.php`
(`forOrg()`/`forSite(Site)` → the `ChecklistsData` contract in `…/checklists/types.ts`).
Run actions live on `SiteChecklistController` (`saveResponse`, `completeRun`,
`rescheduleRun`, `reassignRun`, `skipRun`, `restoreRun`) — **all return
`redirect()->back()`**. The full-page surfaces (`/checklists`,
`/sites/{site}/checklists`) render the big `ChecklistHero` banner — **leave those
unchanged**; both PRs only change embeds (profile tab, My Day).

**Environment / gotchas:**
- Deploy = push to `main`; webhook auto-builds (~5–8 min). **Migrations & seeders are
  MANUAL on the server.** PR 1 needs neither. **PR 2 re-seeds permissions** → after
  deploy run `php artisan db:seed --class=RbacSeeder --force` (permissions are seeded,
  not migrated). No schema changes in either PR.
- ⚠️ **`back()` ⇒ full Inertia reload of the current page.** Any data an embed needs
  must be a **normal eager prop**, never `Inertia::optional` (an optional prop is
  dropped on a full `back()` reload → the embed blanks mid-flow). This is why both the
  profile (`checklistsData`) and My Day (`shiftChecklists`/`checklistConfig`/`runDetail`)
  payloads are eager.
- ⚠️ **NZ timezone:** derive "today"/week math via local helpers
  (`ymd`/`startOfWeek`/`addDaysWP` from `@/components/rostering/week-picker`) on the
  client and `config('app.worker_timezone','Pacific/Auckland')` on the server — never
  `toISOString().slice(0,10)` / UTC.
- ⚠️ **Local browser verification is impossible** (Herd web PHP 8.3.29 < vendor ≥8.4.1
  → `.test` 500s). CLI php ≥8.4 → `artisan test` works. Verify UI on the **remote**.
  Tests **non-parallel** (`--filter=…`).
- ⚠️ **Shared checkout** — stage explicit paths (never `git add -A`); commit with
  `Co-Authored-By: Claude Opus 4.8`. Pre-push: `npx tsc --noEmit` (0) + `npm run build`
  (exit 0).
- Reusable components (`RunModal`, `RunListRow`, `CategoryIcon`, `StatusBadge`, …) call
  `useChecklistConfig()` and **throw outside `<ChecklistConfigProvider>`** — both PRs
  render inside a provider.

---

## PR 1 — Profile Checklists tab: functional workspace, no hero banner

**History/why:** v1 embedded the full workspace **with** the hero → owner: "the banner
is a duplicated page." A prior plan over-corrected to a read-only summary card that
`<Link>`s to the full page (shipped in `0f3635e9`) → owner: "I wanted the actual
checklists IN the tab and to **complete** runs in the profile — now it just redirects."
**This PR:** keep the full *functional* workspace in the tab, drop only the hero banner,
replace it with a compact light header + an **"Open full checklists page"** button.

### 1.1 Current state (what to change)
- `resources/js/pages/sites/show.tsx`: local `SiteChecklistsSummary*` types ≈L367–388;
  prop `checklistsSummary?` ≈L628; destructure ≈L1053; helper components
  `checklistDateLabel`/`checklistStatusClass`/`SiteChecklistKpi`/`SiteChecklistRunRow`/
  `SiteChecklistsSummaryCard` ≈L857–1015; **rendered at ≈L2069–2070**.
- `app/Http/Controllers/SiteController.php@show`: `'checklistsSummary' =>
  $this->buildSiteChecklistsSummary(...)` ≈L681; lightweight method ≈L1158 (no
  `runDetail`/`templateDetail`, so no in-profile completion).
- `resources/js/components/checklists/workspace.tsx`: always renders `<ChecklistHero>`
  ≈L207–233; already seeds `runId` from `?run=` ≈L79–84 and threads `skippedRuns`
  ≈L115 — keep both.

### 1.2 Backend — `SiteController@show`
Restore the **eager** payload (copy from `git show
cf2f8099:app/Http/Controllers/SiteController.php` — build ≈L399, props ≈L688–690):
1. Before the `return inertia('sites/show', [ … ])`, add
   `$checklistsData = (new \App\Support\ChecklistsDashboardData($request))->forSite($site);`
   (ensure `use App\Support\ChecklistsDashboardData;`).
2. Replace the `'checklistsSummary' => …` prop with:
   ```php
   'checklistsData'  => $checklistsData,
   'runDetail'       => $checklistsData['runDetail'],
   'templateDetail'  => $checklistsData['templateDetail'],
   ```
3. **Delete** `buildSiteChecklistsSummary` (≈L1158) and its `SiteChecklistsSummary`
   PHPDoc. `runDetail`/`templateDetail` are what the run-modal + builder partial reloads
   read (`router.reload({only:['runDetail'], data:{run}})`) — required for in-tab
   completion.

### 1.3 Frontend — `sites/show.tsx`
1. **Delete** the summary types (≈L367–388) and helper components/card (≈L857–1015).
2. **Re-add** `import { ChecklistsWorkspace } from '@/components/checklists/workspace';`
   and `import type { ChecklistsData } from '@/components/checklists/types';`.
3. Prop `checklistsSummary?: SiteChecklistsSummary` (≈L628) → `checklistsData?:
   ChecklistsData`; update destructure (≈L1053).
4. Render (≈L2069–2070):
   ```tsx
   <TabsContent value="checklists">
     {checklistsData ? (
       <ChecklistsWorkspace
         scope={{ mode: 'site', site: { id: site.id, name: site.name, type: site.type }, backHref: `/sites/${site.id}` }}
         data={checklistsData}
         embedded
       />
     ) : (
       <Card><CardContent className="py-12 text-center text-sm text-muted-foreground">Loading…</CardContent></Card>
     )}
   </TabsContent>
   ```

### 1.4 Workspace — `workspace.tsx`
1. Add `embedded?: boolean` prop (default `false`).
2. Replace the hero block (≈L207–233) with a conditional: when `embedded`, render the
   new `<ChecklistsEmbeddedHeader …/>` (§1.5) passing `stats`, the `site`, `fullHref =
   scope.mode==='site' ? `/sites/${scope.site.id}/checklists` : '/checklists'`, the
   week props (`week`, `onPrevWeek`, `onNextWeek`, `selectedWeekStart`, `today`,
   `onJumpToWeek`), `query`/`onQuery`, `cat`/`onCat`, `onStart` (`()=>setTab('due')`),
   `onNewTemplate` (`()=>setBuilderTarget('new')`); else render `<ChecklistHero …>`
   unchanged. Keep `TabStrip`, all panes, `RunModal`, `TemplateBuilderModal`.

### 1.5 New compact header — `resources/js/components/checklists/embedded-header.tsx`
A **light** header (NOT the dark gradient banner). Build from light primitives so you
don't restyle the dark `hero-footer.tsx`:
- A `rounded-xl border bg-card p-3` block, two rows.
- **Row 1:** left = an icon tile + "Checklists" + site name + small stat chips
  (On-track `${stats.onTrack}%`, Due today `${stats.dueToday}`, Overdue `${stats.overdue}`)
  via `StatusBadge` (overdue→`critical` when >0, due→`warning`). Right = **`<Button asChild>
  <Link href={fullHref}>Open full checklists page <ArrowUpRight/></Link></Button>`**, then
  (from `useChecklistConfig().can`) `can.run`→"Start a checklist" (`onStart`),
  `can.manageTemplates`→"New template" (`onNewTemplate`).
- **Row 2 (light controls):** mirror `hero-footer.tsx` (week stepper ≈L82–127, catOptions
  ≈L62–65, search ≈L131–148) but light: a week prev/next + centre week button (ref) +
  `<WeekPicker showContextMenu={false}/>`; `<SearchInput value={query} onChange={onQuery}
  className="md:w-56"/>` (from `./primitives`, already light); `<Dropdown value={cat}
  onChange={onCat} options={catOptions}/>` (from `./primitives`, **no `dark` prop** →
  light). **Omit** the org/site scope segment + "Jump to a site" dropdown — the
  "Open full checklists page" button replaces them.
- Use `useChecklistConfig()` for `categories`/`can` (always inside the provider here).

### 1.6 How completion works in-profile (no extra code)
Click a run → `openRun(id)` → `RunModal` → `router.reload({only:['runDetail'],
data:{run}})` re-runs `show()` → returns top-level `runDetail` → items render → Complete
posts `…/complete` (`back()`) → `/sites/{site}` reloads → eager `checklistsData` refreshes
→ run moves to history. Hazard/damage folding + right-click actions all unchanged.

### 1.7 PR 1 tests & verify
- `tests/Feature/SiteControllerTest.php`: Codex added an assertion that the profile has
  `checklistsSummary` and is `missing('checklistsData')` — **reverse it**: assert
  `->has('checklistsData.stats')`, `->missing('checklistsSummary')`, `->has('runDetail')`.
- `tsc --noEmit` 0 · `npm run build` 0 · `--filter=SiteControllerTest`,
  `--filter=ChecklistsDashboardTest`, `--filter=ChecklistRunActionsTest` green.
- Remote: profile → Checklists tab shows the functional workspace with a **compact light
  header (no big banner)** + "Open full checklists page"; **open a run from the tab and
  complete it without leaving the profile**; `/checklists` + `/sites/{id}/checklists`
  unchanged (full hero).

---

## PR 2 — Checklists ON the shift (My Day), worker-completable

**Owner requirement:** a **due** checklist appears **on the shift** and **stays until
someone completes it**; with 3 shifts at a site that day it shows on **all** of them and
clears once **any** worker completes it. Locked decisions: **shared by `(site, day)`**
(not per assigned worker); **complete inline on My Day** via the run modal.

**Current state (half-built):** Codex's `SiteChecklistScheduler::ensureRunsForShiftLocalDay`
(called from Shift/Calendar/ShiftSeries store/duplicate) idempotently materialises the
day's run — **keep it** (there is NO scheduled run-generation: `generateUpcomingRuns()`
isn't on cron; `ChecklistDueJob` only notifies). But **nothing displays runs on a shift**
(`Shift` has no checklist rel; My Day/My Tasks/Roster don't read `SiteChecklistRun`), and
**workers can't complete them** (two permission walls, §2.1).

### 2.1 Permissions & authorization (DO FIRST — security-sensitive)
1. **`database/seeders/RbacSeeder.php`** — in the **Support Worker**
   `$syncPermissions($supportWorker, [...])` block (≈L551) add `'checklists.view'` and
   `'checklists.run'` (NOT `schedule`/`manage_templates`). (Perm defs already exist
   ≈L396–399.)
2. **`app/Http/Controllers/Sites/SiteChecklistController.php`** — relax the two
   **execution** methods so a rostered worker (not a manager) can complete:
   - `saveResponse` (≈L124) and `completeRun` (≈L156): `authorize('update', $run->site)`
     → `authorize('view', $run->site)`. The routes already enforce
     `permission:checklists.run` (the action gate); `authorize('view')` ensures site
     access (`SitePolicy@view` = `sites.viewAny` + type + assigned-site, which a rostered
     support worker passes). **Do NOT** relax `rescheduleRun`/`reassignRun`/`skipRun`/
     `restoreRun` (keep `authorize('update')` — management).
   - Re-seed on the server after deploy: `php artisan db:seed --class=RbacSeeder --force`.

### 2.2 My Day backend — `app/Http/Controllers/MyTasksController.php`
Render is `Inertia::render('my-day/index', [...])` ≈L135 with `active_shift` (card
`$shiftToCard` ≈L325, site payload ≈L228). Add three eager props:
1. **`shiftChecklists`** — the shared `(site, day, open)` query for the active shift's
   site (empty when off-shift):
   ```php
   $tz = config('app.worker_timezone', 'Pacific/Auckland');
   $today = now($tz)->toDateString();
   $siteId = $activeShift?->site_id;
   $shiftChecklists = $siteId ? SiteChecklistRun::query()
       ->where('site_id', $siteId)
       ->whereIn('status', ['scheduled', 'in_progress'])        // open → drops off on completion
       ->whereDate('scheduled_date', '<=', $today)               // due today + carried-over overdue
       ->with(['template:id,name,frequency'])
       ->orderBy('scheduled_date')->limit(50)->get()
       ->map(fn ($run) => [
           'id' => $run->id, 'status' => $run->status,
           'scheduled_date' => $run->scheduled_date?->toDateString(),
           'is_overdue' => $run->scheduled_date && $run->scheduled_date->lt($today),
           'pct' => (int) round((float) $run->completion_percentage),
           'template' => $run->template ? ['id'=>$run->template->id,'name'=>$run->template->name,'frequency'=>$run->template->frequency,'category'=>$run->template->category] : null,
       ])->values()->all() : [];
   ```
   → `'shiftChecklists' => $shiftChecklists`.
2. **`checklistConfig`** (so `RunModal` can render outside the workspace):
   `['categories'=>config('checklists.categories'),'frequencyLabels'=>config('checklists.frequency_labels'),'typeLabels'=>config('checklists.type_labels'),'today'=>$today,'can'=>['view'=>(bool)$user?->canDo('checklists.view'),'run'=>(bool)$user?->canDo('checklists.run')]]`.
3. **`runDetail`** (top-level, eager, for the modal's `only:['runDetail']` reload):
   **Extract** `ChecklistsDashboardData::runDetail()` (≈L366–416) into a shared
   `App\Support\RunDetailPresenter::for(?int $runId, ?int $siteId): ?array` and call it
   from both `ChecklistsDashboardData` and here, scoped to the active shift's `site_id`:
   `'runDetail' => RunDetailPresenter::for($request->integer('run'), $siteId)`.

### 2.3 My Day frontend — `resources/js/pages/my-day/index.tsx` (+ `lib/types.ts`)
The page reads `props.active_shift` (≈L149) and already has modal-state patterns
(`endShiftOpen` ≈L126).
1. **Types** (`my-day/lib/types.ts`): add `shiftChecklists?`, `checklistConfig?`,
   `runDetail?` to the shared props; a light `ShiftChecklistRun` (or import `ChecklistRun`/
   `RunDetail` from `@/components/checklists/types`).
2. **Section** — under the active shift's site block, a **"Checklists due this shift"**
   card (only when `active_shift` + `shiftChecklists.length` + `checklistConfig.can.run`):
   one row per run (category icon + template name + status pill: Overdue if `is_overdue`,
   else Due/In progress) + a **Complete** ("Continue" if in_progress) button →
   `setActiveChecklistRun(run.id)` (new `useState<number|null>`). Hidden when none due.
3. **Inline `RunModal`** at page bottom, wrapped in a minimal provider:
   ```tsx
   {activeChecklistRun != null && props.checklistConfig ? (
     <ChecklistConfigProvider value={{
       categories: props.checklistConfig.categories,
       categoryMap: Object.fromEntries(props.checklistConfig.categories.map(c => [c.key, c])),
       freqLabels: props.checklistConfig.frequencyLabels, typeLabels: props.checklistConfig.typeLabels,
       today: props.checklistConfig.today,
       can: { view: props.checklistConfig.can.view, run: props.checklistConfig.can.run, schedule: false, manageTemplates: false },
       scope: { mode: 'site', site: { id: activeShift.site.id, name: activeShift.site.name, type: activeShift.site.type }, backHref: '/my-day' },
       assignableUsers: [], openRun: setActiveChecklistRun, openBuilder: () => {},
     }}>
       <RunModal runId={activeChecklistRun} onClose={() => setActiveChecklistRun(null)} />
     </ChecklistConfigProvider>
   ) : null}
   ```
   The modal reloads `runDetail` from `/my-day` (§2.2.3), Save/Complete post then `back()`
   → `/my-day` reloads → completed run leaves `shiftChecklists` → drops off. Hazard/damage
   folding fires server-side. (Imports: `RunModal`, `ChecklistConfigProvider`,
   `CategoryIcon`/`StatusBadge` from the checklists module.)

### 2.4 Edge cases
- Off-shift → `shiftChecklists = []` → card hidden (optional: fall back to `shifts[0]`
  like the page's existing `activeShift ?? shifts[0]` ≈L174).
- `scheduled_date <= today` keeps overdue runs visible until done.
- Materialiser stays idempotent; the display never writes.
- Hide card/buttons unless `checklistConfig.can.run`.

### 2.5 PR 2 tests & verify
- My Day feature test: support_worker + active shift at a site with a scheduled run today
  → `GET /my-day` `->has('shiftChecklists', 1)` + `->has('checklistConfig')`; complete it
  → reload `->has('shiftChecklists', 0)`.
- Worker-can-complete: support_worker (now perms) `POST …/complete` `assertRedirect()` +
  run `completed`; a user with neither perm → 403. Keep `ChecklistRunActionsTest` green and
  assert a support_worker is **still** forbidden from reschedule/reassign/skip/restore
  (proves §2.1 only relaxed execution).
- `tsc` 0 · `build` 0. Remote (after deploy + RbacSeeder re-seed): a support worker on a
  shift sees the due checklist on My Day, completes it inline, it drops off; a second
  worker on another shift at the same site sees it until then.

---

## §Z. Out of scope (flag to owner)
- Optional hardening for PR 2: schedule `generateUpcomingRuns` nightly (so runs exist on
  no-shift days) and/or move `ensureRunsForShiftLocalDay` to a queued job (keep shift
  writes fast). Not required for either PR.
- Remaining checklist backlog (reports weighting, real photo upload, drag-to-reschedule)
  stays deferred.
