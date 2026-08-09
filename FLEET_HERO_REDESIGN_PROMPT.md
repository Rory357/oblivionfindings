# Fleet & Assets Dashboard HERO Redesign — PROMPT

> Paste this whole brief to **Claude design**. It redesigns the **hero banner** of `/fleet-assets` (`resources/js/pages/fleet-assets/dashboard.tsx`, the `<HeroShell>` block ~lines 469–601) **plus the two things that collide with it**: the standalone "Attention Required" red banner directly below it, and the duplicate body "Quick Actions" card. Scope is the hero zone — do NOT rebuild the map, donuts, alerts feed, or the rest of the page. The bar is the gold-standard heroes: `/health-safety` (hs-hero-kit), `/fleet-assets/maintenance/dashboard`, `/fleet-assets/vehicles`, `/meds/today`.

---

## 0. Mission

The flagship fleet hero is already on the shared kit, but it's the **weakest hero in its own module**: no `FleetComplianceBadges` (its sibling `/fleet-assets/vehicles` has them), no `HeroSummaryStrip` (maintenance/compliance/drivers dashboards have one), every "Today" tile is neutral even when a resident outing is past its return time, escalation lives in a hand-rolled red banner *below* the hero, quick actions are duplicated twice on one screen, and a support worker sees org-wide numbers while "their site's" vehicles sit at the very bottom of the page. Turn it into the true **fleet command centre**: one hero that owns identity, escalation, today's operations, NZ compliance, and the canonical quick actions — with a My-site lens for frontline staff.

---

## 1. Non-negotiables

- **Web app only.** Desktop web; no phone frames or mobile-app chrome.
- **NZ-only.** `en-NZ`, NZD via `formatCurrency` from `@/lib/fleet-utils`, WOF/Rego/CoF vocabulary. Don't "fix" to GBP/USD.
- **Reuse the kit — extend, don't fork.** Everything comes from `@/pages/fleet-assets/components/fleet-hero-kit` (which re-exports the generic primitives from `health-safety/components/hs-hero-kit`). If a primitive is missing a feature, extend it **in the kit** so every fleet hero benefits. No hand-rolled chips, pills, tiles, or buttons in the page file.
- **Tokens only.** Semantic tokens (`status-*`, `primary-foreground/*`) — no raw hex/oklch in the hero. (The donuts below keep their hex for now; out of scope.)
- **Alerts belong to Control Room.** Alert chips/tiles deep-link to `/fleet-assets/alerts`; do not build any inline acknowledge/resolve into the hero.
- **Keep the 30s partial reload working.** `router.reload({ only: [...] })` — every new hero prop must be added to that `only` list, and hero components must tolerate `undefined` during partial loads.
- **No schema invention.** The insurance chip auto-hides when `assets.insurance_expires_at` doesn't exist (pass `null`) — that guard already exists in `FleetComplianceBadges`; keep it.
- **No regressions** to the map, donut cards, Recent Alerts/Activity feeds, Today's Outings, or Vehicles at Your Site cards (other than the explicit dedupe in §4E).

---

## 2. The kit you MUST use (exact imports)

From `@/pages/fleet-assets/components/fleet-hero-kit`:

- `HeroShell` (`footer` slot renders above a top border — use it), `HeroMedallion`, `HeroStatusPill`, `HeroCluster`, `HeroClusterTile` (has `href`, `tone`, optional `delta`), `HeroSegmented` (`variant="segmented"` for the lens; `pill` for periods), `HeroSummaryStrip` + `HeroSummaryMetric`, `fmt`, `Tone`.
- `FleetComplianceBadges` — the canonical five NZ chips (WOF expired>due escalation · Rego · CoF · Insurance-or-hidden · Control-Room alerts), fed by **raw counts**, each chip linkable via `hrefs`. Copy the exact usage from `resources/js/pages/fleet-assets/vehicles/index.tsx` lines ~177–192.
- `FleetHeroAction` — on-dark quick action; exactly **one** `emphasis` per hero.

