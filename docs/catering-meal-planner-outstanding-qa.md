# Meal Planner (`/catering`) — Outstanding QA / work to complete

**Created:** 2026-06-03 · remaining verification after the UX/UI gap-analysis work, the 405 routing
fix, and the 3 "everything on `/catering`" containment fixes were all **completed and verified live**.

Companion docs:
- `docs/catering-meal-planner-ux-test-plan.md` — the full original test plan (P0/P1/P2 IDs used below).
- `docs/catering-meal-planner-off-page-redirects.md` — the 3 containment issues (all ✅ done/verified).

## Why this file exists (read me first — written for a fresh session)

The feature is **functionally solid and core-safety-verified** (see "Already done" below). What remains
are **polish / accessibility items and flows that couldn't be exercised** because the demo data or the
test tooling didn't allow it. **None are known-broken** — they're *unverified*, not *failing*. This file
is the punch-list to take it from "ship-able with follow-ups" to "fully signed off."

## ✅ Already done — do NOT re-test (verified 2026-06-03, live)

- **405 routing bug** FIXED — create/serve/**move**/**delete** meal, **dietary save**, override-persist all 200.
- **3 containment fixes** — Issue 1 (onboarding "Add residents" opens site profile in a **new tab**,
  `/catering` stays), Issue 2 (`/catering/recipes|products|tags|recipes/{id}/edit` redirect → `/catering`
  with JSON branches still 200), Issue 3 (embedded `/sites/{id}?tab=meal-planner` kept by decision).
- **P0:** P0-1 (allergen fail-closed + real override flow), P0-2 (visible bootstrap/week errors),
  P0-3 (freshness), P0-4 (texture), P0-5 (ad-hoc), P0-7 (recipes empty: no-match), P0-8 (zero-resident
  onboarding), P0-10 (dietary advisory).
- **P1:** P1-1 (quick-serve), P1-4 (tablist), P1-5 (card aria-labels), P1-6 (move), P1-7 (Clear-week
  confirm), P1-10 (embedded week-picker), P1-11 (bell popover a11y), P1-12 (inventory table caption +
  column scopes + pressed pill — *row icon-buttons still untested*).
- **P2:** P2-3 (served badge), P2-4/P2-8 (severity tags: INFO/WARN/CRITICAL), P2-5 (override audit),
  P2-6 (spend report dialog), P2-9 (overview signals), P2-11 (unset budget), P2-14 (write-failure
  feedback), P2-16 (legacy deprecation), P2-19 (generate dialog).

## 🔎 Source-code audit + fix (2026-06-03, later same day)

A read-through of the source for **every** outstanding item below (not a live run — see access
constraints) found that **14 of the 15 are already implemented in code**; they are awaiting *live*
verification, not code. Exactly **one genuine code gap** was found and **fixed**:

- **P2-20(c) — save-failure announcements.** Inventory adjust / stocktake / week-cleared already
  mirrored failures into the assertive live region, and `aria-busy` was present on in-flight buttons —
  but the **meal-save** path (`PlanEntryDialog`) announced *nothing* on a generic save failure (and
  showed no toast), and the **Generate-list** + **Save-budget** paths toasted without mirroring to the
  announcer. **Fixed** in `resources/js/pages/sites/meal-planner/_dialogs.tsx` — all four write-failure
  paths now call `toast.error(...)` **and** `announce(...)`. `tsc --noEmit` ✓ · eslint 0 errors ✓.

**Code-confirmed present (need live verification only):** P0-6 (red `role=alert aria-live=assertive`
banner gated on `unresolved>0`, descriptive Review aria-label, in both hero + embedded toolbar) ·
P1-2 (embedded toolbar: `md:contents` reflow, KPI row `overflow-x-auto`) · P1-3 (tick = `h-6 w-6`/24px,
steppers `h-7 w-7`/28px) · P1-8 (DialogFallback "Opening…" spinner, week-nav "Updating…" cue, skeleton) ·
P1-12 rows (per-row `aria-label`s incl. product name) · P2-1 (print docs read `--primary`, `#1f7a4d` is
fallback-only; Cook Sheet prints per-resident texture/fluids/allergens + override reason+author) ·
P2-2 (`PageLayout width="full"`, no `max-w` cap) · P2-7 (edit dialog "Mark not served", serve split from
Delete, "Meal record (free-text note)" label, hover serve-time) · P2-10 (`@media (prefers-reduced-motion)`
in `resources/css/app.css` disables `animate-ping/spin/pop/pulse`; safety colours hardcoded high-contrast,
not brand-derived) · P2-12 (recipe names `text-[11px]` + "+N more", allergen-footprint chip row,
"Heads up: N meals conflict…" apply-confirm) · P2-13 ("Adding x of y…", failed-item list + "Retry
remaining", success summary) · P2-15 (localStorage tick persistence, `beforeunload` guard, provenance
rows, `toLocaleDateString('en-NZ')`) · P2-17 (both product forms: "Cost (NZD)", `step=0.01`, ×100→cents).
**Runtime-only blockers remain** for P0-6 (needs a seeded unresolved conflict), P1-2 (needs a real resize),
P2-10 (needs the OS `prefers-reduced-motion` toggle) and P0-9 (needs a zero-sites tenant).

