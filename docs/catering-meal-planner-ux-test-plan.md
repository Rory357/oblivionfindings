# Meal Planner (/catering) — UX/UI Improvements: Dev Test Plan

**Purpose.** Fresh-context verification guide for the UX/UI gap-analysis work implemented from
[`docs/catering-meal-planner-ux-gap-analysis-and-plan.md`](catering-meal-planner-ux-gap-analysis-and-plan.md).
This change set is **UI/UX-only** (no schema/endpoint changes). Verify on the **dev server**.

- **App:** https://oblivionfindings.com — log in as `admin@demo.test`.
- **Deploy:** pushing to `main` auto-pulls **and** auto-builds on dev (~5–8 min). Wait for the build
  before testing; a stale bundle will not show these changes. Deploys **skip seeders** — this change
  set adds **no** new permissions/migrations, so no re-seed is normally needed (but see "403?" below).

## The two modes — both must work (shared component tree)

| Mode | URL | Chrome |
|---|---|---|
| **Standalone** (manager) | `/catering` | brand hero + site switcher + week-picker |
| **Embedded** (support worker) | `/sites/{id}?tab=meal-planner` | compact toolbar, no hero banner |

Every change below must be checked in **both** modes unless noted. Office (non-house) sites only get
Inventory / Shopping / Recipes.

## Demo-data quirks (so empty surfaces aren't mistaken for bugs)

- **Recipes are seeded `is_active=false`** → the Recipes tab can look empty on a fresh house. Activate
  one via `/catering/recipes` → tick **"Show drafts"** → open a recipe → **Active** toggle, or build a
  new one with **Add recipe**.
- **Tōtara House (site 9011) has 0 residents** → use it for the *zero-resident onboarding* tests; it will
  **not** exercise the allergen/texture conflict engine. For conflict/advisory tests, pick a **house with
  ≥1 resident and ≥1 active recipe** (use the site switcher). If none exists, add residents on the site
  profile and activate a recipe first.
- **403 on a panel?** A gated panel can 403 if a tenant is missing seeded permissions — re-run the
  relevant `*PermissionsSeeder --force` over SSH (`oblivion@oblivionfindings.com`). Not expected for this
  UI-only change, but listed for completeness.

## How to force the failure paths (P0 safety)

Most P0 tests need you to simulate a failed request. In Chrome DevTools:
- **Network tab → "Offline"** (or right-click a request → **Block request URL**) to fail
  `…/check-conflicts`, `…/bootstrap`, `…/meal-plan`, etc.
- Re-enable to confirm recovery / Retry buttons work.

---

## P0 — Safety integrity (highest priority)

### P0-1 — Allergen check fails CLOSED
1. House with residents → **Plan a meal** → pick a **recipe** + select residents.
2. With DevTools **blocking** `…/check-conflicts`, change the recipe/residents to trigger a re-check.
- [ ] A **critical** "Couldn't verify allergens for this meal — Do not save until allergens are verified"
      panel appears (not a clean dialog).
- [ ] **Save is disabled** and the button reads **"Re-check before saving"**.
- [ ] **"Retry check"** re-fires the request; unblocking the URL + Retry clears the error.
- [ ] A *real* hard allergen conflict still shows the existing override-gated flow unchanged.
- [ ] (Screen reader) the loading → failure/result transition is announced.

### P0-2 — Bootstrap / reload errors fail VISIBLY
1. Block `…/meal-planner/bootstrap`, reload `/catering`.
- [ ] A centered **error card** ("We couldn't load the meal planner" + **Try again**) shows — **not** a
      zeroed hero/calendar. 403 → "access" copy; 419/401 → "session expired" + **Reload page**.
2. Restore, then block `…/meal-plan/week-summary` and navigate weeks.
- [ ] An **amber "Showing data from before your last action"** strip + toast appears; last data stays.
- [ ] Standalone with no site selected shows a **"Select a site"** prompt (not an error).

### P0-3 — Honest freshness
- [ ] The hero eyebrow reads **"updated N min ago"** (or "refreshing…" mid-reload), never a permanent
      "updated just now". Verify in standalone.

### P0-4 / P0-5 — Texture / fluids / ad-hoc advisories
On a house whose residents have texture (IDDSI < 7) and/or fluids set:
- [ ] **Plan a meal** with such a resident → an amber **"Texture-modified diet"** advisory names each
      resident + IDDSI level (and fluids when set).