Reference heroes to study before writing code: `fleet-assets/vehicles/index.tsx` (badges footer), `fleet-assets/maintenance/dashboard.tsx` (`HeroSegmented` + `HeroSummaryStrip` composition), `health-safety/dashboard.tsx` (cluster tone discipline).

---

## 3. Audit first (paste results back as your first pass)

- [ ] Screenshot the current hero, the red "Attention Required" banner, and the body Quick Actions card at `lg` and `sm` widths, light + dark.
- [ ] Confirm the current hero anatomy: identity row (medallion · pill · title) → 3 clusters (Fleet status ×4 · Today ×3 · Compliance ×3) → footer quick-actions row (4 actions + settings gear).
- [ ] Confirm what the controller already sends (`app/Http/Controllers/FleetAssets/DashboardController.php` → `stats`): `total_vehicles, online_count, offline_count, checked_out_count, vehicles_in_maintenance, trips_today, recent_bookings_count, active_outings, outings_past_return, overdue_count, wof_due_30, rego_due_30, active_alerts, critical_alerts, fuel_cost_mtd, distance_mtd, total_devices, online_devices, tracked_residents, upcoming_maintenance_count` + `my_site_vehicles`, `fleet_by_site`.
- [ ] Confirm what's **missing** for the compliance chips: `wof_expired`, `cof_due`, `insurance_expiring` (§5).
- [ ] Walk the three personas in §6 against the current hero and note every mismatch.

**Known gaps this audit already surfaced (confirm, then fix):**
1. **No `FleetComplianceBadges`** on the module's flagship hero — WOF/Rego live as two cluster tiles; CoF and Insurance are absent entirely; the "Alerts" tile triple-duplicates the red banner and the body alerts card.
2. **No `HeroSummaryStrip`** — fuel MTD, km MTD, devices online, tracked residents are pushed down into scattered body cards.
3. **Escalation lives outside the hero** — overdue returns / outings past return / critical alerts render in a hand-rolled `border-status-critical` div *below* the shell, while the hero's own "Today" tiles stay `tone="neutral"` no matter what.
4. **Quick actions duplicated** — 4 in the hero footer + a 12-tile body "Quick Actions" card with hex-coloured `QuickActionTile`s; two competing affordances for the same jobs.
5. **No site lens** — org-wide numbers only; `my_site_vehicles` ships on this very page but renders as the last card. A frontline worker can't make the hero answer "can I take someone out from *my* house right now?"
6. **Silent live updates** — 30s reload changes numbers with no `aria-live`; the pill timestamp updates invisibly for screen readers.
7. Minor: the derived `onTimeRate` (line ~432) is fake (`100` whenever trips>0) — delete it rather than surface it.

---

## 4. The redesigned hero, zone by zone

### A. Identity row (top)
- Keep: `HeroMedallion icon={Car}`, `HeroStatusPill` ("Fleet command · updated HH:MM" in `en-NZ`), `h1` "Fleet & Assets", one-line subtitle.
- Add `aria-live="polite"` on the timestamp span inside the pill so the 30s refresh is announced, and a subtle `RefreshCw` spin during reload (copy the `isRefreshing` idiom from `vehicles/index.tsx`), guarded with `motion-reduce:animate-none`.
- Right-align a **scope lens**: `HeroSegmented variant="segmented"` with `All sites · My site` (label "Scope"). "My site" filters the **clusters** (not the badges) to the user's site via a `?scope=mine` param the controller honours (§5). If the user has no resolvable site, don't render the lens at all.

