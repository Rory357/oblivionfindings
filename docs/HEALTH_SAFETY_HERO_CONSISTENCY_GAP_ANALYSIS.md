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

- [ ] **B1** Replace `analytics.tsx` `PageHero` with `HeroShell` composed from the kit.
- [ ] **B2** Stats → Leading/Lagging `HeroCluster`s below the title (not top-right
  `PageHeroStats`); map `hero_stats` (+ `scorecard` split) with period-over-period
  `delta`/`deltaTone`. Note the 4-vs-8 tile choice here once decided.
- [ ] **B3** Eyebrow → `HeroStatusPill` (green dot), "Safety analytics · {rangeLabel}".
- [ ] **B4** Medallion → `HeroMedallion icon={BarChart3}` (kit size, `hidden sm:flex`).
- [ ] **B5** Description → one terse line; drop the multi-line prose + 3-item `meta[]`
  (keep at most one compact `meta` line).

## C. Canonical compliance-badge labels (P1)

- [ ] **C1** `HeroComplianceBadges` uses the dashboard's verbatim wording, fed by
  counts/booleans (never strings): `WorkSafe notifiable · {n} awaiting` ·
  `Ngā Paerewa NZS 8134:2021 · Certified` · `Hazardous substances · SDS current` /
  `· {n} SDS expiring` · `Fire · {n} drills due` (warning) / `· {n} drills overdue`
  (critical) / `Drills current` (success) · `First aid · Cover OK`. Tone map +
  fire-drill threshold defined once in the component.
- [ ] **C2** Analytics feeds `HeroComplianceBadges` (drops its reworded `PageHeroBadge`
  list + the `FlaskConical` icon + the bespoke `critical` dot tone).

## D. Action row & gradient (P2)

- [ ] **D1** Analytics folds its two loose outline buttons (Board pack / WorkSafe
  register) into the dashboard's translucent **popover** idiom (or at least its
  translucent-pill style); keep export-led verbs. White primary `Export` stays.
- [ ] **D2** Analytics drops `brandColour={props.site_brand_colour}` — primary gradient
  only (grep first; remove now-dead plumbing only if unused elsewhere).
- [ ] **D3** Custom drop-shadow lives in `HeroShell` so both heroes match (done via A1).

## E. Footer control band (P2)

- [ ] **E1** Rebuild analytics `heroFooter` from kit parts: `HeroSegmented` (period,
  pill variant) + `EntityFilter` (onDark) + `HeroSegmented` (lens) + `HeroSummaryStrip`.
  **Keep analytics' own presets** (Last 30d / Quarter / 6 months / YTD / Custom).
- [ ] **E2** Summary strip → `HeroSummaryStrip` + `HeroSummaryMetric` (dot-led), with
  the "Hide summary" toggle as the kit `onToggle`/`collapsed` prop.
- [ ] **E3** Add uppercase `Period` / `Lens` labels to the analytics footer.
- [ ] **E4** Preserve analytics' Custom-range popover (via `HeroSegmented` pill
  `popover` slot or beside it).
- [ ] **E5** Swap the dashboard's hand-rolled period/lens pills to `HeroSegmented` too
  (so the kit is the only implementation) — verify dashboard still pixel-identical.

## F. Title polish (P3)

- [ ] **F1** Medallion size/visibility aligned (done in A1/B4).
- [ ] **F2** One title casing convention — sentence case both ("Health & Safety
  analytics" to match "command centre").

## G. Page shell (P2/P3)

- [ ] **G1** Analytics replaces its inline role-note div with the shared `RoleLensBanner`
  (dashed/muted/`Search`-icon look).
- [ ] **G2** Analytics page wrapper `gap-4` → `gap-6` (`analytics.tsx`).

## Backend (minimal — NO speculative migrations)

- [ ] **BK1** Confirm no new controller params/migrations needed; if a cluster tile needs
  a value analytics doesn't send, stub `// TODO(Gx):` rendering `—` via `fmt()`. Data on
  hand: dashboard `leading_lagging` + `kpis`; analytics `hero_stats` (deltas),
  `period_summary`, `worksafe_notifiable`, `scorecard`.

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