- [ ] The resident checklist row shows the **fluids** chip beside the IDDSI chip.
- [ ] **MealCards** with a texture-modified assignee show a **"Texture"** pill; hover card lists it.
- [ ] **Ad-hoc / Takeaway** meal with an allergic resident → the note **names** that resident +
      allergens (correct comma/"and" join for 1, 2, 3+); card shows a **"Check allergens"** pill.

### P0-6 — Conflict banner announced
- [ ] The hero/toolbar allergen banner is a solid high-contrast red, the **Review** button announces the
      count + destination, and the banner is spoken by a screen reader when it appears/changes.

### P0-7 — Recipes empty state (3-way)
- [ ] With a search/filter active and no matches → **"No recipes match your filters" + Clear filters**.
- [ ] Truly empty (no filters) → **"No recipes yet"** + **Add recipe** (managers) + draft hint.

### P0-8 — Zero-resident onboarding (use site 9011)
- [ ] House with 0 residents shows a dismissible **"Finish setting up this house…"** card with the
      ordered checklist (1 link-out to site profile, 2 **Set a weekly budget** → opens Settings,
      3 **Plan meals** → Calendar).
- [ ] The Calendar shows **"No residents linked — allergen & texture checks are paused…"**.
- [ ] A house *with* residents shows neither.

### P0-9 — No-sites empty state
- [ ] On a tenant with no sites, `/catering` shows a proper card (icon + heading + body + **Create a
      site** CTA), not a bare dead-end line.

### P0-10 — Dietary-requirement advisory
- [ ] Plan a meal for a resident with a dietary tag (e.g. halal/vegetarian) the recipe doesn't carry →
      amber **"Dietary requirement: … — confirm this meal meets it"** advisory; card shows a **"Diet
      check"** pill. Satisfying recipes show nothing. Save is **never** blocked by this.

---

## P1 — Workflow + accessibility

- [ ] **P1-1 Quick-serve + ⋯ menu:** hover/focus a MealCard → a circular **serve toggle** (one click
      serves/unserves, optimistic + toast) and a visible **⋯** button that opens the actions menu
      (also via right-click); both keyboard-reachable. Hidden when you can't plan.
- [ ] **P1-2 Responsive toolbar (embedded):** narrow the window to ~1024px / half-width → week-nav +
      actions stay on the first row, KPI chips scroll horizontally, the calendar is visible without
      scrolling past stacked chrome. Standalone hero unchanged.
- [ ] **P1-3 Control sizing:** shopping tick-offs, template steppers/clear-X are comfortably clickable
      (≥24px hit area).
- [ ] **P1-4 Tablist:** Tab to the sub-tabs → **Arrow keys** move + activate tabs; active pill is
      brand-tinted (not green); panel is associated.
- [ ] **P1-5 Calendar names:** (screen reader) each **Add** button says its day + slot; each MealCard's
      name includes day, slot, serves, and any conflict/override/served state.
- [ ] **P1-6 Keyboard move:** open a meal's ⋯ menu → **"Move to day/slot…"** → pick a new day+slot →
      the meal moves (no drag needed).
- [ ] **P1-7 Confirm destructive:** **Clear week**, **Apply template (replace)**, **Repeat last week**
      each show a confirm naming the affected meal count; the "Plan week" menu is keyboard-operable +
      closes on Escape.
- [ ] **P1-8 Loading:** clicking **Plan a meal** / **Generate** shows an "Opening…" spinner even on a
      throttled connection; week nav shows an "Updating…/refreshing" cue; initial load shows a skeleton.
- [ ] **P1-9 Drafts:** `/catering/recipes` → **"Show drafts"** reveals inactive recipes; the in-planner
      recipe editor exposes an **Active** toggle that persists.
- [ ] **P1-10 Embedded week-picker:** the embedded toolbar opens a **week picker** (arbitrary jumps) and
      shows **"Today"** only when off the current week; **Plan a meal** opens a *time-appropriate* slot
      and doesn't bounce you off a week that already contains today.
- [ ] **P1-11 Popovers:** the notification **bell** and shopping **Export** dropdown are keyboard/SR
      operable — announce open-state, Escape closes + restores focus, arrow keys move between items.
- [ ] **P1-12 Inventory semantics:** row icon buttons announce action + product; category pills expose
      pressed-state; table has a caption + column scopes.
- [ ] **P1-13 Recipe tag picker:** the recipe editor splits tags into a red **"Contains allergens"**
      group (with a "drives the safety check" helper line) and a neutral **"Dietary"** group; selected
      allergen tags read in red; toggles announce kind + pressed-state.

---

## P2 — Polish + oversight

- [ ] **P2-3 Honest served badge:** a fresh/zero-served week shows **no** green "0 meals served" — it
      reads "{served}/{planned} served" (neutral) or is omitted.
