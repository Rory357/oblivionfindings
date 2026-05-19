# Hero Banner & Page Layout Unification — v2 Plan

**Status:** In progress · Phase 1 shipped to main, Phase 2-4 outstanding
**Reference plan:** `C:\Users\steph\.claude\plans\you-are-acting-as-sharded-whistle.md` (original v1)
**Visual reference:** Site Detail page (`/sites/{id}`) — purple `--primary` gradient hero with icon, badges, meta, stats, actions, 16 horizontally-scrolling tabs

---

## Why a v2 plan

The v1 sweep shipped the canonical component system and migrated 303 pages, but two real problems surfaced in production review:

1. **Color inconsistency across modules.** v1 added `category="hr"`, `category="compliance"`, `category="fleet"`, etc. to 23 module dashboards. The intent was theme-mapped module accents — the reality is HR pages look red, Compliance pages look magenta, Fleet pages look magenta, Sites pages look green. This breaks the "one coherent design language" goal. **Fixed in commit `b34c1c43`** — all category props stripped, every hero now uses `--primary`.

2. **The `compact` variant got over-applied.** The v1 PageHeader codemod converted all 184 `PageHeader` callers to `<PageHero variant="compact">`. That variant has no gradient — it's a plain text title row. Many of those callers were actually **index/list/dashboard** pages that deserve the full gradient hero. Result: pages like `/operations/care-plans`, `/emar/audit`, `/hr/time` look like minimal text pages while `/sites` and `/fleet-assets` get the full hero. Inconsistent.

3. **~50+ pages were never touched at all.** They use raw `<h1>` markup with no shared component. Most are in `/hr/*`, `/governance/*`, `/roadmap/*`.

---

## What's been done so far (v1, on `main`)

### Shipped components — `resources/js/components/page/`
- `PageHero` — three variants: `hero` (gradient + icon/avatar + badges + meta + stats + actions), `compact` (back link + title + description + actions, no gradient), `inline` (h1 + actions only)
- `PageHeroStats`, `PageHeroBadges`, `PageHeroMeta`, `PageHeroActions` — internal slots
- `PageTabs` — horizontal-scroll tabs with gradient-fade indicators + overflow-into-More dropdown
- `PageContent`, `PageLayout` — orchestrator + content container
- `StatTile` — unified 13-tone KPI card (status + category tones)

### Shipped routes / showcase
- `/internal/_design/page-hero` — admin/local-only showcase covering all variants, tones, categories, and tab overflow
- ESLint guardrail in `eslint.config.js` scoped to `resources/js/components/page/**` flagging `text-white` / `bg-white/*` / hex literals

### Shipped migrations (commit `3ed848c2` + follow-ups)
- 113 `FleetHero` import sites → `PageHero` (via codemod, `fleet-hero.tsx` becomes a shim)
- 184 `PageHeader` import sites → `<PageHero variant="compact">` ← **OVER-APPLIED — see "Phase 2" below**
- 184 `FleetStatCard` / `OpsStatCard` callers → `StatTile` (via shims, no call-site changes needed)
- `CateringHero` → `PageHero` shim (6 callers preserved)
- 12 inline hero blocks token-purified (`text-white` → `text-primary-foreground` etc.) — operations/clients/show, hr/employees/show, hr/directory/show, hr/candidates/show, hr/training/×3, hr/goals/show, hr/feedback/×3, timeline/index
- Sites module fully migrated: `sites/show.tsx` (surgical hero+tabs), `sites/index.tsx`, `sites/create.tsx`, `sites/edit.tsx`, `sites/rooms/index.tsx`

### Reverted in v2 (this document's commit)
- 23 dashboard pages had `category="..."` props added — **all stripped** to restore unified `--primary` gradient

### Two bugs caught + fixed during live verification
- React error #31 (lucide forwardRef icons): commit `f49164bb` — `renderIcon` no longer gates on `typeof === 'function'`
- "TabsContent must be used within Tabs" in showcase: commit `069943f9` — `<TabsContent>` siblings moved inside `<PageTabs>...</PageTabs>`

