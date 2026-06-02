# Meal Planner / Catering — session handoff & Add-Recipe design audit

> **Purpose:** fresh-context handoff. Everything below reflects work done in a long
> session on **2026-06-02**, all merged to `main` and deployed to
> **oblivionfindings.com** (dev). Read this top-to-bottom before continuing.

---

## 0. UPDATE — §4 + §5 implemented (2026-06-02, later session)

Both remaining items below are now **built, type-checked, built (vite), and covered by
passing feature tests** (`tests/Feature/Catering/RecipeControllerTest.php` +
`LibraryManagerTest.php`, 38 Catering tests / 134 assertions green).

- **🔴 §4 Add-Recipe dialog — DONE.** `_recipe-edit-dialog.tsx` rebuilt to the design:
  single-column, `[Name | Category]`, Serves/Prep/Cook, **Availability tile-picker**
  (house/shared), full-width real-tag pills, `[1fr_72px_92px_36px]` ingredient rows
  (no Notes), Method-only, BookPlus/Pencil header + description, footer **Delete**
  (inline confirm). Backend: new `category` column migration
  (`2026_06_02_110000_add_category_to_meal_recipes`), `RecipeController` now writes
  `category`/`scope`/`site_id` (shared ⇒ `site_id=null`) and **guards
  `description`/`category`/`scope`/`site_id` with `$request->has()`** so the planner
  dialog and the legacy `/catering/recipes/edit` page don't clobber each other;
  `destroy` has a `wantsJson` branch. The dialog is now fed the already-loaded
  `RecipeFull` (no `/edit` re-fetch) and `category` is surfaced on cards + a filter row.
- **🟡 §5 Products + Dietary/Allergen-tag managers — DONE.** New
  `_library-dialogs.tsx` (`ProductsManagerDialog`, `DietaryTagsManagerDialog`): one
  dialog each with an internal list/form view, lazy-fetch on open, axios CRUD, refresh
  the planner via `bootstrap()`. Entry points: **"Manage products"** in the Inventory
  toolbar, **"Manage tags"** in the Recipes toolbar (gated by `products_manage` /
  the new `tags_manage` bootstrap permission). Backend: `Product`/`DietaryTag`
  controllers gained `wantsJson` branches on index/store/update/destroy. **Fixed a
  latent bug**: `DietaryTagController@store` did `$data['key']` which 500s when `key`
  isn't sent (the new dialog auto-slugs from the label) → now `($data['key'] ?? null)`.

> **CORRECTION to §4 #7 below:** the tag picker is **not** empty for lack of a seeder —
> `CateringSeeder` already seeds **25** dietary/allergen tags (incl. all 12 in the
> design). The local/dev DB simply hadn't run it (local had `dietary_tags=0`,
> `active_recipes=0`). Fix = **run `CateringSeeder` (`--force` on dev)**, not write a
> new seeder. The dialog already uses real `MealDietaryTag` IDs (correct for the
> allergen engine).

**Remaining / next:** verify visually on dev (recipe create/edit/delete + scope +
category; both managers); ensure dev ran the `category` migration + has tags seeded;
optional §8.5 (week-picker on the embedded toolbar). The old `/catering/products|tags`
pages + `CateringTabs` can now be retired.

---

## 1. TL;DR

The Meal Planner was moved to a clean `/catering` page, re-themed to the **brand
colour**, and the old "Catering hub" tabs were removed. Recipe create/edit and a
calendar week-picker were folded into the planner. **Two things remain:**

1. **🔴 Fix the "Add recipe" dialog to match the design handoff.** What's currently
   shipped (`_recipe-edit-dialog.tsx`) was copied from the *old* recipe page and is
   **missing Category, the Availability (house/shared) toggle, the correct layout,
   tag pills, Method-only copy, and a Delete action.** Needs frontend **and** backend
   work (a `category` column doesn't exist; `scope` isn't wired into the controller).
2. **🟡 Finish the "merge":** fold **Products** and **Dietary & Allergen tags**
   management into the planner (no design reference for these — follow the popup style
   guide). Recommendation: **do #1 first** (user-visible & wrong), then #2.

---

## 2. What shipped this session (all on `main`, deployed)

