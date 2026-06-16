# Health & Safety Redesign — Workstream Plan: Hero command centre (WS2)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §4. Visual spec = `PROTOTYPE_DIGEST.md` §1. NZ-only.

## 0. Identity
- **Workstream:** Hero command centre + footer band.
- **One-line goal:** replace the simple `PageHero` (title + 4 stats + 2 links) with the command-centre hero — eyebrow, title + active site, NZ compliance badges, leading-vs-lagging stat clusters, and a working footer band (period range · site · lens · "this week" strip).

## 1. Approach — dedicated component, reuse the shell
- New `resources/js/pages/health-safety/components/command-centre-hero.tsx`, **mirroring `pages/operations/handovers/components/handovers-hero.tsx`** (the canonical command-centre hero): `PageHero category="ops"` shell with `icon`/`title`/`description`/`badges`, the live-dot **eyebrow as `children`** (the app idiom), and the controls in `footer`. Do NOT rebuild `PageHero`.
- The two **leading/lagging stat clusters** render inside `children` (full-width — no right-column `stats`/`actions` passed, so the title column is full width). PageHero's single right-column `stats` can't show two labelled clusters, so we compose them as a small local `<ClusterTile>` grid (token-only translucent tiles + tone dot), each tile a `<Link>` to its register.

## 2. Prop sources (all from the WS1 payload)
| Hero element | Source |
|---|---|
| Eyebrow "Safety system · synced just now" | static (presence-dot idiom; no fake timestamp) |
| Title "Health & Safety command centre" | static |
| Meta: underlined active site · N sites · PCBU view | `filters.site`→`sites`; `sites.length`. **orgName deferred** (no clean payload source — note §6) |
| Badge: WorkSafe notifiable · N awaiting | `worklists.notifiable_events` where status=`pending` (0 → success, else warning) |
| Badge: Ngā Paerewa NZS 8134:2021 · Certified | **static** (no certification model — audit; success, TODO) |
| Badge: Hazardous substances · N SDS expiring | `worklists.expiring` where type=`sds` (0 → success, else warning) |
| Badge: Fire · Drills current / N due | `worklists.expiring` where type=`drill` |
| Badge: First aid · Cover OK | **static** (no cover model — audit; success, TODO) |
| Lagging cluster: Incidents · LTIFR · TRIFR · Days LTI-free | `leading_lagging.lagging` (null → "—") |
| Leading cluster: Near-miss × · Actions % · Train/audit % · Open hazards | `leading_lagging.leading` |
| Footer period pills (This week/30 days/Quarter/Custom) | derive active from `filters.from/to`; click → Inertia `router.get` with computed range |
| Footer site filter | `EntityFilter onDark` items=`sites`, value=`filters.site` |
| Footer lens toggle | `filters.lens`; click → `router.get({lens})` |
| Footer "this week" strip (5 metrics + dots) | incidents=`leading_lagging.lagging.incidents`; notifiable=`worklists.notifiable_events`; hazards=`leading_lagging.leading.open_hazards`; drills due=`worklists.expiring` type=drill; lone-workers=`kpis.active_alerts` (0 → "all checked in") |

## 3. Footer control wiring (G4/G3 — real, not stubs)
- All controls call `router.get('/health-safety', merged, { preserveScroll: true })` merging current `filters` + the changed param. The WS1 controller already reads `?from/?to/?site/?lens`.
- Period presets compute `from/to`: week=startOfWeek→now, 30d=−30d→now, quarter=−90d→now, custom=two `<input type=date>` in a popover.
- Active period derived by matching `filters.from/to` to a preset; default highlights **30 days** (the controller default), a documented deviation from the prototype's cosmetic "This week" default.

## 4. Reuse / tokens
- `PageHero` (`@/components/page`), `EntityFilter` (`@/components/rostering`), `Badge`/`Button` (`@/components/ui`), `router`/`Link` (`@inertiajs/react`). Lucide icons.
- **Tokens only** — translucent hero surfaces via `bg-primary-foreground/10`, tone dots via `bg-status-success|warning|critical`. No raw hex/oklch. Non-shadcn dark-hero buttons get the `no-restricted-syntax` eslint-disable like handovers.
- **Plain string URLs** (e.g. `/health-safety`, `/incidents`) — avoid wayfinder route-helper imports (wayfinder can't regenerate in this vendorless worktree).

## 5. dashboard.tsx changes
- Extend `Props` with `filters`, `lens`, `sites`, `leading_lagging`, `frequency_trends`, `worklists` (always present from WS1).
- Import + render `<CommandCentreHero …/>` in place of the `<PageHero …/>` block (lines ~637–683). **Keep the rest of the body** (KPI grid, charts, backbone) — it still reads the legacy keys and renders, so the page is never broken. WS3+ migrate the body into tabs/worklists.
- The `＋ Report` launcher (WS6) and `Export board summary` (WS8) actions are **intentionally omitted** until their workstreams build the backing UI (no inert stubs — [[feedback_hide_unbuilt_actions]]).

## 6. Deferred / notes
- **＋Report** → WS6; **Export board summary** → WS8.
- **orgName** in the meta line: no clean payload source; show site + count + PCBU only for now (add org to payload in a later pass if a tenant/org name prop exists).
- **Custom range** picker: implement two native date inputs (no heavy date-picker dep).
- **Concurrent /analytics loop** (branch `claude/sharp-hypatia-*`): owns `analytics()` — my WS1 site_comparison fix was reverted to avoid a cross-branch conflict; LTIFR/TRIFR duplication (their `HsAnalyticsService` timesheet-hours vs my `HsKpiService` BillingEntry-hours) is a post-merge consolidation item.

## 7. Verification
- `npm run types` (best-effort — `npm run build` can't run in this worktree: wayfinder→artisan needs vendor). Token/eslint raw-colour guard via review (lint may also need the full toolchain). Visual parity verified post-merge on the deployed site (Chrome MCP), per the established worktree pattern.
