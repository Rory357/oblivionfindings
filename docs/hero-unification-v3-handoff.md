# Hero Banner & Page Layout Unification — v3 Handoff

**Status:** Phase 4 complete · platform-wide sweep done · only cleanup + intentional skips remain
**Reference plans:**
- v1: `C:\Users\steph\.claude\plans\you-are-acting-as-sharded-whistle.md`
- v2: `docs/hero-unification-v2-plan.md` (executed across commits 8abbdbd0..f550974f)

---

## What's done (so a fresh session doesn't repeat it)

**~559 pages migrated** to the canonical `PageHero` + `PageLayout` system across 9 commits to `main`:

| Commit | Phase | Pages |
|---|---|---|
| `8abbdbd0` | Phase 2 (compact→hero) + Phase 3 (raw h1) | 39 |
| `ff8e88f8` | Phase 4 (broader sweep) | 108 |
| `6a8c80a1` | Phase 4b (Catering/Finance/11 bespoke heroes) | 56 |
| `811da184` | Phase 4c (Respite/Sites/HR/Gov) | 49 |
| `7ac317d8` | Phase 4d (Finance dashboards + reports) | 37 |
| `a2e120bb` | Phase 4e (Privacy/Portal/HR/Gov sub-pages) | 131 |
| `f3423c09` | Phase 4f (Health-Safety + CRUD forms) | 79 |
| `4fe47e6a` | Phase 4g (Governance + Respite CRUD) | 34 |
| `f550974f` | Phase 4h (HR CRUD re-do after agent flake) | 26 |

### Conventions that shipped

- **Color: primary only.** Every hero uses `--primary` (the default). NEVER add `category="..."` props. v2 explicitly stripped them; the design intent is a single coherent purple gradient across the platform.
- **Variant rules:**
  - `hero` (default) — full gradient banner with `icon` + `title` + `description` + `stats` + `actions`. Use for index, list, dashboard pages.
  - `compact` — back-link + title + description + actions (no gradient). Use for Create/Edit/Show sub-pages with `backHref` to the parent index.
  - `inline` — h1 + actions only (rarely used; wizard steps).
- **Outline buttons inside the gradient hero** must use this class so they remain readable: `border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground`
- **Detail/show pages** can use `avatar` instead of `icon` (see `operations/clients/show.tsx`, `hr/employees/show.tsx`, `hr/directory/show.tsx`).
- **Backwards-compat shims** still exist for `fleet-hero`, `fleet-stat-card`, `ops-stat-card`, `catering/_hero`, `page-header`, `page-shell`. They forward to canonical components.

### Canonical reference files (do not modify without intent)
- [resources/js/components/page/page-hero.tsx](resources/js/components/page/page-hero.tsx)
- [resources/js/components/page/page-layout.tsx](resources/js/components/page/page-layout.tsx)
- [resources/js/components/page/page-tabs.tsx](resources/js/components/page/page-tabs.tsx)
- [resources/js/components/page/page-hero-stats.tsx](resources/js/components/page/page-hero-stats.tsx)
- [resources/js/components/page/page-hero-badges.tsx](resources/js/components/page/page-hero-badges.tsx)
- [resources/js/components/page/page-hero-meta.tsx](resources/js/components/page/page-hero-meta.tsx)
- [resources/js/components/page/index.ts](resources/js/components/page/index.ts) — barrel export
- [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) — **the visual reference** (hero + tabs)
- [resources/js/pages/operations/care-plans/Index.tsx](resources/js/pages/operations/care-plans/Index.tsx) — clean migrated example
- [resources/js/pages/internal/_design/page-hero.tsx](resources/js/pages/internal/_design/page-hero.tsx) — admin showcase at `/internal/_design/page-hero`
- [eslint.config.js](eslint.config.js) — hero-color guardrail (flags `text-white`, `bg-white/*`, hex literals in `components/page/`)

---

## Outstanding work

### 1. Quick shim cleanup (low risk, ~5 min)

These shims can be deleted right now with no callers:

- **`resources/js/components/page-header.tsx`** — 0 imports across the codebase. Safe to `git rm`.
- **`resources/js/components/fleet-hero.tsx`** — only referenced by a `vi.mock('@/components/fleet-hero', ...)` block in `resources/js/test/resident-tracking.test.tsx`. Delete the shim AND the mock block (the test no longer uses `FleetHero` at all).

**How to verify before deleting:**
```bash
grep -rE "from ['\"]@?/?components/page-header['\"]" resources/js --include='*.tsx' --include='*.ts'
grep -rE "from ['\"]@?/?components/fleet-hero['\"]" resources/js --include='*.tsx' --include='*.ts'
```

Both should return nothing (other than `resident-tracking.test.tsx` for `fleet-hero`).

### 2. Medium-effort: migrate `catering/_hero.tsx` callers, then delete the shim

`resources/js/pages/catering/_hero.tsx` is a bespoke catering-specific hero used by:
- `resources/js/pages/catering/recipes/edit.tsx` (line 10, 101)
- `resources/js/pages/catering/dashboard.tsx` (line 5, 84)

