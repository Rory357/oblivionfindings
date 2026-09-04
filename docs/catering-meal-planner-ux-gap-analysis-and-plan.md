# Meal Planner (/catering) — UX/UI Gap Analysis & Improvement Plan

**TL;DR.** The Meal Planner is a clinically load-bearing surface in a NZ supported-living CRM, but several of its most safety-critical paths *fail open and fail silent*: a failed allergen check looks identical to "no conflicts", a failed data load looks identical to an empty house, and texture/IDDSI and fluids requirements are displayed but never enforced or warned on. Layered on top are responsive-layout and click-target gaps (a toolbar that buries the calendar on narrow windows, quick actions hidden behind desktop right-click, controls below the WCAG 2.5.8 AA 24px floor), missing tab/live-region accessibility semantics, unconfirmed destructive bulk actions, "looks-broken" first-run empty states, and safety-data-entry surfaces (recipe editor, legacy tag/product editors) that aren't audited for the mis-entry risk they carry. This plan dedupes ~60 raw findings across 7 lenses into a phased, **UI/UX-only** programme: **P0** = stop the safety surfaces from lying (fail-closed + honest empty/error states); **P1** = the core workflow and accessibility; **P2** = polish + oversight surfacing + safety-data-entry hardening. All backend-shaped ideas are quarantined in a Deferred appendix and excluded from the phases.

> **Scope note (web-only).** This plan covers the **web** application — desktop/laptop, mouse + keyboard, responsive down to narrow/split windows. A separate **native Android/iOS app is planned** and owns the in-shift mobile/tablet experience, so touch-specific ergonomics (long-press, swipe, 44px touch targets) are **out of scope** here; control sizing targets the WCAG 2.5.8 AA 24px floor instead.

---

## Orientation

### What this module is
A **dual-homed** "Meal Planner / Catering" feature for houses where residents with disabilities/health needs live, supported by support workers. Meal planning here has real clinical-safety stakes: **allergens** (anaphylaxis), **choking/aspiration risk** (IDDSI texture-modified diets, thickened fluids), **dietary requirements** (halal/vegetarian/diabetic — dignity *and* safety), refusals and intake. Use NZ currency/terms throughout (`en-NZ`, NZD).

Both homes share **one** component tree and **both must keep working**:

- **Embedded** in a Site profile at `/sites/{id}?tab=meal-planner` — the support worker's in-shift workspace. `mode="embedded"` → compact `MealPlannerToolbar`, **no hero banner**. Accessed on **desktop/laptop web browsers** (a separate native Android/iOS app is planned to own the in-shift mobile/tablet experience).
- **Standalone** at `/catering` — brand hero + site switcher + week-picker. `mode="standalone"`. The manager's entry point.

### Key files (use these exact paths; do not invent others)
| File | Role |
|---|---|
| `resources/js/pages/sites/meal-planner/index.tsx` | **Shared orchestrator** (`MealPlannerSubTabs`). Owns bootstrap, reloads, derived `stats`/`notifications`, sub-tab strip, lazy dialog registry. |
| `resources/js/pages/sites/meal-planner/_hero.tsx` | Standalone `MealPlannerHero` **and** embedded `MealPlannerToolbar`; `HeroBell`, `HeroStat`, `KpiChip`, `WeekPicker`, conflict strip. |
| `resources/js/pages/sites/meal-planner/_calendar-grid.tsx` | Weekly grid, `MealCard`, `MealHoverCard`, `MealContextMenu`, `ResidentChip`/`ResidentHoverCard`, `WeekActionsMenu`, budget/completeness bars, `SpendReportDialog`, `KitchenSheetPrintDoc`. |
| `resources/js/pages/sites/meal-planner/_inventory-table.tsx` | Inventory table, quick ±1, `StockGauge`, category filter pills. |
| `resources/js/pages/sites/meal-planner/_recipes-panel.tsx` + `_recipe-edit-dialog.tsx` | Recipe library cards, `RecipeDetailDialog`, recipe editor (`toggleTag` at line 120 picks tags). |
| `resources/js/pages/sites/meal-planner/_shopping-list-panel.tsx` + `_templates-panel.tsx` | Shopping lists (`DraftCard`, export dropdown, `BrandedListPrintDoc`); week templates + `TemplateBuilderDialog`. |
| `resources/js/pages/sites/meal-planner/_dialogs.tsx` | `PlanEntryDialog`, `AdjustInventoryDialog`, `StocktakeDialog`, `ShoppingListGenerateDialog`, `SettingsDialog`. |
| `resources/js/pages/sites/meal-planner/_library-dialogs.tsx` | `ProductsManagerDialog`, `DietaryTagsManagerDialog`. |
| `resources/js/pages/sites/meal-planner/_helpers.ts` | Types, `IDDSI`, `SLOT_TIME`/`SLOT_LABEL`, `conflictsFor()`, `residentRelation()`, formatters. |
| `resources/js/pages/catering/meal-planner.tsx` | **/catering wrapper** (currently a bespoke `space-y-4 p-6` div, not `PageLayout`). |
| `resources/js/pages/catering/_tabs.tsx` + `recipes/`, `products/`, `tags/` | **Legacy** library pages (superseded by in-planner managers). |
| `app/Http/Controllers/Sites/SiteMealPlanController.php` | Bootstrap + week-summary endpoints (read-only reference for what the API already returns). |

### Bootstrap data contract
`GET /sites/{site}/meal-planner/bootstrap` → `{ site, recipes, products (thin: {id,name,default_unit}), product_categories, clients (residents), templates, sites, iddsi_levels, dietary_tags (thin: {id,label,kind}), permissions }`.

Per-week data: `GET /sites/{id}/meal-plan?week=` (entries) and `GET /sites/{id}/meal-plan/week-summary?week=` which returns `total_cost_cents`, **`cook_cost_cents`**, **`takeaway_cost_cents`**, and **`by_day`** (the latter three are fetched but currently discarded — see P2). Mutations = `axios`/Inertia + `sonner` toast, then `bootstrap()`/`reload*()` to refresh.

Types you'll lean on (`_helpers.ts`): `Resident` carries `allergens` + `allergen_tag_ids`, `dietary` + **`dietary_tag_ids`** (present in `residentPayload` and the type — e.g. halal/vegetarian/diabetic), `dislikes` + `dislike_product_ids`, `texture: {level,label} | null`, `fluids: string | null`. `PlanEntry` carries `source_type: 'recipe'|'ad_hoc'|'takeaway'`, `client_ids`, `served_at`, `allergen_override_reason`/`_at`/`_by` — **but no `served_by`** (persisted server-side, omitted from the client payload; see Deferred). `RecipeTag` carries optional `severity`. `conflictsFor()` **only evaluates `source_type === 'recipe'`** and checks **allergens (hard) and dislikes (soft) only** — it does **not** check recipe dietary tags against resident `dietary_tag_ids` (see P0-10).

### Domain framing
The calendar at handover is a clinical record. "Served" is the closest thing to an intake record and (per orchestrator) deducts inventory. Allergen **overrides** are the highest-trust decision in the module and exist to be auditable. Texture-modified diets and thickened fluids are choking/aspiration controls that rank alongside allergens. Dietary requirements (halal/vegetarian/diabetic) are a dignity-and-safety control with the same data shape as allergens but currently zero signal.

---

## Conventions & gotchas