| Area | Change | Key commit |
|---|---|---|
| **Colour** | Meal planner now uses the **brand `--primary`**, not the Sites green. The bare `--sites`/`--sites-deep`/`--sites-bg` family (used *only* by the meal planner) was repointed to `--primary` in `app.css` (light+dark). Hero gradient base → `--primary`; `--shadow-hero`/`--shadow-float` brand-derived; the one literal-green "live" dot → `bg-status-success`. Applies to **both** the embedded site-profile planner and `/catering`. The real Sites module (`--category-sites`) is untouched. | `8ca110d6` |
| **Breadcrumbs** | All `/catering*` pages lead with **Sites & Locations** (matches the active sidebar module — Meal Planner stays nested there, not its own module). The planner breadcrumb is `Sites & Locations › Meal Planner`. | `8ca110d6` |
| **`/catering` page** | Leads with the **hero**, then the planner's own sub-tabs (Calendar/Inventory/Shopping/Recipes/Templates) — **no `CateringTabs` hub row** (user called it "old duplicated stuff"; it doubled the Recipes sub-tab). Matches the Rostering page layout & the embedded site-profile planner. | `eed49581` |
| **Overview retired** | Removed the cross-site Overview: its route (`/catering/overview`), `DashboardController@index`, `catering/dashboard.tsx`, and the `overview` tab in `_tabs.tsx`. | `889f786f` |
| **Recipes folded in** | `_recipe-edit-dialog.tsx` — "Add recipe"/"Edit recipe" in the Recipes sub-tab open an **in-planner axios dialog** (no nav to `/catering/recipes/*`). `RecipeController@edit/store/update` gained `wantsJson()` branches. **⚠ Does NOT match the design — see §4.** | `889f786f` |
| **Week-picker** | The hero week button opens the **same month-calendar week-picker as Rostering** (`components/rostering/week-picker.tsx`). Added an optional `showContextMenu` prop (default `true`, so Rostering is unchanged) and the meal planner passes `false` to hide the rostering-only right-click menu. | `0280811d` |

**Verified on dev (Chrome MCP, admin@demo.test):** brand colours, breadcrumbs,
clean page, week-picker calendar, and recipe create+edit round-trip all work. A test
recipe **"Spaghetti Bolognese"** was created on dev during verification (harmless;
delete if unwanted).

---

## 3. Architecture (where things live)

The planner is **one shared component, dual-homed** — both must keep working:

- **Embedded** in the Site profile — `resources/js/pages/sites/show.tsx`,
  `?tab=meal-planner`, renders `<MealPlannerSubTabs site={...} />` (default
  `mode="embedded"` → compact `MealPlannerToolbar`, no banner). **This is the support
  worker's task workspace — do not remove or make standalone-only.**
- **Standalone** at `/catering` — `resources/js/pages/catering/meal-planner.tsx`,
  renders `<MealPlannerSubTabs mode="standalone" defaultSiteId=… />` → full
  `MealPlannerHero` + site switcher + week-picker.

Shared component: `resources/js/pages/sites/meal-planner/index.tsx` (`MealPlannerSubTabs`).
Sub-components in that folder: `_hero.tsx`, `_calendar-grid.tsx`, `_inventory-table.tsx`,
`_recipes-panel.tsx`, `_shopping-list-panel.tsx`, `_templates-panel.tsx`, `_dialogs.tsx`,
`_recipe-edit-dialog.tsx` (new), `_helpers.ts`.

Data: the planner bootstraps from `GET /sites/{site}/meal-planner/bootstrap` which
returns `recipes`, `products` (thin: `{id,name,default_unit}`), `product_categories`,
`clients`, `templates`, `sites`, `iddsi_levels`, `dietary_tags` (thin: `{id,label,kind}`),
`permissions`. Mutations are **axios + sonner toast**, then re-`bootstrap()` to refresh.

Catering library pages still exist (folded-in recipes no longer link to them):
`/catering/recipes` (index/show/edit/create), `/catering/products`, `/catering/tags`.
They still use `CateringTabs` (`resources/js/pages/catering/_tabs.tsx`) among themselves.

---

## 4. 🔴 Add-Recipe dialog — design-fidelity audit