### B. Attention strip (renders only when something is wrong)
- Absorb the standalone red banner into the hero: when `overdue_count > 0 || outings_past_return > 0 || critical_alerts > 0`, render one on-dark strip directly under the identity row — an `AlertTriangle` + "Needs attention" eyebrow + the same three deep-linked chips (overdue returns → `/fleet-assets/bookings`, outings past return → `/fleet-assets/outings`, critical alerts → `/fleet-assets/alerts`).
- Build it as a small kit component (`FleetAttentionStrip`) in `fleet-hero-kit.tsx` using the existing chip classes (`status-critical/50` borders, `bg-status-critical/25`) so it reads like the warning/critical compliance chips. Then **delete** the old below-hero banner (lines ~606–635).
- `role="status"` on the strip; each chip keeps count + noun ("2 overdue vehicle returns"), never colour-only.

### C. Clusters (the middle band) — three clusters, retoned and re-scoped
1. **Fleet status** (keep 4 tiles: Online / In use / Maintenance / Offline) — unchanged semantics; Offline gets `tone={offline_count > 0 ? 'warning' : 'success'}` instead of permanent neutral.
2. **Today** (Trips today · Bookings · Outings) — fix the tone discipline: Outings tile goes `critical` with caption "`{n} past return`" when `outings_past_return > 0`; Bookings tile goes `warning` with caption "`{n} overdue return{s}`" when `overdue_count > 0`; otherwise keep current captions. Tiles keep their deep links.
3. **Resident movement** (NEW — replaces the old "Compliance" cluster, whose job moves to the badges row in §D): `Transports today` (→ `/fleet-assets/transports`), `Tracked residents` (→ `/fleet-assets/resident-tracking`), `Wandering alerts` (→ `/fleet-assets/resident-tracking?tab=wandering`, `critical` tone when > 0, `success` when 0). This puts the *people* the fleet exists for onto the hero — vehicles here are how residents access their community, not a logistics fleet. Needs two new counts (§5).
- Keep the `lg:grid-cols-2 xl:grid-cols-[1.25fr_1fr_1fr]` responsive grid.

### D. Compliance badges row
- `FleetComplianceBadges` with real counts: `wofDue={stats.wof_due_30} wofExpired={stats.wof_expired} regoDue={stats.rego_due_30} cofDue={stats.cof_due} insuranceExpiring={stats.insurance_expiring} openAlerts={stats.active_alerts} criticalAlerts={stats.critical_alerts}` and `hrefs` → `/fleet-assets/compliance` (×4) + `/fleet-assets/alerts`. Identical composition to the vehicles hero so the two pages read as siblings.
- Place it in the `HeroSummaryStrip` zone or directly above the footer — pick whichever matches the maintenance dashboard's rhythm best, and say why.