- **Brand colour, NOT Sites green.** Use `--primary`. Inside the planner the `--sites*` token family is *aliased* to `--primary` in `app.css`, so legacy `bg-sites`/`text-sites-deep` classes currently render brand-correct — **do not reintroduce literal green and do not "fix" aliased classes into green.** Two real green literals **do** leak: `#1f7a4d` in the print docs (P2). Prefer `bg-primary`/`text-primary` for *new* chrome.
- **Dual-homed: both modes must keep working.** Every change to a shared component (hero/toolbar, grid, dialogs, sub-tabs) must be verified in **both** `mode="standalone"` (/catering) and `mode="embedded"` (/sites/{id}?tab=meal-planner). Office (non-house) sites only get Inventory/Shopping/Recipes — guard house-only logic on `isHouse`.
- **Stock terminology: use the product's existing "par level" / "below par" language.** The hero already says "below par" for low stock (line 388). New copy and ARIA labels must say "below par" / "reorder at par" — **not** "low stock" / "reorder at {n}" — for consistency with the rest of the module.
- **"Texture-modified diet"** is a clinical term — always hyphenated, sentence-case in prose ("texture-modified diet"), wherever it appears (P0-4, P2-9).
- **Web-only — desktop/laptop first; do NOT build touch affordances.** This plan targets the **web** app (mouse + keyboard; desktop/laptop browsers; must stay usable down to narrow/split windows). A separate **native Android/iOS app is planned** and owns the in-shift mobile/tablet experience — so no long-press, swipe, or 44px touch mandates here. Control-size target is **WCAG 2.5.8 (AA) = 24×24 CSS px minimum**: size controls for comfortable mouse clicking and meet the 24px floor — do **not** inflate to 44px for touch.
- **Pre-ship:** `npm run types` **and** `npm run build`. `vite build` does **not** type-check.
- **Deploys auto-pull+build but SKIP seeders.** New permission/tag data 403s or looks empty on the server until the relevant `*Seeder --force` is run. This is *why* a 403 → silent-empty planner is a realistic, recurring failure mode — the P0 error-state work directly addresses it.
- **Full-width layout.** Page bodies fill the screen; no centered `max-w` cap. The /catering wrapper currently violates this (P2-2).
- **Known empty-state quirks (make the product look broken on first run):** recipes are seeded `is_active=false` → Recipes tab looks empty; the demo house **9011 has no residents** → Calendar + conflict engine look empty *and the allergen safety layer is silently dormant*. P0/P1 empty-state work must distinguish these from genuine failures.
- **Verify on dev** via Chrome MCP logged in as `admin@demo.test` after merging to main. Re-seed permissions on the server if a newly-gated panel 403s.
- **Confirmed code facts** (so you don't re-discover them): `index.tsx` bootstrap `catch {}` is empty and still sets `bootstrapped=true` (lines 113–116); `reload*` callbacks have **no** try/catch (125–152); active sub-tab uses `bg-sites` (494); `Suspense fallback={null}` (398). `_dialogs.tsx` conflict-check `.catch` resets to `EMPTY_REPORT` (154–156), `saveDisabled` ignores any check-failure (173), the `onError` re-check has no `.catch` (183–187), `markServed` posts empty body and closes (204–210), the existing-override panel renders at 224–235. `/catering` wrapper is a hand-rolled `space-y-4 p-6` with a dead-end no-sites div.

---

## Improvements

Severity/effort are carried from the lens findings; duplicates across lenses are merged into single items. **Resident safety first.**

---

### P0 — Safety integrity + "looks-broken" clarity

> These stop the safety surfaces from presenting *failure* or *absence* as *all-clear*. Highest priority regardless of effort.

#### P0-1. Fail **closed** on a failed allergen check (currently fails open)
**Problem.** In `PlanEntryDialog` the debounced `POST .../check-conflicts` `.catch` sets `setReport(EMPTY_REPORT)` (lines 154–156), which has `has_hard_blocks=false`/`has_soft_warnings=false`. So `saveDisabled` (line 173) goes false and **Save enables** — byte-for-byte identical to a genuinely safe meal. The submit-time `onError` re-check (183–187) has **no `.catch`**, so a failure there leaves the stale/empty report in place. The only loading signal is plain text "Checking dietary conflicts…" with no `aria-live`. **This is the single highest-risk gap in the product** (a 500/419/dropped wifi lets a worker save a hard-allergen meal with no warning).

**Affected files.** `resources/js/pages/sites/meal-planner/_dialogs.tsx`.

**UX/UI direction.**
- Add a `checkFailed`/`reportError` state. In **both** `.catch` blocks (the debounced check and the submit `onError` re-check) set `reportError` instead of `EMPTY_REPORT`.
- When `reportError` is set, render a **critical** inline panel where the conflict panel would be (reuse `border-status-critical` / `bg-status-critical-bg` styling already used for the hard-block panel): `ShieldAlert` icon + copy *"Couldn't verify allergens for this meal. Do not save until allergens are verified."* + a **"Retry check"** button that re-fires the request.
- While `reportError` is set **and** the site is a house with ≥1 resident selected, force `saveDisabled = true` and relabel the primary button **"Re-check before saving"**.
- Wrap the "Checking dietary conflicts…" text and the failure panel in a single `role="status" aria-live="assertive"` region; add a small inline spinner so slow checks are visible. Leave the genuine hard-conflict override flow untouched.

**Acceptance criteria.**
- [ ] Simulating a check failure (e.g. block the POST) shows the critical "couldn't verify" panel, **not** a clean dialog.
- [ ] Save is disabled (button reads "Re-check before saving") while `reportError` is set for a house with residents selected.
- [ ] "Retry check" re-fires the POST and clears the error on success.
- [ ] A real hard conflict still shows the existing override-gated flow unchanged.
- [ ] Screen reader announces the loading→failure/result transition.

**Effort.** M

---

#### P0-2. Fail **visibly** on bootstrap / per-tab load errors (currently fails silent)
**Problem.** `bootstrap()`'s `catch {}` is empty ("swallow — render with empties") and still sets `bootstrapped=true`; `reloadCalendar/Inventory/Lists/Templates` have **no** try/catch. A 403 (seeder-skipped feature), 401/419 (session/CSRF expiry), or flaky house wifi renders a confident, branded, **all-zero** planner — zero meals, zero residents, zero conflicts — while the hero eyebrow still claims **"updated just now"**. Indistinguishable from a legitimately empty house, so the allergen banner shows "all clear" when nothing was checked.

**Affected files.** `resources/js/pages/sites/meal-planner/index.tsx`, `resources/js/pages/sites/meal-planner/_hero.tsx`.

**UX/UI direction.**
- Add a `loadError` state to the orchestrator. In the bootstrap `catch`, set it and distinguish `401/403/419` ("access/session") from generic ("couldn't load"). Fire a `toast.error` immediately.
- When `loadError` is set and there is no cached data, **replace the planner body** (reuse the rounded-card pattern at line 469) with a centered error card: `TriangleAlert`, heading *"We couldn't load the meal planner"*, body *"Some data didn't load — don't use this view to serve meals until it reloads."* (for 403: *"You may not have access, or this feature needs enabling for your account."*; for 419/401: *"Your session expired"* + "Reload page"), and a primary **"Try again"** that re-calls `bootstrap()` (+`reloadCalendar()`).
- **Critically, do not render the hero stats / calendar while errored** (a zeroed conflict banner is the dangerous artefact).
- Wrap each `reload*` in try/catch: on failure fire a scoped `toast.error` ("Couldn't refresh meals for this week — showing last loaded data") and set a per-surface staleness flag rendering a small amber "Showing data from before your last action — Retry" strip above the affected grid/table.
- Distinguish the **legitimate** zero-site case (`currentSiteId === 0`) — that should show a "Select a site" prompt, not an error.

**Acceptance criteria.**
- [ ] A forced bootstrap 403 shows the error card (with access-specific copy) + a working "Try again", **not** a zeroed hero/calendar.
- [ ] A forced week-summary failure shows the amber stale strip + toast and leaves last-loaded data visible.
- [ ] `currentSiteId === 0` shows "Select a site", not the error card.
- [ ] The "updated just now" eyebrow does **not** render while `loadError` is set (ties to P0-3).

**Effort.** M

---

#### P0-3. Stop the hero asserting false freshness ("updated just now")
**Problem.** The hero eyebrow renders a hardcoded `· updated just now` next to a pulsing green "live" dot (`_hero.tsx` line 405). It never reflects real freshness — it shows over stale post-navigation data and over a silently-failed (zeroed) bootstrap, actively reinforcing the P0-2 false-safe.

**Affected files.** `resources/js/pages/sites/meal-planner/_hero.tsx`, `resources/js/pages/sites/meal-planner/index.tsx`.

**UX/UI direction.** Track `lastLoadedAt` set on each **successful** `bootstrap()`; pass it to the hero and render a relative string ("updated 3 min ago"). Suppress the phrase entirely while `loadError` is set or a reload is in flight, and switch the green pulse to a muted/critical dot on error. Lowest-effort acceptable fallback: delete the literal and the pulse dot.

**Acceptance criteria.**
- [ ] Eyebrow never reads "just now" when data is stale/failed.
- [ ] Pulse dot is suppressed/muted while errored.
- [ ] Both standalone and embedded modes verified.

**Effort.** S

---

#### P0-4. Surface texture / IDDSI mismatch at planning time and on the calendar
**Problem.** `Resident.texture {level,label}` is bootstrapped and *displayed* (dialog chip, `ResidentChip`, hover card, and a print-only red `KitchenSheet` banner) but **never warned on**. `conflictsFor()` evaluates only allergens/dislikes. A standard-texture meal planned for an IDDSI-4 (pureed) resident produces no in-app prompt and no calendar signal — texture safety depends entirely on a worker remembering a printout. This is a choking/aspiration risk ranking alongside allergens.

**Affected files.** `_dialogs.tsx`, `_calendar-grid.tsx`, `_recipes-panel.tsx` (detail), `_helpers.ts` (small derived helper).

**UX/UI direction.** Pure surfacing of existing `Resident.texture`; no recipe-side texture model.
- **PlanEntryDialog:** when any selected resident has `texture.level < 7`, render a persistent amber advisory under the residents list: `Soup` icon + *"Texture-modified diet: Mila needs IDDSI 4 (Pureed), Tāne needs IDDSI 6 (Soft & bite-sized). Confirm this meal is prepared to the right texture."* Fold thickened-fluids into the same banner (see P0-5).
- **MealCard:** add a small `Soup`-icon **"Texture"** pill when the entry has an assigned resident with `level < 7`, alongside the existing allergen/override pills. Echo the same line in `MealHoverCard`.

**Acceptance criteria.**
- [ ] Selecting an IDDSI<7 resident shows the amber texture-modified-diet advisory naming each resident + level.
- [ ] MealCards with a texture-modified assignee show a "Texture" pill; hover card lists the requirement.
- [ ] Office sites / residents at level 7 show nothing.

**Effort.** M

---

#### P0-5. Resident-aware warnings for ad-hoc/takeaway + thickened fluids
**Problem.** (a) For `ad_hoc`/`takeaway`, `conflictsFor()` returns early, so the dialog shows a *static* grey/amber note regardless of which residents are selected, these meals never tint cells, never get a card pill, and never count toward `stats.unresolved` — yet they're the meals most likely to hide allergens. (b) `Resident.fluids` (thickened-fluids label) is bootstrapped but shown **only** in the resident hover card — never in the planning dialog, even for beverage/supper slots.

**Affected files.** `_dialogs.tsx`, `_calendar-grid.tsx`.

**UX/UI direction.** Advisory only (no override gate — there's no recipe to match), but **name names** from data already in state.
- For `ad_hoc`/`takeaway` with residents selected, replace the static note with a resident-specific reminder built from selected residents that have any `allergens`. **Join logic (specify in code so the implementer doesn't ship "Aroha, Mila have"):** comma-separate all but the last name, join the final name with " and ", append " have recorded allergens." (singular "has" when only one). Example two-name output: *"Check carefully — Aroha (peanuts, dairy) and Mila (gluten) have recorded allergens."*
- Add **fluids** to the dialog resident checklist line beside the IDDSI chip: `CupSoda` icon + the `fluids` label (e.g. "Thickened L2"), and include it in the P0-4 advisory banner ("…Mila needs thickened fluids (L2)").
- On the MealCard, when an ad-hoc/takeaway entry has assigned residents with allergens, show a neutral **"Check allergens"** pill so it's visibly distinct from a verified recipe.

**Acceptance criteria.**
- [ ] Ad-hoc/takeaway with an allergic resident names that resident + their allergens (not a generic note), with correct comma/"and" join for 1, 2, and 3+ names.
- [ ] `fluids` appears in the dialog checklist and the advisory banner when set.
- [ ] Ad-hoc/takeaway cards with allergic assignees show a "Check allergens" pill.

**Effort.** M

---

#### P0-6. Announce the allergen-conflict banner to assistive tech (and label "Review")
**Problem.** The red conflict strip in the hero (lines 513–528) and toolbar (659–667) is a plain `<div>` — no `role="alert"`/`aria-live`, icon not `aria-hidden`, and the action button's accessible name is just "Review". The dialog hard-block panel (406–417) and the loading text (402–404) are likewise silent. The single most safety-critical signal is **inaudible** and changes dynamically (week nav, resident selection) with no announcement (WCAG 4.1.3).

**Affected files.** `_hero.tsx`, `_dialogs.tsx`.

**UX/UI direction.** Wrap the hero/toolbar strip in `role="alert" aria-live="assertive"`; add `aria-hidden` to the `ShieldAlert`. Set the button name to `aria-label={`Review ${n} allergen conflict${n===1?'':'s'} on the calendar`}`. In the dialog, wrap the hard-block panel in `role="alert"` and the loading text in `aria-live="polite"` (shared with P0-1's live region).

**Acceptance criteria.**
- [ ] Conflict appearing/changing is spoken in both modes.
- [ ] "Review" button announces the count + destination.
- [ ] Dialog loading→hard-conflict transition is announced.

**Effort.** S

---

#### P0-7. Context-aware **Recipes** empty state (stop "looks-broken" + the unchecked-meal pushback)
**Problem.** `_recipes-panel.tsx` (line 133) and legacy `recipes/index.tsx` (line 84) both render the literal **"No recipes match."** for *every* zero-length case. Because recipes are seeded `is_active=false`, a brand-new house's **first impression** of Recipes is this generic "no match" message with no CTA. Workers conclude there are no recipes and fall back to ad-hoc/takeaway — exactly the meals `conflictsFor()` doesn't check. (Tie-in: there's no surfaced way to *activate* seeded drafts — see P1-9.)

**Affected files.** `_recipes-panel.tsx`, `resources/js/pages/catering/recipes/index.tsx`.

**UX/UI direction.** Make the dashed card three-way:
1. **Filtered-empty** (search non-empty OR `cat!=='all'` OR `scope!=='all'`): *"No recipes match your filters"* + a **"Clear filters"** ghost button that resets all three.
2. **Truly-empty library** (`recipes.length===0`, no filters): `ChefHat` icon, *"No recipes yet"*, body *"Build your house's first recipe, or activate one from the shared library."* + a primary **"Add recipe"** (when `canManage`, reusing `onAdd`). Add a hint when drafts exist: *"Some recipes are saved as drafts — activate them to plan from them."*
3. **Loading** (`bootstrapped===false` upstream): a skeleton row, not this card.
Mirror the three-way copy in legacy `recipes/index.tsx`.

**Acceptance criteria.**
- [ ] Empty-because-filtered vs empty-because-no-recipes show distinct copy; the former offers "Clear filters" that works.
- [ ] Truly-empty shows "Add recipe" for managers.
- [ ] Verified in both standalone and embedded.

**Effort.** M

---

#### P0-8. First-run / zero-resident onboarding (make the dormant safety layer visible)
**Problem.** A house with `residents.length===0` (the demo house 9011) silently disables the entire conflict/IDDSI/dislike engine — the resident strip is skipped and the calendar shows a generic empty banner. A configured-but-unplanned house and a no-residents house look identical, and **a clean, conflict-free planner over an un-entered house is the most dangerous false-safe in the tool.** No ordered "getting started" path exists.

**Affected files.** `index.tsx`, `_calendar-grid.tsx`.

**UX/UI direction.** When `isHouse && residents.length===0`, render a dismissible Info card above `SubTabs`: heading *"Finish setting up this house to plan meals safely."*, body *"No residents are linked yet, so allergen and texture checks are paused."* + an ordered checklist:
1. **Add residents & their dietary needs** — **link-out only** to the site profile (residents and dietary tags are owned by the Client model outside this module; **do not build resident-editing UI in the planner**).
2. **Set a weekly food budget** — in-module action → `setSettingsOpen(true)`.
3. **Plan meals or apply a template** — scrolls to / activates the Calendar tab.

Separately, in `CalendarGrid` show a one-line muted note in the resident-strip slot: *"No residents linked — allergen & texture checks are paused for this house."*

**Acceptance criteria.**
- [ ] Zero-resident house shows the onboarding card + the "checks paused" calendar note.
- [ ] "Set a weekly budget" opens `SettingsDialog`.
- [ ] The "Add residents…" step is a **link/navigation to the site profile only** — no resident-editing UI is added inside the planner.
- [ ] A house *with* residents shows neither the card nor the note.

**Effort.** M

---

#### P0-9. No-active-sites empty state must offer a way forward
**Problem.** `/catering` wrapper renders a bare dashed div *"No active sites yet — create a site…"* with no link, heading, or icon — a literal dead end and the first thing an admin sees on a fresh tenant.

**Affected files.** `resources/js/pages/catering/meal-planner.tsx`.

**UX/UI direction.** Replace with a proper empty-state card: `Building2`/`Home` icon, *"No sites yet"*, body *"Create a house or office to start planning meals, tracking inventory and building shopping lists."*, and a primary Button → `/sites/create` (render the button only if the user can create sites; otherwise show "contact an admin" guidance). Reuse the app's existing empty-card pattern.

**Acceptance criteria.**
- [ ] Empty state has heading, icon, body, and a working CTA to site creation (permission-gated).

**Effort.** S

---

#### P0-10. Surface **dietary-requirement** mismatch (halal/vegetarian/diabetic) as an advisory
**Problem.** Residents carry `dietary[]` + `dietary_tag_ids` (kind=`dietary`: halal, vegetarian, diabetic, etc.), and recipes carry tags of the same kinds, but `conflictsFor()` checks **only** allergens (hard) and dislikes (soft) — it never compares a recipe's dietary tags against a resident's dietary requirements. A pork/meat meal planned for a halal or vegetarian resident produces **zero signal** anywhere in the UI. In supported living this is a dignity-and-safety gap as real as dislikes, and the data is already in state. We surface it as a UI advisory (same pattern as P0-5); a *blocking* dietary rule would need a backend rule and is Deferred.

**Affected files.** `_dialogs.tsx`, `_calendar-grid.tsx`, `_helpers.ts` (small derived helper comparing `recipe` dietary tag ids ↔ resident `dietary_tag_ids`; advisory only, do not extend the hard/soft gate).

**UX/UI direction.** Advisory only — no override gate, no change to `saveDisabled`.
- **PlanEntryDialog:** when a selected resident has `dietary_tag_ids` that the recipe's dietary tags don't satisfy (e.g. resident halal, recipe not tagged halal/contains a conflicting tag), render a warning-tone advisory naming the resident + requirement: *"Dietary requirement: Tāne is halal — confirm this meal meets it."* Use the same name-join logic as P0-5.
- **MealCard:** add a neutral **"Diet check"** pill (distinct from the allergen/texture pills) when an assignee has an unmet dietary requirement; echo the line in `MealHoverCard`.
- Keep this strictly advisory and clearly worded as "confirm" (not "blocked"), because the match is heuristic on tag presence, not an authoritative rule.

**Acceptance criteria.**
- [ ] Planning a meal for a resident with a dietary requirement the recipe doesn't carry shows the named advisory; satisfying recipes show nothing.
- [ ] MealCards with an unmet dietary requirement show a "Diet check" pill distinct from allergen/texture pills.
- [ ] Behaviour is advisory only — Save is never blocked by a dietary mismatch.

**Effort.** M

---

### P1 — Core workflow + accessibility

#### P1-1. One-click quick-serve on the MealCard + a visible actions menu (stop hiding them behind right-click)
**Problem.** Marking a meal served — the highest-frequency, time-sensitive action and the meal's clinical-record event — has **no quick path that's discoverable**. Left-click opens the full edit dialog; the only quick serve/duplicate/copy/delete lives in the **right-click** `MealContextMenu` (line 283), which most users never discover and which is invisible to keyboard users. So the realistic path to serve a meal is a 3-step dialog round-trip, repeated across up to 6 slots × 7 days.

**Affected files.** `_calendar-grid.tsx` (+ shares the toggle-served axios path used by the context menu).

**UX/UI direction.** Add a small circular quick-serve toggle (existing `CircleCheck`/`RotateCcw`) on the card — revealed on hover/focus, always shown once served — gated on `canPlan`, calling the same `toggle-served` endpoint with optimistic state + toast; give it a ≥24px hit area and a real `aria-label`. Separately, surface the hidden actions through a **visible `⋯` overflow-menu button** (kebab) on the card so Duplicate/Copy-next-day/Delete/Serve aren't right-click-only — keep the right-click menu as a power-user accelerator that opens the same menu. Give the menu `role="menu"` and items `role="menuitem"`, and make both the toggle and the overflow button keyboard-focusable.

**Acceptance criteria.**
- [ ] The quick-serve toggle is reachable by hover **and** keyboard focus; one click serves/unserves with optimistic UI + toast.
- [ ] A visible `⋯` button opens the actions menu (also openable via right-click); the menu is keyboard-navigable.
- [ ] Toggle and overflow button are hidden/disabled when `!canPlan`.

**Effort.** M

---

#### P1-2. Make the embedded toolbar responsive so it stops burying the calendar
**Problem.** `MealPlannerToolbar` only goes horizontal at `xl` (~1280px), so on a typical laptop or a narrowed/split browser window it stacks week-nav + 4 KPI chips + 4 actions into a tall header that pushes the calendar below the fold, with read-only KPIs taking vertical priority over the actions. Control sizing is also inconsistent and partly below the WCAG 2.5.8 AA 24px floor: per-cell **Add** is `py-1` (~24px) and the `ResidentChip` pencil `h-7 w-7` (28px), while toolbar actions are `h-9` (36px) and the bell/settings `h-9 w-9` — all smaller than, and misaligned with, the desktop hero's `h-10`.

**Affected files.** `_hero.tsx`, `_calendar-grid.tsx`.

**UX/UI direction.**
- Lower the horizontal breakpoint from `xl` → `md`. On narrow widths put **week-nav + actions on the first row** (`flex-wrap`) and demote the 4 KPI chips to a compact **horizontally-scrolling** second row (`overflow-x-auto`, no-wrap) instead of stacking. Keep "Plan a meal" visible without scrolling at ~1024px and in a split/half-width window.
- Standardise control sizing for visual consistency and the 24px AA floor: align bell/settings with the action buttons (also resolves the P2 `h-10` bell vs `h-9` button misalignment).
- Calendar Add button and `ResidentChip` pencil → ensure a ≥24px hit area (extend with padding if the visual must stay compact).

**Acceptance criteria.**
- [ ] At ~1024px (and in a half-width window) the calendar is visible without scrolling past stacked chrome; KPIs scroll horizontally.
- [ ] Toolbar actions, bell/settings, cell Add, and chip pencil are visually consistent and have a ≥24px (WCAG 2.5.8 AA) hit area.
- [ ] Standalone hero layout unchanged/intact.

**Effort.** M

---

#### P1-3. Raise sub-24px controls to the WCAG AA floor across Inventory / Shopping / Templates
**Problem.** Several of the most-used controls fall below the WCAG 2.5.8 AA 24px minimum and are fiddly to hit even with a mouse: shopping tick-off `h-5 w-5` (20px), template servings stepper `h-4 w-4` (16px) and clear-X `h-3 w-3` (12px); the `h-7 w-7` (28px) inventory/shopping icon buttons clear the floor but are inconsistent. A mis-click marks the wrong item or mis-sets a clinically-relevant serving count.

**Affected files.** `_shopping-list-panel.tsx`, `_inventory-table.tsx`, `_templates-panel.tsx`.

**UX/UI direction.** Raise every listed control to a ≥24px hit area and standardise: shopping tick-off → ≥`h-6 w-6` (inner icon); template steppers/clear-X → ≥`h-6 w-6` with padding; align the inventory/shopping icon buttons to a single size. Where the *visual* must stay small, keep it but extend the clickable zone with `-m-2 p-2` so the hit area still meets 24px.

**Acceptance criteria.**
- [ ] All listed controls have a ≥24px (WCAG 2.5.8 AA) hit area (visual size may stay smaller via padding).
- [ ] No layout regressions in table/list rows.

**Effort.** M

---

#### P1-4. Make `SubTabs` an accessible tablist (and switch off the green token)
**Problem.** The primary module navigation is plain `<button>`s in a `<div>` (index.tsx 473–503): no `role="tablist"`/`tab`, no `aria-selected`/`aria-controls`, no `role="tabpanel"`, no arrow-key roving. Screen-reader users hear five undifferentiated buttons and can't tell which view (e.g. the safety-critical Calendar) is active. The active pill also uses `bg-sites` (relies on the alias) directly beneath the `--primary` hero.

**Affected files.** `index.tsx`.

**UX/UI direction.** Container `role="tablist" aria-label="Meal planner sections"`; each button `role="tab"`, `aria-selected={active}`, `id={`tab-${value}`}`, `aria-controls={`panel-${value}`}`, `tabIndex={active?0:-1}`; add Left/Right (move+activate) and Home/End handlers. Body wrapper → `role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} tabIndex={0}`. Replace `bg-sites text-primary-foreground` with `bg-primary/10 text-primary` (the style `_tabs.tsx` line 81 already uses correctly). Bump buttons to ~`min-h-10` for touch.

**Acceptance criteria.**
- [ ] Arrow keys move between tabs; active tab exposes `aria-selected`; panel is associated.
- [ ] Active pill renders via `--primary` utilities, not `bg-sites`.

**Effort.** M

---

#### P1-5. Calendar accessible names: day/slot context + spoken safety state
**Problem.** Empty-cell Add buttons are `aria-label="Add Breakfast"` ×7 with **no day** (line 1062). `MealCard` (line 271) has **no** `aria-label` — its name is just meal text, and the allergen/override/served state is conveyed only by colour + a pill the name never reaches (WCAG 1.3.1/1.4.1/4.1.2). SR users would have to open every card to find risk.

**Affected files.** `_calendar-grid.tsx`.

**UX/UI direction.** Add Day to the Add label: `Add ${SLOT_LABEL[slot]} for ${dayLabel} ${d.getDate()}/${d.getMonth()+1}`. Compose the MealCard name: `${name}, ${SLOT_LABEL[slot]} ${dayLabel}, ${servings} serves${unresolved?', allergen conflict':''}${overridden?', allergen override on file':''}${served?', served':''}`. Replace the spotlight badge `title` (line 300) with `aria-label`. Pass `slot`/`day` down to `MealCard`.

**Acceptance criteria.**
- [ ] Each Add button announces its specific day + slot.
- [ ] MealCard name includes day, slot, serves, and any conflict/override/served state.

**Effort.** M

---

#### P1-6. Keyboard alternative to drag-and-drop ("Move to…")
**Problem.** Moving a meal is HTML5 `draggable`-only (no keyboard/menu path); `MealContextMenu` has Edit/Duplicate/Copy-next-day/Delete/Serve but **no Move**. Keyboard/switch users can't reschedule without delete-and-recreate (WCAG 2.1.1) — drag-and-drop is the sole move path and is itself inaccessible.

**Affected files.** `_calendar-grid.tsx`.

**UX/UI direction.** Add a **"Move to day/slot…"** item to `MealContextMenu` (keyboard-reachable via the P1-1 menu semantics) opening a small day+slot picker that calls the existing `moveEntry()`. Minimum interim: a "Move" affordance that opens `PlanEntryDialog` focused on its existing date/slot fields.

**Acceptance criteria.**
- [ ] A planned meal can be moved to a different day/slot entirely by keyboard.
- [ ] Uses existing `moveEntry()`; no backend change.

**Effort.** M

---

#### P1-7. Confirm destructive bulk actions (Clear week / Apply-template-replace / Repeat last week)
**Problem.** `clearWeek()` (DELETE) fires on one click; `applyTemplate(t, true)` and the empty-week CTA overwrite the whole week immediately; `copyWeek` likewise — **none confirm**, while single-item deletes already use `ConfirmAction`. A dietitian-authored, allergen-safe/texture-modified week can be wiped by a mis-click with no undo and no trace. `WeekActionsMenu` also lacks `role="menu"`/`aria-expanded`/focus trap, so stray Tab/Enter can trigger these.

**Affected files.** `_calendar-grid.tsx`.

**UX/UI direction.** Wrap `clearWeek`, replace-mode `applyTemplate`, and Repeat-last-week in the existing `ConfirmAction` with **impact-aware** copy using counts already in scope: *"Clear all {N} planned meals for {range}? This can't be undone."* / *"Replace this week's {N} meals with \"{template}\" ({M} meals)?"*. Convert `WeekActionsMenu` to proper menu semantics (`role="menu"`/`menuitem`, `aria-expanded`, Escape-to-close, focus trap).

**Acceptance criteria.**
- [ ] Clear/replace/repeat each show a confirm naming the affected meal count.
- [ ] Week actions menu is keyboard-operable and closes on Escape; destructive items aren't reachable by stray Tab.

**Effort.** S–M

---

#### P1-8. Loading feedback for lazy dialogs + post-mutation reloads
**Problem.** All five write dialogs (`PlanEntryDialog`, `AdjustInventoryDialog`, `StocktakeDialog`, `ShoppingListGenerateDialog`, `SettingsDialog`) share `<Suspense fallback={null}>` (line 398) → on slow house wifi "Plan a meal" / "Generate shopping list" appears to do nothing (the plan dialog also hosts the allergen check), and a chunk-load failure is a silent dead-end. Separately, after every mutation `bootstrap()`/`reload*()` runs with no in-flight indicator — week nav leaves **stale stats/grid** on screen presented as current, then a sudden swap. Initial load is a flat "Loading meal planner…" card.

**Affected files.** `index.tsx`, `_calendar-grid.tsx`, `_inventory-table.tsx`.

**UX/UI direction.**
- Replace `fallback={null}` with a minimal centered spinner overlay ("Opening…"); wrap the lazy dialogs in a small error boundary that toasts "Couldn't open — please reload" on chunk failure; optionally set `aria-busy` on the trigger. Consider prefetching the `_dialogs` chunk on first idle. (This covers the *open* of all five dialogs, including `ShoppingListGenerateDialog`; that dialog's own form a11y/onError is addressed in P2-19.)
- Replace the flat loading card with a layout-matched **skeleton** (hero band + 4 stat tiles + 7-col grid placeholder), following the existing `CockpitSkeleton` precedent in `resources/js/components/governance`.
- Track an `isReloading` flag around `reloadCalendar`/`reloadInventory`; show a small spinner by the week label during calendar reload and `aria-busy` on the grid container; add `role="status"` to `HeroStat` tiles so refreshed numbers are announced.

**Acceptance criteria.**
- [ ] Clicking a primary action shows immediate feedback even on a throttled connection.
- [ ] Week navigation shows an "updating" cue rather than silently swapping stale numbers.
- [ ] Initial load shows a layout skeleton.

**Effort.** M

---

#### P1-9. Surface inactive/draft recipes so seeded recipes are reachable
**Problem.** Legacy `recipes/index.tsx` declares a `filters.inactive` prop the server honours but **never renders the toggle** (only sends `q`); the planner receives only active recipes and `_recipe-edit-dialog` always saves `is_active=true` with no toggle. With recipes seeded `is_active=false`, the planner Recipes tab is empty while a full set of "Draft" rows exists if you knew to look — a contradictory IA and no path to activate.

**Affected files.** `resources/js/pages/catering/recipes/index.tsx`, `_recipe-edit-dialog.tsx`, `_recipes-panel.tsx` (empty-state copy from P0-7).

**UX/UI direction.** (1) Render a **"Show drafts"** Switch/checkbox next to Search in the legacy index, bound to local state adding `inactive:true` to the `router.get` params. (2) Add an **"Active"** toggle to `_recipe-edit-dialog.tsx` (legacy edit page mirrors it) so a manager can flip `is_active` without leaving the planner.

**Acceptance criteria.**
- [ ] "Show drafts" reveals inactive recipes in the legacy list; rows open/edit.
- [ ] The recipe editor exposes an Active toggle that persists `is_active`.

**Effort.** M

---

#### P1-10. Embedded week-picker parity + time-aware "Plan a meal"
**Problem.** (a) The embedded toolbar's week-label calls `onThisWeek` (jump to current) with only Prev/Next — **no `WeekPicker`** — so the embedded workspace is the one missing arbitrary week nav. (b) `planToday()` hard-jumps to the current week and opens **lunch** regardless of time, and yanks a worker reviewing a future week back to today. `SLOT_TIME` exists but isn't used.

**Affected files.** `_hero.tsx`, `index.tsx`.

**UX/UI direction.** Reuse the existing `WeekPicker` in `MealPlannerToolbar` (anchored to the button ref, wired to `onSelectWeek`); move "jump to today" to a small "Today" affordance shown only when `!isThisWeek`. In `planToday()`, pick the slot from `SLOT_TIME` nearest now (≥15:00→dinner, ≥12:00→lunch, else breakfast); don't reset `weekStart` if the viewed week already contains today.

**Acceptance criteria.**
- [ ] Embedded toolbar opens a week picker for arbitrary jumps; "Today" still available.
- [ ] "Plan a meal" defaults to a time-appropriate slot and doesn't bounce the user off a week containing today.

**Effort.** S

---

#### P1-11. Accessible custom popovers: notification bell + shopping export dropdown
**Problem.** `HeroBell` is a button with static `aria-label="Notifications"`, a visual-only count, no `aria-expanded`/`haspopup`/`controls`, a non-dialog/menu popover, no Escape, no focus management. The shopping **Export (PDF/CSV)** dropdown is a bespoke div dismissed only by `mousedown` — no `aria-expanded`/`haspopup`, no `role="menu"`/`menuitem`, no Escape, no managed focus → keyboard/SR users can't export the list they take to the shop.

**Affected files.** `_hero.tsx`, `_shopping-list-panel.tsx`.

**UX/UI direction.** Bell: dynamic `aria-label` (`count>0 ? `Notifications, ${count} need attention` : 'Notifications, all clear'`), add `aria-expanded`/`aria-haspopup`/`aria-controls`; give the popover `role="menu"` (items `role="menuitem"`), Escape-to-close returning focus to the bell, focus first item on open — mirror the existing `SiteSearch` keyboard handling in the same file. Export: replace the bespoke dropdown with the project's Radix `DropdownMenu` (or add the same `aria-haspopup`/`expanded`/`role`/Escape/arrow-key handling).

**Acceptance criteria.**
- [ ] Bell announces open-state + unread count; Escape closes and restores focus.
- [ ] Export menu is fully keyboard- and SR-operable.

**Effort.** M

---

#### P1-12. Inventory table semantics + icon-button labels
**Problem.** The four per-row icon buttons (Minus/Plus/Pencil/Trash) have **no** `aria-label`; category pills have no `aria-pressed`; the table has no `<caption>`/`scope="col"`; `busyId` disables without `aria-busy`. SR users hear "button button button button" with no product or direction (WCAG 4.1.2/1.3.1/1.4.1) — risking wrong stock for allergen/texture-relevant items.

**Affected files.** `_inventory-table.tsx`.

**UX/UI direction.** Add product-referencing labels (`Add one ${name}`, `Remove one ${name}`, `Adjust ${name}`, `Delete ${name}`); `aria-pressed` on category pills wrapped in `role="group" aria-label="Filter by category"`; `<caption className="sr-only">Inventory items</caption>` + `scope="col"` on each `<th>`; `aria-busy={busyId===item.id}` on quick-adjust buttons. (Pairs with P1-3 sizing.)

**Acceptance criteria.**
- [ ] Each row icon button announces action + product.
- [ ] Category filter exposes pressed-state; table has caption + column scopes.

**Effort.** S

---

#### P1-13. Allergen-vs-dietary distinction + a11y in the recipe editor's tag picker
**Problem.** `_recipe-edit-dialog.tsx` is **where allergen tags get attached to a recipe**, and `conflictsFor()` depends entirely on `recipe.allergen_tag_ids` being correct — a mis-tagged or untagged allergen here defeats the entire downstream hard-block. Yet the editor's `toggleTag` (line 120) treats *all* tags uniformly: allergen (kind=`allergen`) and dietary (kind=`dietary`) tags are picked from one undifferentiated list, with no visual separation, no severity cue, and the tag toggles' a11y is unaudited. This is the same "mis-entered safety-critical data" risk P2-17 worries about for the *legacy* editors, on the surface that actually feeds the engine.

**Affected files.** `_recipe-edit-dialog.tsx`, `_helpers.ts` (read `kind`/`severity` already in `dietary_tags`).

**UX/UI direction.** Display + a11y only; no change to what's persisted.
- Split the tag picker into two clearly-labelled groups by `kind`: a **"Contains allergens"** group rendered with `status-critical` styling + `ShieldAlert`, and a separate **"Dietary"** group in neutral/green. Critical-severity allergen tags (P2-8) carry the same marker here so the person tagging sees the weight.
- Selected allergen tags must read visibly as allergens at selection time (red chip, not the same muted chip as dietary).
- Tag toggles: real toggle semantics — `role="button"`/`aria-pressed` (or a checkbox), each with an accessible name that includes the kind (e.g. `Peanuts, allergen`), ≥24px hit area, visible focus ring.
- Add a quiet helper line under the allergen group: *"Allergen tags drive the safety check shown when planning meals."* so the consequence is explicit.

**Acceptance criteria.**
- [ ] Allergen tags and dietary tags are visually separated and labelled; selected allergen tags render in the red treatment.
- [ ] Each tag toggle exposes pressed-state + a name that includes its kind; toggles are ≥24px with a visible focus ring.
- [ ] Critical-severity allergen tags are distinguished in the picker (consistent with P2-8).

**Effort.** M

---

### P2 — Polish + oversight + safety-data-entry hardening

#### P2-1. Print docs follow `--primary` (kill the `#1f7a4d` green literal) **and** carry complete P0 safety data
**Problem.** (a) `KitchenSheetPrintDoc` and `BrandedListPrintDoc` hardcode `#1f7a4d` for header rule, icon tile, and headings — the **only** surfaces that ignore Settings→Branding. The Cook Sheet is a clinical-safety document physically pinned in the kitchen; mismatched branding erodes trust in an audited setting. (b) More importantly, the Cook Sheet is *the* offline safety artefact, but nothing verifies it actually prints the full P0-4/P0-5 safety picture — per-resident texture **and** fluids, allergen warnings, and the effect of overrides — for the meals shown.

**Affected files.** `_calendar-grid.tsx`, `_shopping-list-panel.tsx`.

**UX/UI direction.**
- Read the resolved brand once via `getComputedStyle(document.documentElement).getPropertyValue('--primary')` into a `brand` const per print component and interpolate it into the inline border/icon/heading styles. Keep body text `#111`/white for paper contrast.
- Audit the Cook Sheet's content against the data the P0 work surfaces on-screen: for each assigned resident it must print **texture (IDDSI level + label)**, **fluids** label when set, and **recorded allergens**; allergen-conflict meals must print the warning and, where an override exists, its reason/author (so paper matches screen). Add any missing field rather than relying on the existing red banner alone.

**Acceptance criteria.**
- [ ] Both printouts render brand accents from `--primary`; rebranding the tenant changes the print colour; body text stays high-contrast.
- [ ] The Cook Sheet prints per-resident texture **and** fluids, recorded allergens, and override reason/author where present — matching what P0-4/P0-5 show on screen.

**Effort.** S–M

---

#### P2-2. `/catering` full-width via `PageLayout`
**Problem.** The wrapper is a bespoke `space-y-4 p-6` div inside `AppLayout` — not `PageLayout`, violating the full-width rule. The wide 7-day grid (`min-w-[940px]`) and inventory table (`min-w-[820px]`) are inconsistently inset vs sibling pages and scroll horizontally sooner.

**Affected files.** `resources/js/pages/catering/meal-planner.tsx`.

**UX/UI direction.** Wrap the body in `PageLayout` (matching other full-width pages); keep the (P0-9) empty-state card inside it. If `PageLayout` is unsuitable, at minimum align padding/width to the shared convention.

**Acceptance criteria.** [ ] /catering fills the screen with the standard gutter; grid/table get edge-to-edge width; empty state still inside the wrapper.

**Effort.** S

---

#### P2-3. Honest "meals served" badge (no green "0 meals served")
**Problem.** The success badge is pushed unconditionally (`_hero.tsx` line 389), so a fresh/empty week shows a green check reading **"0 meals served"** next to real warnings — self-contradictory and reads as broken; its sibling badges already gate on `>0`.

**Affected files.** `_hero.tsx`.

**UX/UI direction.** For houses, push the success badge only when `stats.served > 0`. When `served===0` but meals are planned, show a neutral progress chip ("{served}/{mealsPlanned} served") or omit. Keep the office "Kitchen stocked" badge as-is. (Reframing to planned-vs-served pairs with P2-7.)

**Acceptance criteria.** [ ] No green "0 meals served"; zero-served weeks show neutral/no badge.

**Effort.** S

---

#### P2-4. Allergen tags keep their red treatment in the Recipe **detail** dialog
**Problem.** `RecipeDetailDialog` flattens **all** tags into `DialogDescription` as `tags.map(t=>t.label).join(' · ')` (line 269) — allergens and dietary tags become identical low-contrast muted text. The detail view (opened precisely to vet a recipe) gives allergens **less** weight than the card.

**Affected files.** `_recipes-panel.tsx`.

**UX/UI direction.** Stop flattening. Add a block above ingredients: split `recipe.tags` by `kind`; render a **"Contains allergens"** row of red `status-critical` pills with a `ShieldAlert`, and a separate **"Dietary"** row of green pills. If no allergen tags, show an explicit *"No allergens tagged"*. Reuse the card pill styling.

**Acceptance criteria.** [ ] Detail dialog shows a labelled red allergen row (or explicit "none"); dietary tags separated.

**Effort.** S

---

#### P2-5. Real override audit in the hover card + clickable overrides rollup
**Problem.** `MealHoverCard` shows a hardcoded *"Override on file — separate portion plated"* (lines 152–157), **ignoring** the real `allergen_override_reason`/`_by` already on the entry and possibly inventing a wrong reason. The hero overrides badge is a dead-end count. An auditor can only reconstruct "who overrode the peanut conflict for Mila and why" by opening each meal — though the full record already renders in `PlanEntryDialog` (lines 224–235).

**Affected files.** `_calendar-grid.tsx`, `_hero.tsx`, `index.tsx`.

**UX/UI direction.** In `MealHoverCard`, render the quoted `allergen_override_reason` + *"Approved by {allergen_override_by.name} · {en-NZ datetime}"* (fall back to the generic line only if reason missing). Make the hero/toolbar overrides badge open a lightweight **"Allergen overrides this week"** dialog listing one row per `allergen_override_at` entry: meal + day/slot, affected residents, matched allergen(s), quoted reason, by-name + en-NZ time, each linking to `openExistingMeal(entry)`. All data already in `entries`.

**Acceptance criteria.** [ ] Hover card shows real reason/author/time; overrides badge opens a per-entry rollup that deep-links to each meal.

**Effort.** S–M

---

#### P2-6. Spend report reachable from the hero (+ cooked-vs-takeaway split)
**Problem.** A useful `SpendReportDialog` exists but its **only** entry point is inside the house-only Calendar tab's budget panel. The standalone hero (the manager's review surface) and office sites can't open it; the "Week cost" tile is a dead number. Also, `week-summary` returns `cook_cost_cents`/`takeaway_cost_cents`/`by_day` but the client reads only `total_cost_cents` — the cooked-vs-takeaway split (a cost + nutrition signal) is discarded.

**Affected files.** `_calendar-grid.tsx`, `_hero.tsx`, `index.tsx`.

**UX/UI direction.** Lift `SpendReportDialog` mounting to `index.tsx` (it needs only `siteId`, `currentWeekCents`, `budgetCents` — all in scope). Make the hero "Week cost" `HeroStat` a focusable button (and the toolbar KPI chip) that opens it; tint the tile `status-warning` with an "over budget" sub-line when `weekCostCents` exceeds `weekly_food_budget_cents`. Capture `cook_cost_cents`/`takeaway_cost_cents` in `reloadCalendar` and show a one-line breakdown under the current-week bar (*"NZ$240 cooked · NZ$35 takeaway"*).

**Acceptance criteria.** [ ] Spend report opens from the hero in all modes; week-cost tile drills in and flags over-budget; cooked vs takeaway split shown.

**Effort.** M

---

#### P2-7. Planned-vs-served reconciliation + serve time/notes surfacing
**Problem.** `stats.served` is a raw count with no "X of Y planned" framing, no day/slot breakdown, and `served_at` (a timestamp) renders only as a boolean tick. A manager can't see which planned meals went unserved (a care-delivery gap). The dialog's free-text Notes field isn't signposted for intake/refusals, and once served the "Mark served" button vanishes (no in-dialog undo; the only unserve is the right-click toggle).

**Affected files.** `index.tsx`, `_hero.tsx`, `_calendar-grid.tsx`, `_dialogs.tsx`.

**UX/UI direction.** Hero/toolbar tile shows `{served}/{mealsPlanned} served`. In `MealHoverCard`/context menu, when `served_at` is set show the actual time ("Served 17:45 · stock deducted"). In `PlanEntryDialog`, replace the disappearing button with an explicit **"Mark not served"** when already served (existing toggle endpoint), separate "Mark served" from "Delete" (move near Save / add a divider), and relabel/repurpose the existing free-text `notes` field as a **"Meal record (free-text note)"** with placeholder *"Intake / refusals (e.g. 'Aroha ate half, refused vegetables'; 'Mila — dairy-free portion plated')"*; surface that note prominently in `MealHoverCard`. **This is an unstructured note on the existing `notes` field — not a new per-resident intake data model** (that is Deferred). Keep the field label and any helper text explicit that it is a single free-text note for the meal.

**Acceptance criteria.** [ ] Served stat reads planned-vs-served; serve time shown; in-dialog undo always reachable; the existing `notes` field is signposted as a free-text meal record and echoed on hover; serve isn't adjacent to Delete.
- [ ] Copy makes clear this is one free-text note for the meal, not a structured per-resident intake record.

**Effort.** M

---

#### P2-8. Surface allergen **severity** (trace intolerance vs anaphylaxis)
**Problem.** `RecipeTag.severity` and `dietary_tags` severity are bootstrapped but **never read** — every allergen hit renders as one uniform red hard-block with identical weight and identical override flow, hiding which carries anaphylaxis risk at the exact moment a manager decides to override.

**Affected files.** `_helpers.ts`, `_dialogs.tsx`, `_recipes-panel.tsx`, `_calendar-grid.tsx`, `_recipe-edit-dialog.tsx` (marker reused in the P1-13 picker).

**UX/UI direction.** Display-only (no change to the override gate): in the hard-block list and `MealHoverCard` conflict line, append a small marker for `severity==='critical'` (bold "CRITICAL" chip or filled vs outline dot); sort critical matches to the top of each resident's list; carry the same marker onto allergen pills in the recipe card/detail and the recipe-editor tag picker (P1-13).

**Acceptance criteria.** [ ] Critical-severity allergens are visually distinct and sorted first; non-critical unchanged in behaviour.

**Effort.** M

---

#### P2-9. Texture & soft-conflict signals in the hero/toolbar overview
**Problem.** `stats.unresolved` counts only hard allergen conflicts; the notifications array builds only allergen-hard/out/below-par/expiring. Soft (dislike) conflicts are computed but never surfaced, and texture-modified residents (`texture.level < 7`) — a choking-risk control — have **zero** overview presence; IDDSI only appears on a hover deep in the calendar.

**Affected files.** `index.tsx`, `_hero.tsx`.

**UX/UI direction.** Derive and render (below the dominant critical allergen banner): a warning-tone **"texture check"** badge counting entries whose assignee has `texture.level < 7` (routes to Calendar), an optional **"soft warnings"** badge counting soft-conflict entries (routes to Calendar), and a **"N on texture-modified diet"** `Soup` chip derived from the residents array, in both modes. Reuses `conflictsFor()`/`residentRelation()` already imported. (Use the hyphenated "texture-modified diet" term consistently with P0-4.)

**Acceptance criteria.** [ ] Texture-modified count + soft-conflict count surface in both hero and toolbar; critical allergen banner stays dominant.

**Effort.** M

---

#### P2-10. Status-colour contrast floor + non-colour cues + focus rings + reduced-motion
**Problem.** Hero text/badges are opacity variants of `--primary-foreground` over a `--primary` gradient (contrast tracks whatever brand hue an admin picks); 9px white-on-amber pills ("Allergen", "Override", "Out", "Below par") and `text-primary-foreground on bg-red-400/20` can drop below WCAG 1.4.3, with colour often the sole differentiator (1.4.1). Hero CTAs, `SubTabs`, `MealCard`, and Add buttons carry **no** `focus-visible:ring` — focus is near-invisible on the gradient/tinted cells (2.4.7) despite `DESIGN_TOKENS.md` naming `ring-ring`. Separately, the hero has an `animate-ping` pulse dot (lines 401–403) and P0-1/P1-8 add spinners and P1-1 adds optimistic transitions, with **no `prefers-reduced-motion` handling** anywhere (WCAG 2.3.3).

**Affected files.** `_hero.tsx`, `_calendar-grid.tsx`, `index.tsx`, `design_styles/DESIGN_TOKENS.md`.

**UX/UI direction.** Pin the conflict banner and safety pills to fixed high-contrast token pairs (solid `bg-status-critical` + contrast-checked white) rather than primary-foreground/opacity; raise 9px pill text to ≥11px or add a bordered non-colour cue; pair every colour-coded MealCard state with the text/icon labels from P1-5. Add a shared `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2` (using `ring-ring`, light ring on the gradient) constant applied to hero, SubTabs, MealCard and Add buttons. Add a `motion-reduce:` rule (or a global `@media (prefers-reduced-motion: reduce)` block) that disables the `animate-ping` pulse and reduces spinner/transition animation to a static state. Document the contrast floor **and** the reduced-motion expectation in `DESIGN_TOKENS.md`.

**Acceptance criteria.** [ ] Safety pills/banner meet 4.5:1 regardless of brand hue; pill text ≥11px or bordered; keyboard focus clearly visible across hero/grid/tabs; colour never the sole signal on cards; with reduced-motion enabled the pulse dot and spinners do not animate.

**Effort.** M

---

#### P2-11. Progress/gauge bars get `progressbar`/`meter` semantics; budget bar stops faking $0
**Problem.** Budget bar, 7-col plan-completeness chart, shopping progress bar, and `StockGauge` are plain `<div>`s with width styles and (some) native `title` tooltips — no `role`/`aria-valuenow`, and the `title` tooltips are hover-only — inaccessible to keyboard and screen-reader users (WCAG 1.1.1/4.1.2). The budget panel computes against `budget ?? 0`, rendering "of NZ$0.00 planned" when no budget is set — looks like a configured zero budget.

**Affected files.** `_calendar-grid.tsx`, `_shopping-list-panel.tsx`, `_inventory-table.tsx`.

**UX/UI direction.** Add `role="progressbar"` (or `role="meter"` for stock) with `aria-valuenow/min/max` + an `aria-label` per bar (budget: `Budget ${pct}% used, ${money(remaining)} remaining`; shopping: `${ticked} of ${total} items collected`; stock: `${qty} ${unit} in stock, ${belowPar ? 'below par' : 'at or above par'}`). Replace `title`-only data with visible/aria text. When `budgetCents` is null, render the existing "Set a weekly budget" prompt and **suppress** the bar/`NZ$0.00`. Keep state colours but ensure each has its icon so state isn't colour-only. (Use "below par"/"par" terminology, consistent with the rest of the module.)

**Acceptance criteria.** [ ] Each bar exposes an accessible value/label; no hover-only data; unset budget shows the prompt, not a $0 track.

**Effort.** M

---

#### P2-12. Template preview legibility + builder ergonomics + safety summary
**Problem.** Template mini-grid recipe names are `text-[8.5px]` (labels `9px`) — unreadable, with the full name only in a desktop-hover `title`; applying a template overwrites the week (replace), so an illegible preview undermines a destructive, clinically-relevant choice. The builder's `h-4`/`h-3` steppers/clear-X and `min-w-[820px]` grid make editing in a narrow browser window error-prone (forced horizontal scroll). No template surface summarises allergen/IDDSI footprint, even though recipe tags + resident `allergen_tag_ids` are in the bootstrap.

**Affected files.** `_templates-panel.tsx`, `_hero.tsx`.

**UX/UI direction.** Raise recipe-name text to ≥`text-[11px]`/`text-xs leading-tight`, reduce visible recipes-per-day with a readable "+N more", keep day labels ≥`text-[10px]`; grow card height rather than shrink type. (Builder control sizing is covered by P1-3; also add `aria-label`s "Decrease/Increase servings", "Clear meal".) On narrow viewports stack the builder into a per-day vertical list below `md`. On each template card render a small chip row of distinct allergen tags (status-critical) + an "IDDSI-noted" marker; in the apply-confirm (P1-7), run `conflictsFor()` for each template entry against the site's residents and show *"Heads up: 2 meals conflict with residents' allergens."*

**Acceptance criteria.** [ ] Template names legible (≥11px); builder usable in a narrow/half-width window without nested horizontal scroll; cards show an allergen footprint; apply-confirm flags conflicts against current residents.

**Effort.** M

---

#### P2-13. Recipe "Add to shopping list" progress + partial-failure recovery
**Problem.** `addToShopping()` finds/creates a draft then POSTs each ingredient in a serial `for-of` loop with only a button-disabled state. A mid-loop failure leaves the list **partially populated** with a generic toast and no indication which ingredients landed — a worker shops believing it complete and misses a (possibly allergen-relevant) staple.

**Affected files.** `_recipes-panel.tsx`.

**UX/UI direction.** UI-only: show progress ("Adding 3 of 7…") with a spinner and disabled trigger; track failures; on completion toast a summary ("Added 7 items" / "Added 5 of 7 — 2 couldn't be added"); if any failed, keep the dialog open with an inline list of failures + a **"Retry remaining"** button. *(Transactional batch endpoint is Deferred.)*

**Acceptance criteria.** [ ] Progress shown during the loop; partial failures listed with a retry; success summarises counts.

**Effort.** M

---

#### P2-14. Dialog write-failure feedback: Adjust, Stocktake **and** Settings
**Problem.** `AdjustInventoryDialog.submit`, `StocktakeDialog`, **and `SettingsDialog`** call `router.post`/save with only `onSuccess`/`onFinish` — **no `onError`**. A failed save (validation/network/419) silently re-enables the button with no toast/inline error; the worker may assume success or double-submit. For `SettingsDialog` this is worse than cosmetic: P0-8, P2-3, P2-6 and P2-11 all depend on the **weekly budget** value, so a silently-failed budget save makes those surfaces show a wrong/zero budget that looks configured. Inconsistent with the inventory table's quick ±1 (which toasts).

**Affected files.** `_dialogs.tsx`.

**UX/UI direction.** Add `onError` to **all three** saves: fire a specific `toast.error` ("Couldn't save the adjustment — try again" / "Stocktake didn't save" / "Couldn't save settings — try again"), keep the dialog open, don't clear the form, and surface Inertia field errors inline (e.g. under `qty`, or under the budget field in Settings). For the negative-stock preview already styled `text-destructive`, add a caption "This sets stock below zero" (allow submit, or disable per policy).

**Acceptance criteria.** [ ] A failed adjust/stocktake/**settings** save shows an error + keeps the dialog/form; field validation surfaces inline.
- [ ] A failed weekly-budget save is visibly reported (not silent), so dependent surfaces don't render a false budget.

**Effort.** S

---

#### P2-15. Shopping list provenance + local tick-off resilience
**Problem.** `ShoppingList` carries `provider_key`, `provider_order_ref`, `ordered_at`, `received_at`, `notes` — **none rendered**; history rows show the raw DB `#id` instead. Tick-off is local-only React state (comment: "resets on reload"), lost on reload/navigation/re-bootstrap and invisible to the next worker — a split-shop handover loses collected/not-collected state, risking double-buying or missed allergen-safe staples.

**Affected files.** `_shopping-list-panel.tsx`, `_helpers.ts`.

**UX/UI direction.** In `HistoryRow`/`ViewListDialog`, surface `ordered_at`/`received_at` as en-NZ dates + `provider_key`/`provider_order_ref` when present (*"Received 2 Jun · Countdown · ref #CD-1183"*); drop the raw `#id`; localise `covers_from/to` to en-NZ. For tick-off: add a persistent caption *"Tick progress is in this browser only — click Mark received to save it."*, a `beforeunload`/Inertia nav guard while ticks differ from loaded `is_checked`, and persist the ticked Set to `localStorage` keyed by `list.id` (still client-only). *(Cross-device persistence + per-item received-qty are Deferred.)*

**Acceptance criteria.** [ ] Provenance/timestamps shown (no raw #id); tick-off survives a same-device reload; an unsaved-progress warning fires on navigation.

**Effort.** S–M

---

#### P2-16. Consolidate / deprecate the legacy library pages
**Problem.** `/catering/recipes`, `/catering/products`, `/catering/tags` still render `CateringTabs` and offer divergent, lower-fidelity editing of the **same** data now managed in-planner: products cost is raw **cents** ("Cost per unit (cents)", `products/index.tsx` line 238, column `cost_per_unit_cents`) vs the planner's dollars; tag colour is a hex `Input` vs a colour picker; legacy product-tag toggles lack `aria-pressed`. Since allergen/dietary tags drive the hard-conflict engine, a divergent surface invites mis-entered safety-critical data (e.g. "350" for $3.50). Also `recipes/edit.tsx` shows a wrong hero title ("Meal Planner — Cross-site overview…", lines 104–105) above a correct h2.

**Affected files.** `resources/js/pages/catering/_tabs.tsx`, `recipes/index.tsx`, `recipes/edit.tsx`, `products/index.tsx`, `tags/index.tsx`.

**UX/UI direction.**
1. Add a prominent Info banner to each legacy index: *"Recipes/Products/Tags are now managed inside the Meal Planner — open the planner to make changes."* + a button to `/catering`.
2. **Product cost — dollars input, cents payload (UI-only, server rule unchanged).** Relabel the legacy `cost_per_unit_cents` field to **"Cost (NZD)"** as a dollars input, but convert on submit so the request still sends an **integer number of cents** (`Math.round(dollars * 100)`) — the controller's integer-cents validation must remain untouched. If the existing request rule cannot accept the converted integer without change, **do not ship the relabel here — move it to Deferred** (it would then need a request-rule tweak, i.e. backend).
3. Add `aria-pressed` to legacy tag toggles.
4. **Tag colour input:** standardise the legacy hex `Input` to the same colour picker the in-planner tag manager uses (so the two surfaces match), with the hex value still submitted. (If unifying the picker proves to need shared-component work beyond UI, drop this to the deprecation banner only — but address it rather than leaving it half-mentioned.)
5. Remove the legacy entries from `CateringTabs`, leaving only Meal Planner, to kill the orphaned cross-links.
6. Fix `recipes/edit.tsx` hero copy (`title={isNew?'New recipe':`Edit ${recipe.name}`}`, description "Recipes are reusable across all sites for meal planning.", `ChefHat`) and remove the redundant secondary h2. No route/backend changes.

**Acceptance criteria.**
- [ ] Legacy indexes show a deprecation banner + link; `CateringTabs` lists only Meal Planner; the recipe-edit page shows a single correct title.
- [ ] Legacy product cost is entered in dollars **and the submitted payload is still integer cents with the server validation rule unchanged** (otherwise this sub-item is moved to Deferred).
- [ ] Legacy tag toggles expose `aria-pressed`; the legacy tag-colour input matches the in-planner colour picker (or is explicitly deferred if it needs shared-component work).

**Effort.** M

---

#### P2-17. Consistent product-cost unit across all three in-app entry points
**Problem.** P2-16 fixes the *legacy* page, but cost is entered in **three** places: the legacy products page (cents → being fixed), the in-planner `ProductsManagerDialog` (`_library-dialogs.tsx`), and `AdjustInventoryDialog` (`_dialogs.tsx`). Inconsistent cost units across these is the named safety/data-integrity risk (a "350" meaning $350 vs $3.50). Nothing currently verifies all three present cost the same way.

**Affected files.** `_library-dialogs.tsx`, `_dialogs.tsx` (and cross-checked against the P2-16 legacy fix).

**UX/UI direction.** Audit the three product-cost entry points and make them consistent: all display and accept **dollars (NZD)** in the UI while persisting whatever integer-cents shape the endpoint expects (same convert-on-submit pattern as P2-16, server rule unchanged). Use one shared label string ("Cost (NZD)") and the same input affordance/format hint in all three. Where any one cannot be made dollars-in/cents-out without a backend rule change, note it and defer that specific surface.

**Acceptance criteria.** [ ] `ProductsManagerDialog`, `AdjustInventoryDialog`, and the legacy products page all present product cost in the same unit (dollars) with the same label; each still submits the integer-cents payload its endpoint expects.

**Effort.** S

---

#### P2-18. Hover-revealed resident edit + hover-card data reachable by AT
**Problem.** The `ResidentChip` pencil is `opacity-0 group-hover:opacity-100` (appears on focus, but the hint says "pencil to edit" implying visual discoverability). The rich `MealHoverCard`/`ResidentHoverCard` portals are `pointer-events-none` with no ARIA role, so their clinical content (allergens, dislikes, texture, fluids, meal counts) is **inaccessible** to keyboard/AT (WCAG 1.4.13/1.3.1).

**Affected files.** `_calendar-grid.tsx`.

**UX/UI direction.** Make the pencil always visible at low emphasis (or on `focus-within`) and reword the hint ("Press Enter to spotlight · edit button to change diet"). Make hover-card data reachable: fold the key facts (allergens, IDDSI level, fluids) into the chip's `aria-label`, and/or render the same detail inside the keyboard-accessible `ResidentEditDialog` header so nothing is hover-only.

**Acceptance criteria.** [ ] Edit control is keyboard-discoverable; a resident's allergens/IDDSI/fluids are available to AT without hovering.

**Effort.** M

---

#### P2-19. Generate-shopping-list dialog: form a11y, write-failure feedback, empty-week guard
**Problem.** `ShoppingListGenerateDialog` is the entry point for the whole shopping workflow (`openBuildList`). Its *open* is covered by the P1-8 Suspense fix, but its own form is otherwise unassessed: no audited labels/focus order, and (consistent with the P2-14 gap) no confirmed `onError` on generate, so a failed generation can fail silently; behaviour on an **empty week** (nothing to generate from) is also unspecified.

**Affected files.** `_dialogs.tsx`.

**UX/UI direction.**
- Add/verify `onError` on the generate request: `toast.error` ("Couldn't generate the shopping list — try again"), keep the dialog open, surface field errors inline.
- Audit form a11y: every control labelled, logical focus order, initial focus on the first field, Escape/closes-cleanly, ≥24px (WCAG 2.5.8 AA) targets on any steppers/toggles.
- When the selected week has no meals to source ingredients from, show an explicit inline empty/disabled state (*"No planned meals this week to build a list from — plan meals first."*) rather than generating an empty list or doing nothing.

**Acceptance criteria.** [ ] A failed generate is reported and keeps the dialog open; the form is fully labelled/keyboard-operable; an empty week shows a clear message instead of silent/empty output.

**Effort.** S–M

---

#### P2-20. Hardened, AT-announced feedback channel
**Problem.** Mutation feedback is exclusively `sonner` toasts (announcement inconsistent across SR/browser combos); in-flight states disable buttons **without** `aria-busy`; `reportLoading` is plain DOM. SR/keyboard workers may miss "save failed" / "allergen check failed" — the same safety signal sighted users get.

**Affected files.** `index.tsx`, `_calendar-grid.tsx`, `_inventory-table.tsx`, `_dialogs.tsx`.

**UX/UI direction.** Add one visually-hidden `aria-live="assertive"` region in the orchestrator and mirror critical error toasts into it (save failed, conflict-check failed — see P0-1/P0-6, week cleared). Add `aria-busy` to buttons/containers while their request is in flight (`busyId` rows, `submitting` dialogs). Hardens existing feedback rather than adding new feedback.

**Acceptance criteria.** [ ] Critical errors are announced via a live region regardless of toast behaviour; in-flight controls expose `aria-busy`.

**Effort.** M

---

## Suggested implementation order

1. **P0-1 fail-closed allergen check** and **P0-2 visible load errors** + **P0-3 honest freshness** — land together; they remove the three "presents failure as all-clear" pathways and share the `loadError`/live-region scaffolding reused later.
2. **P0-6 conflict-banner a11y** (small, rides on P0-1's live region), then **P0-4 texture/IDDSI** + **P0-5 ad-hoc/fluids** + **P0-10 dietary-requirement advisory** (one pass over the same dialog/MealCard surfaces — all three add resident-aware advisories from data already in state).
3. **P0-7 / P0-8 / P0-9 empty-state clarity** — high "looks-broken" payoff, low risk; do before any dev verification so the demo house reads correctly.
4. **P1 accessibility scaffolding first:** P1-4 tablist + P1-5 calendar names + P1-12 inventory semantics + P1-11 popovers (establishes the ARIA patterns), then the **responsive-layout + control-sizing pass** P1-1/P1-2/P1-3 together (shared sizing tokens), then P1-7 confirms, P1-8 loading, P1-6 keyboard-move, P1-10 week-picker/slot, P1-9 draft recipes, P1-13 recipe-editor tag picker (safety-data entry; pairs with the P0-7/P1-9 recipe work).
5. **P2** by surface to minimise re-touching files: hero/oversight (P2-3, P2-5, P2-6, P2-7, P2-9); recipes/tags + severity (P2-4, P2-8, P2-13, P2-16); calendar/print/templates (P2-1, P2-11, P2-12, P2-18); shopping/inventory + dialog feedback (P2-14, P2-15, P2-19, P2-17); then cross-cutting visual/a11y (P2-10, P2-20, P2-2).
6. After each phase: `npm run types` + `npm run build`, then verify **both** modes on dev (Chrome MCP as `admin@demo.test`), re-seeding permissions on the server if a new panel 403s.

---

## Deferred (needs backend / out of scope)

These were flagged valuable but require new fields/endpoints/models and are **excluded from the phased UI/UX plan**. UI-only stopgaps for the first three are already folded into P2-7 / P2-13 / P2-15.

- **Per-resident structured intake log at serve time** *(needs_backend, L).* A structured per-resident outcome on each served meal — `{client_id, intake: full|partial|refused, substitute_note, fluids_note}` persisted on the plan entry — plus a serve-sheet UI listing each assigned resident with quick intake chips. Core to true intake/hydration recording for NDIS-style records and incident review, but `PlanEntry` has no per-client dimension (`served_at` is a single slot boolean). UI stopgap shipped in **P2-7** is a single **free-text** note on the existing `notes` field, explicitly *not* this structured model.
- **Blocking dietary-requirement rule** *(needs_backend).* P0-10 surfaces a UI *advisory* when a recipe's dietary tags don't satisfy a resident's `dietary_tag_ids`. Turning that into an authoritative hard/soft gate (like allergens) needs a server-side rule defining which tag combinations actually conflict (e.g. an authoritative halal/vegetarian taxonomy), not a client-side heuristic. Advisory shipped in **P0-10**; the gate is deferred.
- **Transactional batch "Add recipe to shopping list" endpoint** *(needs_backend).* The robust fix for the serial-loop partial-failure problem is an atomic batch write so the list can't end up half-populated. UI stopgap (progress + retry-remaining) shipped in **P2-13**.
- **Cross-device shopping tick-off persistence + per-item received quantities** *(needs_backend).* A real tick endpoint (so a split-shift shop syncs across workers' devices) and per-item `received_qty` entry on "Mark received" (so inventory reflects short deliveries) both need backend/state work. UI stopgap (localStorage + nav guard + provenance display) shipped in **P2-15**.
- **`served_by` display** *(needs_backend).* `served_by` **is** persisted server-side (`SiteMealPlanController::markServed` sets `served_by => auth()->id()`; unserve clears it), **but it is not exposed to the client** — the `PlanEntry` type in `_helpers.ts` has no `served_by` field, so the entries serializer (~line 517) would need to add it before any by-name "served by {worker}" attribution could render. The planned-vs-served reconciliation and serve-**time** display in **P2-7** rely only on the existing `served_at` and are in-scope; by-name attribution is deferred until the serializer includes the field.
- **Legacy product cost relabel, if it requires a request-rule change** *(conditionally needs_backend).* P2-16/P2-17 relabel cost to dollars as **UI-only**, converting to integer cents on submit so the existing controller validation is unchanged. If any of the three entry points has a request rule that can't accept the converted integer without modification, that specific relabel moves here (it would then be a backend rule change).