**Design source of truth:** `C:\Users\steph\Downloads\meal_planner_extract\design_handoff_meal_planner\`
→ `mp/recipes.jsx` (`RecipeFormDialog`, ~line 216-372) + `README.md`. It's a
React-via-Babel prototype — recreate with the app's shadcn/ui components & tokens,
**don't copy literally**. Follow `docs/POPUP_STYLE_GUIDE.md`.

> **Colour note:** the design shows the *green* Sites accent. **Keep our brand
> `--primary`** instead — the user explicitly chose brand colour over green. Match
> *layout/anatomy/copy*, not the green.

**Currently shipped:** `resources/js/pages/sites/meal-planner/_recipe-edit-dialog.tsx`
(copied from the old `catering/recipes/edit.tsx`). **Gaps vs the design:**

| # | Design wants | Current dialog | Backend? |
|---|---|---|---|
| 1 | **Category** select (`Mains, Breakfast, Soups, Baking, Sides, Desserts`) in a `[1fr_160px]` row beside Name | ❌ none | **No `category` column on `meal_recipes`** → new migration + `$fillable` + `RecipeController::validateInput` + store/update |
| 2 | **Availability** segmented toggle: `[This house ⌂ \| Shared library ▣]` + helper text ("Only this house sees this recipe." / "Added to the org-wide library…") | ❌ none (everything saves as default `shared`) | `scope` + `site_id` columns **exist** but `RecipeController` ignores them → add to validate + store/update; dialog must send `scope` and (for house) `site_id` = current site |
| 3 | Header **description**: "Recipes power meal planning and the stock check. Link ingredients to inventory products to track stock automatically." | ❌ none | — |
| 4 | Header icon **BookPlus** (new) / **Pencil** (edit), tone = sites/brand | ❌ uses `ChefHat` | — |
| 5 | **Method (optional)** textarea only | ❌ has *both* a "Description" field **and** "Instructions" | drop the Description field from the form (DB column can stay unused) |
| 6 | **No** "Active" checkbox | ❌ has an Active checkbox | design omits it — remove or hide |
| 7 | **Dietary & allergen tag pills**, full-width below Availability (12 in design: Vegetarian, Vegan, Dairy, Gluten, Eggs, Fish, Tree nuts, Peanuts, Shellfish, Soft diet, Halal-ready, Diabetic-friendly) | ❌ tags in a right-hand column; shows **"No tags yet."** | The form correctly uses **real `MealDietaryTag` IDs** (needed for the allergen engine) — it's empty because the demo tenant has **no tags seeded**. Fix = seed the standard tags (or create via `/catering/tags`). Also move tags to full-width below Availability. |
| 8 | **Single-column** flow: `[Name \| Category]` → `[Serves \| Prep \| Cook]` → Availability → Tags → Ingredients → Method | ❌ 3-col grid (fields left, tags right) | — |
| 9 | Ingredient rows `[1fr_72px_92px_36px]` = Product \| qty \| unit \| ✕; custom rows show a "Custom item name (row N)" line; product empty-option label = "Custom item (not tracked)" | ❌ 12-col grid with an extra **Notes** column; empty option "— Free text —" | design has **no per-ingredient Notes** — drop it |
| 10 | Name placeholder "e.g. Creamy chicken & leek pie"; autofocus | ❌ no placeholder | — |
| 11 | Footer `justify-between`: **Delete** (ghost/critical, edit only) on left; Cancel + **"Add recipe"/"Save recipe"** (loader on submit) on right | ❌ Cancel + "Create recipe"/"Save changes", no Delete | wire delete → `DELETE /catering/recipes/{id}` (add `wantsJson` branch) |
| 12 | Validation: name **and ≥1 ingredient with a name** | ❌ name only | — |
| 13 | Units: `each, kg, g, L, ml, pack, tin, bottle, bunch` | ❌ `each, kg, g, L, ml, tsp, tbsp, cup, pack, tin` | align to design list |
| 14 | Dialog width ~680px | `sm:max-w-3xl` (~768) | minor |

**Backend work required for the recipe dialog:**
- **Migration:** add `category` (nullable string) to `meal_recipes`; add to `MealRecipe::$fillable`; add to `RecipeController::validateInput` + store/update writes.
- **Scope:** add `scope` (`house`/`shared`) + `site_id` to `validateInput` + store/update (columns already exist; model already has `scopeVisibleToSite`).
- **Delete:** add a `wantsJson()` branch to `RecipeController::destroy` for in-planner delete.
- **Seed** the 12 standard `MealDietaryTag`s (so the tag picker isn't empty) — check `database/seeders/CateringSeeder.php` / add a tags seeder; remember **deploys skip seeders** (see §7).

---

## 5. 🟡 Remaining: Products + Dietary-tag managers

The user wants these folded into the planner too ("merge it dont use the old stuff").
**No design reference exists for these in the handoff** (it covers the planner:
calendar/inventory/shopping/recipes/templates — not the global catering *library*
admin). Build them consistent with `docs/POPUP_STYLE_GUIDE.md` and the recipe dialog.

Pattern (same as recipes): add a `wantsJson()` fetch (or enrich bootstrap) + an axios
dialog reusing the planner's loaded data, refresh via `bootstrap()`.
- **Products** → `ProductController` (`store` already returns JSON; `update`/`destroy`
  redirect — add `wantsJson`). Planner only loads thin products today; a manager needs
  full fields (category, pack, cost, barcode, tags).
- **Tags** → `DietaryTagController` (add `wantsJson` to store/update/destroy).
- Entry points: e.g. "Manage products" / "Manage dietary tags" from within the planner
  (Inventory tab / Recipes area), **not** a competing top tab row.

Once folded in, the standalone `/catering/products` & `/catering/tags` pages can be
deprecated/removed (and `CateringTabs` likely deleted entirely).

---

## 6. Key files

```
resources/js/pages/catering/meal-planner.tsx          # /catering standalone page (clean)
resources/js/pages/catering/_tabs.tsx                 # CateringTabs (library pages only now)
resources/js/pages/sites/meal-planner/
  index.tsx            # MealPlannerSubTabs (shared; embedded + standalone)
  _hero.tsx            # MealPlannerHero (standalone) + MealPlannerToolbar (embedded); week-picker wired here
  _recipes-panel.tsx   # Recipes sub-tab; opens _recipe-edit-dialog
  _recipe-edit-dialog.tsx   # 🔴 the dialog to bring up to design (§4)
  _helpers.ts          # types: RecipeFull, RecipeIngredient (qty/name), etc.
