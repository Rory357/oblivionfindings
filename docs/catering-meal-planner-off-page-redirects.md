# Meal Planner (`/catering`) — "Everything on /catering" containment issues

**Created:** 2026-06-03 · from a UX/UI verification pass of the catering / meal-planner work.
**Updated:** 2026-06-03 · **Issues 1 & 2 implemented; Issue 3 kept by deliberate decision.**

## Why this file exists (read me first)

**Goal:** the Meal Planner should live **entirely on `/catering`**, opening everything in
in-page dialogs/overlays, with **no redirects out to other pages**.

During verification, the planner's own action buttons were all confirmed to stay in-page
(Plan a meal, Build list, Spend report, Kitchen sheet = `window.print()`, Settings gear,
resident ✏️ editor, override-audit, and the sub-tabs Calendar/Inventory/Shopping/Recipes/Templates).

Three deviations from the single-page goal were found. They were **not bugs** (the app worked);
they were product decisions. All three are now resolved:

| # | Deviation | Decision | Code change |
|---|---|---|---|
| 1 | Onboarding "Add residents" link navigated off to `/sites/{id}` | Keep `/catering` put — open the site profile in a **new tab** | ✅ Done |
| 2 | Legacy `/catering/recipes\|products\|tags` were standalone pages | **Redirect** the pages into `/catering` (keep their JSON API) | ✅ Done |
| 3 | Embedded "support-worker" planner at `/sites/{id}?tab=meal-planner` | **Keep as designed** — intended dual-homing | — (no change) |

A separate, unrelated **405 routing bug was found and FIXED earlier** — see the bottom of this
file for reference only.

### What changed (files)

- `resources/js/pages/sites/meal-planner/index.tsx` — onboarding step 1 now opens a new tab; dropped the unused `Link` import.
- `app/Http/Controllers/Catering/RecipeController.php` — `index`/`show`/`create`/`edit` page renders → redirect to `catering.meal-planner` (JSON branch of `edit` preserved); store/update/destroy non-JSON redirects repointed to the planner; removed two now-unused model imports.
- `app/Http/Controllers/Catering/ProductController.php` — `index` page render → redirect (JSON branch preserved).
- `app/Http/Controllers/Catering/DietaryTagController.php` — `index` page render → redirect (JSON branch preserved).
- `tests/Feature/Catering/RecipeControllerTest.php`, `tests/Feature/Catering/LibraryManagerTest.php` — added redirect + JSON-still-works coverage.

## How to verify (access — for the live re-check after deploy)

These changes are local until **merged to `main` → deployed** ([[reference_environments]]). No new
permissions were added, so there's no seeder step ([[reference_deploy_seeders]] not applicable here).

- **App:** https://oblivionfindings.com — log in as **`admin@demo.test`** in Chrome.
  - You **cannot** authenticate yourself (entering passwords is a hard boundary). Ask the user
    to click **Log in** in the Chrome window (the form is pre-filled). Sessions time out, so you
    may need to ask again mid-run.
- This is a **deployed remote server**, so drive it with the **Claude-in-Chrome MCP** tools
  (not the local preview/dev-server tools).
- Handy sites for repro:
  | Site | URL | Notes |
  |---|---|---|
  | Aroha Respite | `/catering?site=9010` | **0 residents** → shows the onboarding card (Issue 1) |
  | Tōtara House | `/catering?site=9011` | 0 residents (alt onboarding) |
  | Hilltop House | `/catering?site=9007` | 14 residents |

---

## Issue 1 — Onboarding "Add residents" link redirected to the Site profile · ✅ FIXED

- **Where:** `resources/js/pages/sites/meal-planner/index.tsx`, the `ZeroResidentOnboarding`
  card (shown on any house with **0 residents** at e.g. `/catering?site=9010`) → checklist
  item **1. "Add residents & their dietary needs."**
- **Was:** step 1 used an Inertia `<Link href={`/sites/${siteId}`}>` — a full client-side
  navigation that took the user **off `/catering`** to the Site profile page (`/sites/{id}`).
  This was the only in-content redirect out of the planner, and it was the natural first-run step.
- **Decision:** keep `/catering` loaded — **open the site profile in a new browser tab.**
  - *Why new-tab and not an in-page dialog:* linking residents to a house is a **Sites-module**
    operation (there is no in-planner "add resident" flow), so a new tab is the proportionate
    containment fix. A future in-page **"link residents" dialog** remains the ideal end-state.
- **Change:** the `<Link>` is now `<a href={`/sites/${siteId}`} target="_blank"
  rel="noopener noreferrer">`; the helper text reads **"— opens the site profile in a new tab"**;
  the `ArrowUpRight` icon already signals "opens elsewhere." Removed the now-unused `Link` import.
- **Verification:** `tsc --noEmit` clean; ESLint 0 errors. **Live re-check (after deploy):** open
  `/catering?site=9010`, click step 1 → site profile opens in a **new tab** and the `/catering`
  tab stays put. The onboarding card auto-hides once the house has residents.
- **Status:** [x] decided  [x] changed  [x] verified (build) · [ ] verified (live, post-deploy)

---

## Issue 2 — Legacy `/catering/recipes`, `/catering/products`, `/catering/tags` were separate pages · ✅ FIXED