## How to verify (access)

- **App:** https://oblivionfindings.com — log in as **`admin@demo.test`** in Chrome.
  - You **cannot** authenticate yourself (entering passwords is a hard boundary). Ask the user to click
    **Log in** (form is pre-filled). Sessions time out — you may need to ask again mid-run.
- This is a **deployed remote server** → drive it with the **Claude-in-Chrome MCP** tools.
- Demo sites: **Hilltop House** `?site=9007` (14 residents), **Aroha Respite** `?site=9010` (0 residents,
  onboarding), **Tōtara House** `?site=9011` (0 residents). Recipes are seeded `is_active=false` → only
  1–2 show active by default.

## ⚠️ Prerequisite — demo data that must be seeded first (several items below need it)

Pick one house (e.g. Hilltop 9007) and seed:
1. **Inventory/stock** — add several products with stock levels (for P1-12 row buttons, P2-15 history).
2. **A saved week template** — Plan week → "Manage templates & budget…" or save the current week (P2-12).
3. **A generated shopping list** with some items (Build list → Generate) and tick a few (P2-13, P2-15).
4. **One unresolved allergen conflict** — plan a meal with a resident, then (via the resident ✏️ editor)
   add an allergen the meal's recipe contains, so the meal conflicts **without** an override on file (P0-6).
   *(Remember to revert the resident afterward — the dietary editor saves fine now.)*

---

## A. Close before production sign-off (safety / accessibility)