### E. Summary strip + footer
- `HeroSummaryStrip label="This month"` with `HeroSummaryMetric`s: fuel `formatCurrency(fuel_cost_mtd)` · `fmt(distance_mtd, ' km')` · devices `online_devices/total_devices online` (tone `warning` when any offline) · `fmt(upcoming_maintenance_count)` services due. Raw numbers in, formatting at render.
- Footer quick actions — **one canonical row** (this is now the only quick-action surface on the page): `Book vehicle` (emphasis) · `Daily check` (→ `/fleet-assets/daily-check` — the frontline pre-drive hot path; today it's missing from this hero) · `Log fuel` · `Report incident` · `New work order` · settings gear stays right-aligned.
- **Retire the body "Quick Actions" card** (12 hex-coloured `QuickActionTile`s): the four action-jobs are covered by the hero footer; the eight navigation-jobs (Vehicles, Assets, Devices, Reports, Map, Residents, Outings, Mileage) become a slim on-light "Explore" link-row in the card's place (plain tokens, no hex) — or, if the sidebar already covers navigation, delete the card and let the right column breathe. State which you chose and why.

---

## 5. Backend (DashboardController) — small, copy-paste additions

Extend `stats` in `app/Http/Controllers/FleetAssets/DashboardController.php`; **copy the exact query patterns from `VehicleController::index` lines ~130–156** (they're already efficient COUNT queries with the right guards):
- `wof_expired` (`wof_expires_at < now()`), `cof_due` (30-day window), `insurance_expiring` (**`Schema::hasColumn('assets','insurance_expires_at')` guard, else `null`**).
- `transports_today` (today's `App\Models\FleetResidentTransport` rows) and `open_wandering_alerts` for the Resident-movement cluster — reuse whatever count queries the transports/wandering pages already run; if a table might not exist, `Schema::hasTable` guard → 0 like `fleet_outings` already does.
- Honour `?scope=mine`: when present and the user's site resolves (reuse the existing `$userSiteId` fallback logic in this controller), scope the **cluster** counts (vehicle status, today, resident movement) to that site; compliance chips and month strip stay org-wide. Return `scope` + `has_site` so the lens can render its state.
- Add every new key to the frontend `stats` fallback object and to the 30s `router.reload` `only` list.
- Type all new props in the page's `Props` interface. No new packages, no migrations.

---

## 6. Who this hero serves (walk these before and after)

1. **Support worker, 7:40am, planning a community outing** — opens `/fleet-assets`, flips lens to *My site*: sees online/available vehicles at their house, taps `Daily check`, then `Book vehicle`. Two taps to each hot path; no scrolling to the bottom card. Vehicles are how residents get to their own lives — the hero should make taking someone out *easier*, not bury it under org KPIs (Enabling Good Lives: community access, self-determination).
2. **Coordinator, mid-morning** — one glance: attention strip says an outing is past return and one booking is overdue; Outings tile is red, chips say WOF · 2 due 30d. Every number is a deep link; nothing requires opening three pages to triage. A resident overdue back from an outing is a safeguarding signal and must out-rank fuel spend visually.
3. **Compliance/back-office** — the five NZ chips (WOF/Rego/CoF/Insurance/Alerts) match the vehicles page exactly and click through to the compliance register; evidence lives there, not in the hero.

---

## 7. Accessibility & polish

- `aria-live="polite"` for the refresh timestamp; `role="status"` on the attention strip; every interactive element keeps the kit's `focus-visible:ring-2` ring.
- Tone is never colour-only — every warning/critical tile/chip carries the count and noun in text.
- `tabular-nums` on all values (kit default), `motion-reduce` guards on ping dot + refresh spinner, WCAG AA contrast for on-dark text (the kit's `primary-foreground/60`+ scales pass; don't go dimmer).
- Empty/zero states: clusters render zeros gracefully (`fmt` gives "0"), attention strip and lens simply don't render when irrelevant. No layout shift when they appear — reserve nothing, let the flex column reflow (matches HeroShell's `gap-5`).

---

## 8. Acceptance checklist

- [ ] Hero contains: identity row + lens · (conditional) attention strip · 3 clusters · compliance badges · "This month" summary strip · single canonical quick-action footer.
- [ ] Old red "Attention Required" banner deleted; body "Quick Actions" card retired/replaced per §4E; no duplicate affordances remain on the page.
- [ ] `FleetComplianceBadges` shows real `wof_expired`/`cof_due`/`insurance_expiring` counts (insurance chip hidden on schemas without the column).
- [ ] Outings-past-return and overdue-returns escalate tile tones AND appear in the attention strip; all deep links land on the right filtered pages.
- [ ] `My site` lens filters cluster counts server-side; hidden when the user has no site; survives the 30s partial reload.
- [ ] All new stats in `Props`, the fallback object, and the `reload only` list; page tolerates `undefined` mid-reload.
- [ ] Zero hand-rolled hero elements in the page file; new `FleetAttentionStrip` lives in `fleet-hero-kit.tsx` with a doc comment.
- [ ] Light + dark, `sm` → `xl` screenshots; `npm run build` (or vite build) passes; no console errors.
- [ ] Screenshot diff vs `/fleet-assets/vehicles` and `/fleet-assets/maintenance/dashboard` heroes — the three must read as one family.

**Guardrails:** don't touch the map/donuts/feeds beyond §4E; don't add inline alert actions (Control Room owns triage); don't invent schema; don't add dependencies; keep every existing deep link working.