---

## Phase 2 — Upgrade `compact` → `hero` on misclassified pages (OUTSTANDING)

The v1 PageHeader codemod assumed every `PageHeader` caller was a secondary/form page. It wasn't. Many were index pages that should have the full gradient hero with stats and icon.

### Triage rule (apply per file)

| Page type | Correct variant |
|---|---|
| Dashboard / module landing | `hero` + icon + stats + actions |
| Index / list page | `hero` + icon + stats (totals, active counts, etc.) + actions |
| Detail / show page | `hero` + icon **or** avatar + meta + badges + stats + actions |
| Create / Edit form | `compact` + backHref + actions |
| Settings sub-page | `compact` |
| Wizard step | `inline` |
| Modal / dialog companion | (no PageHero — that's chrome) |

### Confirmed pages needing upgrade from `compact` → `hero`

These 11 came directly from user-reported URLs. Many more exist across the 184 codemodded files.

| URL | File | Suggested icon | Notes |
|---|---|---|---|
| `/operations/care-plans` | [resources/js/pages/operations/care-plans/Index.tsx](resources/js/pages/operations/care-plans/Index.tsx) | `ClipboardList` | Has 6 OpsStatCards below; lift 3-4 KPIs into hero stats |
| `/emar/audit` | [resources/js/pages/emar/AuditLog.tsx](resources/js/pages/emar/AuditLog.tsx) | `History` | List of audit entries |
| `/emar/reports` | [resources/js/pages/emar/Reports.tsx](resources/js/pages/emar/Reports.tsx) | `FileBarChart` | |
| `/emar/competency` | [resources/js/pages/emar/Competency.tsx](resources/js/pages/emar/Competency.tsx) | `GraduationCap` | |
| `/emar/destructions` | [resources/js/pages/emar/Destructions.tsx](resources/js/pages/emar/Destructions.tsx) | `Trash2` | |
| `/emar/handovers` | [resources/js/pages/emar/Handovers.tsx](resources/js/pages/emar/Handovers.tsx) | `ArrowLeftRight` | |
| `/emar/errors` | [resources/js/pages/emar/MedicationErrors.tsx](resources/js/pages/emar/MedicationErrors.tsx) | `AlertTriangle` | |
| `/hr/import-export` | [resources/js/pages/hr/import-export/index.tsx](resources/js/pages/hr/import-export/index.tsx) | `UploadCloud` | |
| `/hr/time` | [resources/js/pages/hr/time/index.tsx](resources/js/pages/hr/time/index.tsx) | `Clock` | |
| `/hr/succession` | [resources/js/pages/hr/succession/index.tsx](resources/js/pages/hr/succession/index.tsx) | `Users` | |
| `/hr/exit-interviews` | [resources/js/pages/hr/exit-interviews/index.tsx](resources/js/pages/hr/exit-interviews/index.tsx) | `LogOut` | |

### Full audit query (re-run before starting)

```bash
# List every page that currently uses `variant="compact"` on a top-level (not modal/form) route.
grep -rlE '<PageHero[^>]*variant="compact"' resources/js/pages --include='*.tsx' \
  | xargs -I{} sh -c 'echo "{}: $(grep -cE "(Create|Edit).tsx$" <<< "{}" || true)"' \
  | grep ':0$' | head -50

# 184 total to triage. Probably 60-100 should become `hero`, the rest legitimately stay compact.
```

---

## Phase 3 — Migrate raw `<h1>` pages to `PageHero` (OUTSTANDING — biggest remaining bucket)

These pages were never migrated and still use bespoke headers (raw `<h1>`, inline divs, or nothing).

### Pages confirmed missing from user-reported URLs (28 files)

| URL | File | Suggested variant + icon |
|---|---|---|
| `/hr/my/documents` | [resources/js/pages/hr/my/documents.tsx](resources/js/pages/hr/my/documents.tsx) | `hero` + `FileText` |
| `/hr/my/training` | [resources/js/pages/hr/my/training.tsx](resources/js/pages/hr/my/training.tsx) | `hero` + `GraduationCap` |
| `/hr/my/payslips` | [resources/js/pages/hr/my/payslips.tsx](resources/js/pages/hr/my/payslips.tsx) | `hero` + `Receipt` |
| `/hr/directory` | [resources/js/pages/hr/directory/index.tsx](resources/js/pages/hr/directory/index.tsx) | `hero` + `Users` |
| `/hr/people` (= /hr/employees) | [resources/js/pages/hr/employees/index.tsx](resources/js/pages/hr/employees/index.tsx) | `hero` + `Users` + stats (count, active, on-leave) |
| `/hr/job-postings` | [resources/js/pages/hr/job-postings/index.tsx](resources/js/pages/hr/job-postings/index.tsx) | `hero` + `Briefcase` |
| `/hr/leave/reports` | [resources/js/pages/hr/leave/reports.tsx](resources/js/pages/hr/leave/reports.tsx) | `hero` + `BarChart3` |
| `/hr/calendar/time-off` | [resources/js/pages/hr/calendar/time-off.tsx](resources/js/pages/hr/calendar/time-off.tsx) | `hero` + `CalendarOff` |
| `/hr/performance` | [resources/js/pages/hr/performance/index.tsx](resources/js/pages/hr/performance/index.tsx) | `hero` + `Target` |
| `/hr/feed` | [resources/js/pages/hr/feed/index.tsx](resources/js/pages/hr/feed/index.tsx) | `hero` + `Rss` |
| `/hr/compliance` | [resources/js/pages/hr/compliance/index.tsx](resources/js/pages/hr/compliance/index.tsx) | `hero` + `ShieldCheck` |
| `/hr/compliance/calendar` | [resources/js/pages/hr/compliance/calendar.tsx](resources/js/pages/hr/compliance/calendar.tsx) | `hero` + `Calendar` |
| `/hr/departments` | [resources/js/pages/hr/departments/index.tsx](resources/js/pages/hr/departments/index.tsx) | `hero` + `Building` |
| `/hr/onboarding` | [resources/js/pages/hr/onboarding/index.tsx](resources/js/pages/hr/onboarding/index.tsx) | `hero` + `UserPlus` |
| `/hr/onboarding/emails` | [resources/js/pages/hr/onboarding/emails.tsx](resources/js/pages/hr/onboarding/emails.tsx) | `compact` + back to /hr/onboarding |
| `/hr/documents` | [resources/js/pages/hr/documents/index.tsx](resources/js/pages/hr/documents/index.tsx) | `hero` + `Folder` |
| `/hr/reports` | [resources/js/pages/hr/reports/index.tsx](resources/js/pages/hr/reports/index.tsx) | `hero` + `BarChart3` |
| `/hr/policies` | [resources/js/pages/hr/policies/index.tsx](resources/js/pages/hr/policies/index.tsx) | `hero` + `BookOpen` |
| `/hr/payroll` | [resources/js/pages/hr/payroll/index.tsx](resources/js/pages/hr/payroll/index.tsx) | `hero` + `Banknote` |
| `/hr/payroll/payslips` | [resources/js/pages/hr/payroll/payslips.tsx](resources/js/pages/hr/payroll/payslips.tsx) | `hero` + `Receipt` |
| `/hr/cases` | [resources/js/pages/hr/cases/index.tsx](resources/js/pages/hr/cases/index.tsx) | `hero` + `Folder` |
| `/hr/signatures/pending` | [resources/js/pages/hr/signatures/pending.tsx](resources/js/pages/hr/signatures/pending.tsx) | `hero` + `PenSquare` |
| `/governance/dashboard` | [resources/js/pages/Governance/Dashboard.tsx](resources/js/pages/Governance/Dashboard.tsx) | `hero` + `Landmark` + stats |
| `/governance/meetings` | [resources/js/pages/Governance/Meetings/Index.tsx](resources/js/pages/Governance/Meetings/Index.tsx) | `hero` + `Users` |
| `/governance/risks` | [resources/js/pages/Governance/Risks/Index.tsx](resources/js/pages/Governance/Risks/Index.tsx) | `hero` + `ShieldAlert` |
| `/governance/resolutions` | [resources/js/pages/Governance/Resolutions/Index.tsx](resources/js/pages/Governance/Resolutions/Index.tsx) | `hero` + `Gavel` |
| `/governance/compliance` | [resources/js/pages/Governance/Compliance/Index.tsx](resources/js/pages/Governance/Compliance/Index.tsx) | `hero` + `ShieldCheck` |
| `/governance/strategy` | [resources/js/pages/Governance/Strategy/Index.tsx](resources/js/pages/Governance/Strategy/Index.tsx) | `hero` + `Compass` |
| `/governance/performance` | [resources/js/pages/Governance/Performance/Index.tsx](resources/js/pages/Governance/Performance/Index.tsx) | `hero` + `Target` |
| `/governance/budgets` | [resources/js/pages/Governance/Budgets/Index.tsx](resources/js/pages/Governance/Budgets/Index.tsx) | `hero` + `DollarSign` |
| `/governance/actions` | [resources/js/pages/Governance/Actions/Index.tsx](resources/js/pages/Governance/Actions/Index.tsx) | `hero` + `CheckSquare` |
| `/roadmap/dashboard` | [resources/js/pages/Roadmap/Dashboard.tsx](resources/js/pages/Roadmap/Dashboard.tsx) | `hero` + `Map` |

### Migration template

Each file needs roughly this pattern (rough — adapt to existing imports + props):

```tsx
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Users, Plus } from 'lucide-react';

export default function HrPeopleIndex() {
    const { employees, can } = usePage<Props>().props;
    const active = employees.filter((e) => e.is_active).length;

    return (
        <AppLayout breadcrumbs={[{ title: 'HR', href: '/hr' }, { title: 'People', href: '/hr/people' }]}>
            <Head title="People" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Users}
                        title="People"
                        description="Directory of all employees across the organisation."
                        stats={[
                            { label: 'Total', value: employees.length },
                            { label: 'Active', value: active },
                            { label: 'On leave', value: employees.filter((e) => e.on_leave).length },
                        ]}
                        actions={
                            can?.people?.create ? (
                                <Button size="sm" asChild>
                                    <Link href="/hr/people/create">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add person
                                    </Link>
                                </Button>
                            ) : null
                        }
                    />
                }
            >
                {/* existing table/grid stays */}
            </PageLayout>
        </AppLayout>
    );
}
```

### Pages that route but file is missing (4)

These URLs 404 or use a different file naming convention — investigate first:

- `/hr/performance/pips` — file `hr/performance/pips.tsx` does not exist; route probably maps elsewhere
- `/hr/performance/competencies` — same
- `/hr/compliance/vetting` — same (try `hr/compliance/vetting/*.tsx` glob)
- `/hr/compliance/drivers` — same

Run: `find resources/js/pages -path '*performance*' -name '*.tsx'` and `find resources/js/pages -path '*vetting*' -name '*.tsx'` to locate.

---

## Phase 4 — Broader sweep beyond user's URL list (BACKLOG)

The 49 URLs were a sample. The full numbers:

| Metric | Count | % of 865 |
|---|---|---|
| Pages using `PageHero` (any variant) | 303 | 35% |
| Pages on legacy `PageShell` wrapper (no hero) | 254 | 29% |
| Pages with raw `<h1>` (no shared component) | ~532 | 61% |

The Phase 2 + Phase 3 work above closes the highest-visibility gaps. To finish the platform sweep:

- Sweep remaining `<PageHero variant="compact">` callers (~150 after the user-flagged 11) — promote index/list/dashboard ones to `hero`
- Sweep raw `<h1>` pages in non-HR/non-Governance modules — `respite`, `control-room`, `finance`, `settings/*`, `security-devices`, `system`, `integrations`, `portal`, `calendar`, `checklists`, `compliance/*`, `attendance`, `audit`, `careers`
- Structurally migrate the 12 token-purified bespoke gradient heroes to `<PageHero>` calls (operations/clients/show, hr/employees/show, hr/directory/show, hr/candidates/show, hr/training/×3, hr/goals/show, hr/feedback/×3, timeline/index) — they're token-pure but still bespoke JSX
- PR11 deprecation cleanup: delete `fleet-hero.tsx`, `fleet-stat-card.tsx`, `ops-stat-card.tsx`, `catering/_hero.tsx`, `page-header.tsx`, `page-shell.tsx` shims once nothing imports them

---

## Decisions the operator should confirm before resuming

1. **Color strategy — confirm "primary only"**
   - The v2 fix strips all `category="..."` props. Every hero now uses `--primary`. Want this to stay? Or do you want category-themed gradients selectively (e.g. ONLY on incident-related pages, in destructive red)?

2. **Compact-vs-hero promotion scope**
   - Phase 2 promotes 11 user-flagged pages from `compact` → `hero`. Want the same applied automatically to ALL ~150 remaining `compact` index pages, or stick to per-file review?

3. **Pages with no shared component (Phase 3)**
   - 32 user-flagged. ~500 untouched platform-wide. Want a sweep across all of them, or only the user-flagged subset?

4. **Backwards-compat shims (PR11)**
   - Currently `fleet-hero`, `fleet-stat-card`, `ops-stat-card`, `catering/_hero`, `page-header`, `page-shell` exist as shims. Want them removed in this initiative or kept for future churn?

---

## Suggested next-session prompt

When you open a fresh context, paste this:

> Read `docs/hero-unification-v2-plan.md`. Execute **Phase 2** (upgrade the 11 listed `compact` → `hero` pages) and **Phase 3** (migrate the 28 listed raw-`<h1>` pages to `PageHero`). Use the `PageHero` + `PageLayout` components from `@/components/page` (already on `main`). Do NOT add `category` props — every hero uses `--primary`. After each batch of ~10 files, run `npm run types` and `npm run build` to verify. Commit + push to `main` between batches so I can verify on `https://oblivionfindings.com`.

---

## Critical reference files

- [resources/js/components/page/page-hero.tsx](resources/js/components/page/page-hero.tsx) — the canonical hero
- [resources/js/components/page/page-tabs.tsx](resources/js/components/page/page-tabs.tsx) — tabs with scroll-fade + More overflow
- [resources/js/components/page/page-layout.tsx](resources/js/components/page/page-layout.tsx) — orchestrator
- [resources/js/components/page/stat-tile.tsx](resources/js/components/page/stat-tile.tsx) — unified KPI card
- [resources/js/components/page/index.ts](resources/js/components/page/index.ts) — barrel export
- [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) — **the visual reference** (lines 1700–~1810 = hero + tabs)
- [resources/js/pages/sites/index.tsx](resources/js/pages/sites/index.tsx) — list page with hero
- [resources/js/pages/internal/_design/page-hero.tsx](resources/js/pages/internal/_design/page-hero.tsx) — showcase, `/internal/_design/page-hero` admin-gated
- [eslint.config.js](eslint.config.js) — has the hero-color guardrail
- [routes/web.php](routes/web.php) — showcase route at the top of the auth+verified group

## Commits in this branch (most recent first)

- `b34c1c43` (v2 fix) — strip category props for unified --primary gradient
- `069943f9` — fix: nest TabsContent inside PageTabs in showcase
- `f49164bb` — fix: PageHero icon rendering for lucide forwardRef
- `3ed848c2` — Unify hero banner + page layout system platform-wide