### P0-6 — Unresolved-conflict banner (hero/toolbar)
- **Test:** with an unresolved allergen conflict present (see seeding #4), the hero/toolbar shows a
  **solid high-contrast red** allergen banner; the **Review** button announces the count + destination;
  a screen reader speaks the banner when it appears/changes.
- **Note:** only the *overridden* path was seen (shows "N override logged"); the *unresolved* red banner
  was never triggered. Needs an unresolved conflict to exist.
- **Status:** [ ] verified

### P1-2 — Responsive toolbar (embedded)
- **Test:** narrow the window to ~1024px / half-width → week-nav + actions stay on the **first row**,
  KPI chips **scroll horizontally**, calendar visible without scrolling past stacked chrome; standalone
  hero unchanged.
- **Note:** couldn't verify previously — the resize tool didn't change the viewport (`innerWidth` stayed
  maximized). Re-try with a genuinely resizable window, or check the CSS breakpoint classes on the
  embedded toolbar / KPI-chip row (`overflow-x-auto`).
- **Status:** [ ] verified

### P2-10 — Contrast / reduced motion
- **Test:** safety pills/banner stay high-contrast regardless of brand hue; keyboard focus is clearly
  visible on hero/grid/tabs; with **reduced motion ON (OS setting)** the hero pulse-dot + spinners don't
  animate.
- **Note:** needs the OS `prefers-reduced-motion` setting toggled (couldn't do via browser tooling).
- **Status:** [ ] verified

### P2-20 — Live regions / aria-busy
- **Test:** with a screen reader, critical errors (save failed, week cleared, allergen check failed) are
  announced; in-flight controls expose `aria-busy`.
- **Note:** **code-complete (2026-06-03).** Allergen-check-failed panel is `role=status`/`aria-live=assertive`;
  week-cleared announces; `aria-busy` is on in-flight Save/Submit buttons. The **save-failed** gap was the
  open item: `PlanEntryDialog` (meal save), Generate-list and Save-budget now also `announce(...)` on
  failure (see audit/fix section above). **Live SR check still pending** (force a failed save → confirm the
  assertive region speaks "Couldn't save the meal — try again").
- **Status:** [x] code-complete · [ ] live SR re-check pending

### P1-3 — Control sizing
- **Test:** shopping tick-offs, template steppers/clear-X are comfortably clickable (**≥24px hit area**).
- **Note:** needs a shopping list + a template present (seeding #2/#3). Measure via DOM bounding boxes.
- **Status:** [ ] verified

---

## B. Blocked on seeded data (test once seeding is done)

### P2-12 — Templates
- **Test:** template card recipe names legible (**≥11px**) with "+N more"; cards show an **allergen
  footprint** chip row; **Apply** confirm flags "**Heads up: N meals conflict with residents' allergens**".
- **Needs:** at least one saved template (seeding #2). Currently "No templates yet".
- **Status:** [ ] verified

### P2-13 — Add-to-shopping
- **Test:** "Add N to shopping list" shows "**Adding x of y…**"; partial failures list failed items with
  **Retry remaining**; success summarises counts.
- **Needs:** a recipe with ingredients (e.g. the "dfhsdh" recipe has 1) + a shopping context.
- **Status:** [ ] verified

### P2-15 — Shopping resilience
- **Test:** tick items on a draft list → **reload** → ticks survive (localStorage); leaving with unsaved
  ticks warns; history rows show **provenance** (received/ordered date + provider + ref, not a raw `#id`);
  dates are **en-NZ**.
- **Needs:** a generated shopping list with history (seeding #3).
- **Status:** [ ] verified

### P1-12 (row buttons) — Inventory row semantics
- **Test:** inventory **row icon buttons announce action + product** (e.g. "Adjust stock for Apples").
- **Note:** table caption + 6 `th[scope=col]` + category pill `aria-pressed` **already verified**; only the
  per-row icon buttons remain (Hilltop inventory was empty).
- **Needs:** inventory items (seeding #1).
- **Status:** [~] partial — finish row buttons

---

## C. Minor / low-risk (cosmetic or narrow-scope)

### P2-1 — Print docs branding
- **Test:** Kitchen sheet + Shopping list print docs use the **brand** colour (no green `#1f7a4d`); the
  Cook Sheet prints per-resident **texture + fluids + allergens** + override reason/author. (Kitchen sheet
  is `window.print()` — inspect the print preview / print stylesheet.)
- **Status:** [ ] verified

### P2-2 — Full-width
- **Test:** `/catering` fills the screen with the standard gutter (grid/table edge-to-edge).
- **Note:** **looks** full-width in every screenshot; not formally measured. Low risk.
- **Status:** [~] looks fine — confirm formally

### P2-7 (detail) — Planned-vs-served edit dialog
- **Test:** open a **served** meal's **Edit** dialog → explicit "**Mark not served**", serve separated
  from Delete, notes field relabelled "**Meal record (free-text note)**"; hover card shows serve time.
- **Note:** the ⋯ menu already exposes "Mark not served" + relabelled note seen on Add; the full **Edit
  dialog** state for a served meal wasn't opened.
- **Status:** [~] partial — open the edit dialog on a served meal

### P2-17 — Cost unit (dollars)
- **Test:** product cost is entered in **dollars** in both the in-planner Products manager and the (now
  redirecting) legacy products form. (Manage products dialog renders; product costs were unset/"—".)
- **Status:** [ ] verified

### P0-9 — No-sites empty state
- **Test:** on a tenant with **no sites**, `/catering` shows a proper card (icon + heading + body +
  **Create a site** CTA), not a bare dead-end line.
- **Needs:** a tenant with zero sites (not available among the demo houses).
- **Status:** [ ] verified

### P1-8 — Loading cues
- **Test:** clicking **Plan a meal** / **Generate** shows an "Opening…" spinner even throttled; week-nav
  shows an "Updating…/refreshing" cue; initial load shows a skeleton.
- **Note:** initial-load **skeleton already observed**; the spinner/throttled cues weren't isolated.
- **Status:** [~] partial

---

## Suggested order of work

1. **Seed the demo data** (the 4 items above) on Hilltop 9007.
2. Knock out **B** (P2-12, P2-13, P2-15, P1-12 rows) — they unblock immediately after seeding.
3. Do **A** safety/a11y (P0-6 needs the seeded conflict; P1-2 & P2-10 need a resizable window / OS setting;
   finish P2-20 + P1-3).
4. Mop up **C** (cosmetic) and tick the boxes.
5. When all boxes are `[x]`, update the verdict in this file to **"Production sign-off complete."**

**Current verdict (2026-06-03):** core safety + write layer + page-containment are **production-ready**;
the above are follow-ups, none known-broken.