- **Where:** `routes/catering.php:29-73` registered standalone `recipes` / `products` / `tags`
  GET pages **and** their `POST`/`PUT`/`DELETE` API endpoints.
- **Was:** the GET pages were **full standalone Inertia pages** (with a deprecation banner),
  reachable by URL/bookmark, duplicating the in-planner **Recipes / Inventory** library tabs.
- **Key constraint discovered:** the in-planner dialogs **depend on these same endpoints as a
  JSON API** — so a blanket route-level redirect would have broken the planner:
  - `_recipe-edit-dialog.tsx` → `POST/PUT/DELETE /catering/recipes`
  - `_library-dialogs.tsx` → **`GET /catering/products` and `GET /catering/tags` (JSON)** + their
    `POST/PUT/DELETE`
  - The controllers already content-negotiate on `wantsJson()`.
- **Decision:** **redirect the page (browser/Inertia) requests** into `/catering`, while **keeping
  the `wantsJson()` JSON branches** the planner relies on. Done at the **controller level** (a
  route-level `Route::redirect` was deliberately *avoided* — it would have killed the JSON).
  - `RecipeController@index|show|create` → `redirect()->route('catering.meal-planner')`.
  - `RecipeController@edit` → JSON branch preserved; the page branch redirects.
  - `RecipeController@store|update|destroy` → non-JSON redirects repointed to the planner.
  - `ProductController@index` / `DietaryTagController@index` → JSON branch preserved; page branch redirects.
- **Verification:** `tests/Feature/Catering/RecipeControllerTest.php` and `LibraryManagerTest.php` —
  added cases asserting the page GETs **302 → `catering.meal-planner`** *and* that the JSON
  endpoints still return 200. **13/13 pass.** **Live re-check (after deploy):** visiting
  `/catering/recipes`, `/catering/products`, `/catering/tags`,
  `/catering/recipes/{id}`, `/catering/recipes/{id}/edit` now lands on `/catering`.
- **Follow-up (optional cleanup, not blocking):** the now-**unreachable** standalone page
  components are dead and can be deleted later — `resources/js/pages/catering/recipes/{index,show,edit}.tsx`,
  `catering/products/index.tsx`, `catering/tags/index.tsx`, and the `LibraryDeprecationNotice`
  helper in `resources/js/pages/catering/_tabs.tsx` (the `CateringTabs` nav + `/catering/library-counts`
  badge fetch are still used by the standalone `/catering` shell, so leave those).
- **Status:** [x] decided  [x] changed  [x] verified (tests)

---

## Issue 3 — Embedded ("support worker") mode on `/sites/{id}?tab=meal-planner` · ✅ KEPT (by decision)

- **Where:** the **Meal Planner tab on the Site profile** (e.g. `/sites/9007?tab=meal-planner`),
  wired via `resources/js/pages/sites/meal-planner/index.tsx:304` (`router.visit('/sites/{id}',
  { data: { tab: 'meal-planner' }})`) + `routes/sites.php` + `resources/js/pages/sites/show.tsx`.
- **What it is:** the planner is **dual-homed** — standalone `/catering` (manager view) **and**
  an **embedded** compact-toolbar version inside the Site profile (support-worker view).
- **Decision:** **keep as designed.** This is **intended dual-homing** per the project spec
  ([[project_meal_planner_redesign]]): the embedded view is a deliberate support-worker entry
  point, not an oversight. No code change.
- **Status:** [x] decided (keep)  [—] changed (n/a)  [x] verified (unchanged)

---

## Quick re-check checklist (live, after deploy)

- [ ] Log in to https://oblivionfindings.com as `admin@demo.test` (ask user to click Log in).
- [ ] **Issue 1:** `/catering?site=9010` → onboarding "Add residents" → opens `/sites/9010` in a
      **new tab**; the `/catering` tab stays put.
- [ ] **Issue 2:** open `/catering/recipes`, `/catering/products`, `/catering/tags` (and
      `/catering/recipes/{id}`, `.../{id}/edit`) → each **redirects to `/catering`**.
- [ ] **Issue 2 (regression):** in the planner, open the **Recipes** tab + a recipe editor, and
      the **Inventory** library dialogs (products/tags) → they still load and save (the JSON API
      still works behind the redirects).
- [ ] **Issue 3:** `/sites/9007?tab=meal-planner` → still renders the embedded planner (kept).
- [ ] Bonus sweep: on `/catering?site=9007`, confirm the planner action buttons (Plan a meal,
      Build list, Spend report, Kitchen sheet, Settings ⚙, resident ✏️) still all open as in-page
      dialogs and don't navigate away. (All were in-page as of 2026-06-03.)

---

## Reference only — the separate 405 bug (already FIXED, not part of the 3 issues)

For context if it resurfaces: several planner writes used to fail with **HTTP 405** because
`PUT`/`DELETE` requests were hitting the `/catering` page route (GET/HEAD only) instead of their
API endpoints — e.g. *"The DELETE method is not supported for route catering."* This affected
**move meal, delete meal, resident dietary-save, and override-meal persistence**. It was **fixed
and re-verified on 2026-06-03** (DELETE meal → 200, PUT move → 200, PUT dietary → 200). Listed here
only so a future reader doesn't confuse it with the 3 containment items above.