resources/js/components/rostering/week-picker.tsx     # reused calendar (now has showContextMenu prop)
resources/css/app.css                                 # --sites family -> --primary (lines ~215, ~306); shadows ~127
app/Http/Controllers/Catering/RecipeController.php     # wantsJson on edit/store/update; needs category+scope
app/Http/Controllers/Catering/DashboardController.php  # libraryCounts + mealPlanner (index removed)
app/Http/Controllers/Catering/{Product,DietaryTag}Controller.php
app/Models/MealRecipe.php                              # fillable has scope/site_id; NO category
database/migrations/2026_05_17_120004_create_meal_recipes_table.php
database/migrations/2026_06_02_100004_add_scope_to_meal_recipes.php
routes/catering.php
```

---

## 7. Conventions & gotchas

- **Brand colour, not green.** The planner is intentionally `--primary`. Don't revert
  to the design's green. `--sites*` tokens resolve to `--primary` inside the planner.
- **Deploy:** push to `main` → webhook auto-pulls **and auto-builds** on the server
  (~1.5–8 min). Detect "live" by polling `https://oblivionfindings.com/build/manifest.json`
  until the relevant chunk hash (e.g. `resources/js/pages/catering/meal-planner.tsx`)
  changes. **Deploys skip seeders** — run any new `*Seeder --force` over SSH for
  permission/tag data, or it 403s/looks empty on dev.
- **Verify** UI on dev via Chrome MCP logged in as `admin@demo.test` (the local Herd
  app `oblivionfindings.test` was not reachable from the sandbox this session).
- **Branch flow:** feature branch → `git merge --no-ff` into `main` → push (matches repo
  history). `public/build` is gitignored.
- **axios + Inertia:** Laravel auto-returns **422 JSON** on validation failure for
  axios requests (Accept: application/json), so dialogs can `catch` and show errors;
  success currently follows a redirect → add `wantsJson()` branches for clean JSON.
- **Pre-deploy checks:** `npm run types` (tsc) + `npm run build` (vite). `vite build`
  doesn't type-check, so run both.
- **App reference docs:** `docs/MEAL_PLANNER_PLAN.md`, `docs/POPUP_STYLE_GUIDE.md`,
  `docs/DESIGN_TOKENS.md`, `docs/GOVERNANCE_HERO_GUIDE.md`.
- **Memory:** see `memory/project_meal_planner_redesign.md`.

---

## 8. Suggested order for the next session

1. **Backend for recipes:** migration `add_category_to_meal_recipes`; extend
   `RecipeController` (category + scope + site_id in validate/store/update; `wantsJson`
   on destroy). Seed/verify the 12 dietary tags.
2. **Rebuild `_recipe-edit-dialog.tsx`** to the §4 spec (single-column; Name+Category;
   Serves/Prep/Cook; Availability segmented; full-width tag pills from real tags;
   ingredient rows w/o Notes; Method-only; Delete in footer; correct copy/icon/validation).
3. Verify create/edit/delete + scope + category on dev.
4. **Products + Dietary-tag managers** folded into the planner (§5).
5. (Optional) add the week-picker to the **embedded** site-profile toolbar too, for parity.
