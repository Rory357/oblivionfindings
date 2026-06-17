# Health & Safety — hero consistency gap analysis & loop tracker

> Single source of truth for the `/loop` that unifies the **two H&S heroes**
> (`/health-safety` dashboard = gold standard, `/health-safety/analytics` =
> conforms). Operationalises `HEALTH_SAFETY_CONSISTENCY_AUDIT.md` +
> `HEALTH_SAFETY_CONSISTENCY_FIX_PROMPT.md`. Tick `[x]` as each item lands; the
> loop exits when every box is ticked and §6 of the prompt passes.
>
> **Guardrails (do NOT over-unify):** keep different period presets (week vs YTD),
> different hero KPI *content* (analytics carries deltas), different actions/verbs
> (dashboard report-led, analytics export-led), different tab sets. **No create/report
> wizards on analytics** — it stays a read-only explorer. NZ frameworks only
> (LTIFR/TRIFR, WorkSafe notifiable, Ngā Paerewa NZS 8134:2021, ACC). Web-only.

## Reference files

- Kit (the only implementation): `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
- Dashboard hero (gold standard): `resources/js/pages/health-safety/components/command-centre-hero.tsx`
- Analytics page (conforms): `resources/js/pages/health-safety/analytics.tsx`
- Role banner: `resources/js/pages/health-safety/components/dashboard-tabs.tsx` → `RoleLensBanner`
- Controllers: `App\Http\Controllers\HealthSafety\HealthSafetyDashboardController@index` / `@analytics`

## Verify each pass

`npm run types` · `npm run lint` · `npm run build` (+ `npm run test` for touched specs)
clean for touched files. **Dashboard must stay pixel-identical** through every kit
extraction (it is the reference, not a target). Side-by-side `/health-safety` vs
`/health-safety/analytics`: eyebrow pill, badges, stat clusters, footer band, summary
strip, medallion, gradient/shadow and role banner must read as identical chrome.

---

## A. Extract the shared hero kit (P1) — the consistency centrepiece

- [x] **A1** Create `hs-hero-kit.tsx` exporting the moved primitives: `HeroShell`,
  `HeroStatusPill`, `HeroMedallion`, `HeroCluster` + `HeroClusterTile` (optional
  `delta`/`deltaTone`), `HeroComplianceBadges`, `HeroSegmented` (pill + segmented
  variants), `HeroSummaryStrip` + `HeroSummaryMetric` (optional `onToggle`/`collapsed`),
  plus shared `Tone`/`DOT_CLASS`/`fmt`. _Done — `hs-hero-kit.tsx` created with all
  primitives + `hs-hero-kit.test.tsx` (5 specs) locking the canonical badge labels,
  the one fire-drill threshold, and the delta slot._
- [x] **A2** Refactor `command-centre-hero.tsx` to import & compose the kit — **pure
  refactor, dashboard pixel-identical**. _Done — hero now composes `HeroShell` /
  `HeroStatusPill` / `HeroMedallion` / `HeroCluster`+`HeroClusterTile` /
  `HeroComplianceBadges` / `HeroSummaryStrip`+`HeroSummaryMetric`; Tailwind classes
  preserved verbatim (non-regression by construction). The footer period/lens pills
  stay hand-rolled this pass and move to `HeroSegmented` under **E5** (kept out of A2
  to protect pixel-identity). `types`/`lint`/`test`/`build` all green. Live screenshot
  diff deferred until a build is deployed to compare against._

## B. Rebuild the analytics hero on the kit (P1)

- [x] **B1** Replace `analytics.tsx` `PageHero` with `HeroShell` composed from the kit. _Done._
- [x] **B2** Stats → Leading/Lagging `HeroCluster`s below the title (not top-right
  `PageHeroStats`); map `hero_stats` (+ `scorecard` split) with period-over-period
  `delta`/`deltaTone`. _Done — **4+4 tile choice**: mirror the dashboard's exact two
  clusters (Lagging: Incidents/LTIFR/TRIFR/Days LTI-free · Leading:
  Near-miss/Actions-on-time/Train-audit/Open-hazards), sourced from `scorecard` by key
  (it carries the deltas — `hero_stats` overlaps so it's unused now). Delta = `▲/▼ |Δ|`
  with tone from `dir` (▲ improving=green, ▼ watch=red); null/0 deltas render no line._
- [x] **B3** Eyebrow → `HeroStatusPill` (green dot), "Safety analytics · {rangeLabel}". _Done._
- [x] **B4** Medallion → `HeroMedallion icon={BarChart3}` (kit size, `hidden sm:flex`). _Done._
- [x] **B5** Description → one terse line; drop the multi-line prose + 3-item `meta[]`. _Done
  — single subtitle line: underlined `{siteScope}` · Ngā Paerewa NZS 8134:2021 · HSWA 2015 ·
  ACC (exact dates dropped — conveyed by the period control + eyebrow rangeLabel)._

## C. Canonical compliance-badge labels (P1)

- [x] **C1** `HeroComplianceBadges` uses the dashboard's verbatim wording, fed by
  counts/booleans (never strings). Tone map + one fire-drill threshold in the component.
  _Done in Pass 1 (A1); locked by `hs-hero-kit.test.tsx`._
- [x] **C2** Analytics feeds `HeroComplianceBadges` (drops its reworded `PageHeroBadge`
  list + `FlaskConical` + bespoke `critical` dot tone). _Done — analytics passes
  `worksafeAwaiting`, `sdsExpiring={0}` (analytics has no SDS-expiry series → "SDS current"),
  `drillsOverdue` (non-compliant drills → critical, per existing `period_summary` which has
  no due-soon granularity). Now both pages read the identical chip chrome + wording._

## D. Action row & gradient (P2)

- [x] **D1** Analytics folds its two loose outline buttons into the dashboard's translucent
  **popover** idiom; export-led verbs kept, white primary `Export` stays. _Done — action row
  now mirrors the dashboard: white primary `Export` + a translucent "Board reports ▾" popover
  (same pill chrome + `w-60 p-1` content) listing the five governance reports; analytics opens
  each dated report in a new tab (`window.open(reportUrl(...))`), the dashboard navigates._
- [x] **D2** Analytics drops `brandColour={props.site_brand_colour}` — primary gradient
  only. _Done — `HeroShell` takes no `brandColour`, so the prop read is gone; both heroes
  render the primary gradient. The `site_brand_colour` payload field is now vestigial for
  analytics (left in `AnalyticsProps`/controller as a harmless extra prop)._
- [x] **D3** Custom drop-shadow lives in `HeroShell` so both heroes match. _Done — analytics
  now uses `HeroShell` (shadow from A1)._

## E. Footer control band (P2)

- [x] **E1** Rebuild analytics `heroFooter` from kit parts. _Done — `HeroSegmented` (period,
  pill variant) + `EntityFilter` (onDark) + `HeroSegmented` (lens) + `HeroSummaryStrip`;
  analytics' presets (Last 30d / Quarter / 6 months / YTD / Custom) preserved._
- [x] **E2** Summary strip → `HeroSummaryStrip` + `HeroSummaryMetric` (dot-led), toggle as
  the kit `onToggle`/`collapsed` prop. _Done — toggle moved out of the controls row into the
  strip; dot-led metrics._
- [x] **E3** Add uppercase `Period` / `Lens` labels to the analytics footer. _Done (via
  `HeroSegmented label=`)._
- [x] **E4** Preserve analytics' Custom-range popover (via `HeroSegmented` pill `popover`
  slot). _Done — `CustomRangeFields` embedded in the Custom pill's popover._
- [x] **E5** Swap the dashboard's hand-rolled period/lens pills to `HeroSegmented` too. _Done
  — the dashboard period (pill variant, custom-range popover in the pill) and lens (segmented
  variant) now render via `HeroSegmented`; removed the page-local `pillBase`/`segBase` consts +
  `PERIOD_ITEMS` + the dead `cn` import. **Pixel-identical by construction**: only `role="group"`,
  `aria-pressed` and a keyboard-only focus ring were added (the focus ring is the §5 a11y
  requirement). `HeroSegmented` made faithful: pill label keeps `mr-1`; segmented variant is a
  fragment (label `ml-1` + box as siblings) so it slots beside the Site filter exactly. The kit
  is now the **only** segmented implementation on both pages._

## F. Title polish (P3)

- [x] **F1** Medallion size/visibility aligned (`HeroMedallion`, done in A1/B4).
- [x] **F2** One title casing convention — sentence case both. _Done — "Health & Safety
  analytics" (was "Analytics" title case), h1 scale matched to the dashboard
  (`text-2xl … md:text-[28px]`)._

## G. Page shell (P2/P3)

- [x] **G1** Analytics replaces its inline role-note div with the shared `RoleLensBanner`.
  _Done — `<RoleLensBanner lens={filters.lens} />` (uses the shared `LENS_TEXT` map; the
  backend `role_note` is now vestigial for analytics)._
- [x] **G2** Analytics page wrapper `gap-4` → `gap-6`. _Done._

## Backend (minimal — NO speculative migrations)

- [x] **BK1** Confirm no new controller params/migrations needed. _Confirmed — the analytics
  hero is built entirely from existing payload (`scorecard` for cluster tiles+deltas,
  `period_summary` + `worksafe_notifiable` for badges + summary strip). No controller/migration
  changes. Two values analytics simply doesn't track: **SDS expiry** (→ `sdsExpiring={0}`,
  "SDS current") and **due-soon vs overdue drills** (→ all non-compliant treated as overdue).
  Both reflect existing analytics behaviour, not stubs needing new schema._

---

## Change log (one line per pass)

- **Pass 1** — A1+A2 ✅. Created `hs-hero-kit.tsx` (the single hero-chrome
  implementation); refactored `command-centre-hero.tsx` to compose it (dashboard
  pixel-identical, classes verbatim); added `hs-hero-kit.test.tsx` (5 specs) locking the
  canonical NZ badge labels + one fire-drill threshold + the delta slot. Verified:
  `npm run types` clean · `npm run test` 5/5 · `npm run lint` (touched files) clean ·
  `npm run build` ✓. Files: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`,
  `…/command-centre-hero.tsx`, `…/hs-hero-kit.test.tsx`, this tracker. Next: **B** (rebuild
  the analytics hero on the kit).