Each `<CateringHero />` call should become a `<PageHero icon={...} title="..." />` call. Look at how `feedback/index.tsx` migrated from its bespoke gradient to PageHero — same pattern.

Then `git rm resources/js/pages/catering/_hero.tsx`.

### 3. Optional: stat card consolidation (high effort, low UX value)

The platform uses three stat card primitives:
- **`StatTile`** (canonical, in `resources/js/components/page/stat-tile.tsx`) — 13-tone unified KPI card
- **`fleet-stat-card.tsx`** shim → forwards to `StatTile` — **29 imports**
- **`ops-stat-card.tsx`** shim → forwards to `StatTile` — **30 imports**

The shims work today. Removing them requires changing 59 call sites to import `StatTile` directly and adjusting the prop API where it differs. **No UX benefit**, just code-debt cleanup. Recommend deferring unless someone is consolidating the design system.

### 4. Optional: `page-shell.tsx` removal (very high effort, no UX value)

`page-shell.tsx` is used by **255 files** as a content container. `PageLayout` from `@/components/page` provides the equivalent function via its `width` and `padding` props. Migrating would mean changing 255 imports and reviewing the layout of each. **Recommend not doing this** unless there's a separate layout refactor planned.

### 5. Intentionally not migrated (15 pages — leave as-is)

These pages use different layout systems and shouldn't be migrated to `PageHero` without first defining how:

| File | Why skipped |
|---|---|
| `resources/js/pages/welcome.tsx` | Marketing — uses `MarketingLayout` |
| `resources/js/pages/about.tsx` | Marketing |
| `resources/js/pages/contact.tsx` | Marketing |
| `resources/js/pages/features.tsx` | Marketing |
| `resources/js/pages/pricing.tsx` | Marketing |
| `resources/js/pages/terms.tsx` | Marketing |
| `resources/js/pages/home.tsx` | Marketing |
| `resources/js/pages/smart-monitoring.tsx` | Marketing |
| `resources/js/pages/fleet-assets/mobile/dashboard.tsx` | Bare mobile UI, no AppLayout |
| `resources/js/pages/my-roster/index.tsx` | Uses `StaffPageShell` (frontline shell) |
| `resources/js/pages/my-calendar.tsx` | h1 is a FullCalendar period title, not a page title |
| `resources/js/pages/portal/login.tsx` | Public portal — custom `min-h-screen` layout |
| `resources/js/pages/portal/messages/show.tsx` | Public portal layout |
| `resources/js/pages/portal/consent-requests/Show.tsx` | Public portal layout |
| `resources/js/pages/incidents/show.tsx` | 1500+ lines with custom severity-bar hero. Would need a new "rich children" PageHero slot — defer until someone designs that |

If a marketing-page hero unification is wanted, that's a separate initiative (different design system, different layout).

---

## Pre-existing backend bug (not caused by this work)

`/hr/people/{id}` returns 500:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'employee_profile_id'
in 'where clause' (Connection: mysql, Host: 127.0.0.1, Port: 3306,
Database: oblivion_findings, SQL: select * from `hr_performance_improvement_plans`
where `employee_profile_id` = 39 order by `start_date` desc limit 5)

at /var/www/oblivionfindings/app/Http/Controllers/Hr/EmployeeProfileController.php:283
```

Either add the `employee_profile_id` column to `hr_performance_improvement_plans` (look at the model's `$fillable` to see the intended schema), or change the controller's query to use whatever the actual column name is (`user_id`? `subject_id`?). Backend fix only — frontend hero migration is correct.

---

## Suggested next-session prompt

```
Read docs/hero-unification-v3-handoff.md. The hero migration is complete; only
shim cleanup remains. Do the "Quick shim cleanup" step:

1. Delete resources/js/components/page-header.tsx (0 imports — verify first).
2. Delete resources/js/components/fleet-hero.tsx AND remove the vi.mock block
   in resources/js/test/resident-tracking.test.tsx that mocks it (the test
   no longer uses FleetHero directly).
3. Run npm run types and npm run build.
4. Commit + push to main.

If that goes smoothly, optionally tackle the catering/_hero.tsx migration:
- Replace <CateringHero /> in catering/recipes/edit.tsx and catering/dashboard.tsx
  with <PageHero icon={ChefHat} ... />.
- Then delete resources/js/pages/catering/_hero.tsx.

Stop after either step. Don't touch fleet-stat-card.tsx, ops-stat-card.tsx,
or page-shell.tsx — those are out of scope.
```

---

## Test credentials (dev)

- Dev URL: `https://oblivionfindings.com`
- SSH: `oblivion@oblivionfindings.com` / `Sheila1983@#$!`
- Login: `admin@demo.test` / `Sheila1983@#$!`
- Workflow: commit + push to `main` → wait ~5 min for deploy → hard-refresh browser to bust asset cache.

## Verify cumulative status

```bash
# Should be 15 (the intentionally-skipped list above):
grep -rLE 'PageHero|FleetHero|PageHeader' resources/js/pages --include='*.tsx' \
  | xargs grep -lE '^\s*<h1' 2>/dev/null | wc -l

# Should pass cleanly:
npm run types
npm run build
```
