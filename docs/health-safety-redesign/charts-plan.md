# Health & Safety Redesign — Workstream Plan: Charts (WS5)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §7 (pixel fidelity). Spec = `PROTOTYPE_DIGEST.md` §3. NZ-only.

## 0. Goal
Build the prototype's charts faithfully and place them in the right tabs, wired to live payload (no demo numbers).

## 1. Backend additions (chart data)
| Key | Source | Test |
|---|---|---|
| `frequency_operands` {near_misses, recordable} | `HsKpiService::nearMissOperands` (trailing 12mo) — feeds the ratio donut legend + arc fill | `test_near_miss_operands_count_over_window` |
| `hazard_burndown` [{week, open}] | `HsKpiService::hazardBurndown(6)` — open hazards at each week-end (`created_at ≤ end AND (closed_at NULL OR > end)`) | `test_hazard_burndown_returns_weekly_series` |
| `incidents_by_category` [{label, count}] | controller — `ClientIncident` grouped by `type` (period + site-scoped via shift), top 6 | (covered by controller test) |

## 2. Chart components — `components/charts.tsx`
- **Token-driven SVG ports** (exact geometry r=48, circ≈301.6; `var(--…)` colours, no raw hex): `Gauge`/`GaugeCard` (drill 86% · training 92%, `offset = circ·(1−pct/100)`), `RatioDonutCard` (arc fills to `near_misses/(near_misses+recordable)`, centre `{ratio}×`, real operand legend), `SeverityDonutCard` (3 stacked arcs success/warning/critical from `events_by_severity` via `mapSeverity`: low+medium→minor/moderate, high→serious, critical→critical).
- **recharts** (tuned to match): `IncidentTrendCard` — `ComposedChart` bars (primary 70%) + TRIFR line (critical 2.5px) + LTIFR line (warning 2.5px), merged by month; `variant='full'` adds dashed gridlines + legend, `'mini'` is compact. `HazardBurndownCard` — single primary line + end dot.
- **div bars**: `CategoryBarsCard` (track muted, fill primary, width = count/max).
- Grouped layouts `LeadingCharts` (ratio donut + burn-down, then drill + training gauges) and `LaggingCharts` (full trend, then severity donut + category bars) — placed in the Leading / Lagging tabs below their KPI cards.

## 3. Placement & scope
- Leading tab: `<LeadingPanel>` + `<LeadingCharts>`. Lagging tab: `<LaggingPanel>` + `<LaggingCharts>` + investigations worklist.
- **Pure additions** — the legacy Overview charts (old area trend / donut / gauges / hazard bar / monthly comparison) are LEFT in place this turn to avoid risky mass deletion; a later cleanup turn removes the legacy Overview body once everything's migrated. Interim duplication is acceptable; the page is never broken.
- **Site safety league** (chart 8) → WS8 (needs a per-site incidents+hazards payload).

## 4. Verify
- php -l (service/controller/test) clean. `npm run types` H&S-clean; `npx eslint` clean incl. raw-colour guard (SVG/recharts colours are `var(--token)`). Pixel parity vs prototype + axe → post-merge browser (worktree can't run vite build); backend tests → post-merge in parent.