- [ ] **P2-5 Override audit:** a meal with an allergen override → hover card shows the **real quoted
      reason + "Approved by {name} · {date}"**; the hero/toolbar **overrides** badge opens an
      **"Allergen overrides this week"** dialog; each row deep-links to the meal.
- [ ] **P2-6 Spend report from hero:** the **Week cost** tile (hero) / KPI (toolbar) opens the spend
      report; the tile flags **over budget**; the budget panel shows a **cooked vs takeaway** split line.
- [ ] **P2-7 Planned-vs-served:** Meals tile reads "{served}/{planned} served"; hover card shows the
      **serve time**; the edit dialog has an explicit **"Mark not served"** when served, with serve
      separated from Delete, and the notes field relabelled **"Meal record (free-text note)"**.
- [ ] **P2-9 Overview signals:** texture-check / soft-warning / "N on texture-modified diet" surface in
      **both** hero badges and the toolbar signals row; the critical allergen banner stays dominant.
- [ ] **P2-4 / P2-8 Severity:** the recipe **detail** dialog shows a labelled red allergen row (or
      "No allergens tagged") separate from dietary; **critical**-severity allergens render filled/marked
      and sort first in the dialog hard-block list + hover line.
- [ ] **P2-13 Add-to-shopping:** "Add N to shopping list" shows **"Adding x of y…"**; partial failures
      list the failed items with **Retry remaining**; success summarises counts.
- [ ] **P2-16 Legacy pages:** `/catering/recipes|products|tags` each show a **deprecation banner →
      Open Meal Planner**; the catering tab bar lists **only Meal Planner**; legacy product cost is a
      **"Cost (NZD)"** dollars field; the recipe-edit page shows a single correct title.
- [ ] **P2-1 Print docs:** **Kitchen sheet** + **Shopping list** print docs use the **brand** colour (no
      green `#1f7a4d`); the Cook Sheet prints per-resident **texture + fluids + allergens** and override
      reason/author. (Re-brand via Settings → Branding changes the print accent.)
- [ ] **P2-11 Bars:** budget / stock / shopping bars expose accessible values; an **unset** budget shows
      the "Set a weekly budget" prompt, **not** a "$0.00" track.
- [ ] **P2-12 Templates:** template card recipe names are legible (≥11px) with "+N more"; cards show an
      **allergen footprint** chip row; **Apply** confirm flags **"Heads up: N meals conflict with
      residents' allergens"**.
- [ ] **P2-14 Write-failure feedback:** force a failed **Adjust** / **Stocktake** / **Settings** /
      **Generate list** save → an error toast shows, the dialog stays open, the form isn't cleared; a
      negative-stock preview shows "This sets stock below zero".
- [ ] **P2-15 Shopping resilience:** tick items on a draft list → **reload** → ticks survive (localStorage);
      leaving with unsaved ticks warns; history rows show **provenance** (received/ordered date +
      provider + ref), not a raw `#id`; dates are en-NZ.
- [ ] **P2-17 Cost unit:** product cost is entered in **dollars** consistently in the in-planner Products
      manager and the legacy products page.
- [ ] **P2-19 Generate dialog:** an **empty week** shows "No planned meals this week to build a list
      from…"; the form is labelled, first field focused; a failed generate is reported + keeps the dialog.
- [ ] **P2-2 Full-width:** `/catering` fills the screen with the standard gutter (grid/table edge-to-edge).
- [ ] **P2-10 Contrast / motion:** safety pills/banner stay high-contrast regardless of brand hue;
      keyboard focus is clearly visible on hero/grid/tabs; with **reduced motion** on (OS setting), the
      hero pulse dot + spinners don't animate.
- [ ] **P2-20 Live region:** with a screen reader, critical errors (save failed, week cleared, allergen
      check failed) are announced; in-flight controls expose `aria-busy`.

---

## Smoke / regression

- [ ] Standalone `/catering`: hero, week-picker, site switcher, all 5 sub-tabs render and switch.
- [ ] Embedded `/sites/{id}?tab=meal-planner`: compact toolbar, sub-tabs, calendar all render.
- [ ] Plan → serve → unserve → delete a meal; generate → tick → mark-received a shopping list;
      adjust + stocktake inventory; create/apply/delete a template — no console errors, toasts fire.
- [ ] Office (non-house) site shows only Inventory / Shopping / Recipes (no house-only logic leaks).

## Build status at hand-off

`npm run types` ✓ · `npm run build` ✓ · `eslint` ✓ (0 errors). Browser verification on dev is the open
item this doc covers.