- **Pass 2** — B1–B5, C1–C2, D2–D3, E1–E4, F1–F2, G1–G2, BK1 ✅. Rebuilt the analytics
  hero on the kit: `PageHero` → `HeroShell`; top-right `PageHeroStats` → Leading/Lagging
  `HeroCluster`s (4+4, mirroring the dashboard, with period-over-period deltas from
  `scorecard`); eyebrow → `HeroStatusPill`; medallion → `HeroMedallion(BarChart3)`;
  badges → `HeroComplianceBadges` (canonical NZ labels now identical on both pages);
  footer → `HeroSegmented`×2 + `EntityFilter` + `HeroSummaryStrip` (Hide-summary as a kit
  prop; Custom-range popover in the pill); dropped `brandColour`; sentence-case title;
  inline role-note → shared `RoleLensBanner`; `gap-4`→`gap-6`. Removed the page's local
  `Segmented`/`SummaryStat`/`CustomRange`. Verified: `types` clean · `lint` (analytics)
  clean · `test` 5/5 · `build` ✓. File: `resources/js/pages/health-safety/analytics.tsx`
  (+ tracker). **Remaining: D1** (fold analytics' 2 outline buttons into a popover) **+
  E5** (swap the dashboard's period/lens pills onto `HeroSegmented`) — final pass.
- **Pass 3** — D1 + E5 ✅ (**all boxes now ticked**). Folded analytics' two outline buttons
  into a translucent "Board reports ▾" popover (action row now mirrors the dashboard); moved
  the dashboard's hand-rolled period/lens pills onto `HeroSegmented` (kit is now the only
  segmented impl on both pages). Made `HeroSegmented` faithful to the dashboard's exact markup
  (pill label `mr-1`; segmented = fragment with `ml-1` label) so the dashboard stays
  pixel-identical (only `role`/`aria-pressed`/focus-ring added). Removed the dead `cn` import +
  page-local pill/seg consts. Verified: `types` clean · `lint` (3 files) clean · `test` 5/5 ·
  `build` ✓. Files: `hs-hero-kit.tsx`, `command-centre-hero.tsx`, `analytics.tsx` (+ tracker).

---

## ✅ Loop implementation complete

Every checklist box is `[x]`; `npm run types` / `npm run lint` / `npm run build` all pass; the
kit test (5 specs) is green. **Both heroes are composed entirely from `hs-hero-kit.tsx`** — no
page hand-rolls a primitive the other also has. The dashboard is **pixel-identical by
construction** (every kit-adoption pass preserved its Tailwind classes verbatim; only `role`/
`aria-pressed`/focus-ring a11y attributes were added). Analytics now renders Leading/Lagging
clusters with deltas, the five canonical compliance badges, the shared `RoleLensBanner`, and the
primary gradient (no `brandColour`).

**Open `TODO(Gx)`:** none. Two values analytics simply doesn't track were mapped to existing
behaviour, not stubbed against new schema (SDS expiry → "SDS current"; due-soon vs overdue drills
→ non-compliant = overdue) — see **C2 / BK1**.

**Final gate (needs a deploy — out of the loop's local reach):** the §5 *live* side-by-side
screenshot diff (`/health-safety` vs `/health-safety/analytics`) + the dashboard non-regression
screenshot. The work is on branch `hs-hero-consistency` (3 commits); per this repo's pattern it
merges to `main` → the deploy webhook builds it (~5–8 min) → Chrome-verify on `.com` as demo
admin. Awaiting the merge decision.
