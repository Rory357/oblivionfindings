# Health & Safety Redesign — Workstream Plan: TabStrip + role lens (WS3)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §4. Visual spec = `PROTOTYPE_DIGEST.md` §2. NZ-only.

## 0. Goal
Reorganise the dashboard body under a 4-tab strip (Overview / Leading / Lagging / Compliance) with a role-lens banner, **progressively** — every tab has real content immediately; worklists (WS4), charts (WS5) and governance exports (WS8) fill in below the initial cards.

## 1. Approach (low-risk, progressive)
- **TabStrip** — reuse `@/components/rostering` `TabStrip` (value/onChange/items). Tones per prototype: Overview=primary, Leading=success, Lagging=critical, Compliance=primary. Dynamic count badges: Lagging=`worklists.open_investigations.length`, Compliance=`worklists.expiring.length`. Client-side `useState` tab state, default `overview`.
- **Role-lens banner** — dashed banner under the tabs with the three exact lens texts (governance/manager/frontline). The lens is already server-wired (the WS2 hero toggle posts `?lens=`); the banner reads `filters.lens`.
- **Overview tab** = the **existing body** (KPI grid + charts + backbone + drill table + recent activity + quick actions), wrapped once in `{tab === 'overview' && (<div>…</div>)}`. Nothing lost, page never broken. WS4 will replace its recent-activity lists with proper worklists + the site league.
- **Leading / Lagging / Compliance tabs** = NEW panels in `components/dashboard-tabs.tsx`, each opening with the prototype's KPI/status cards bound to the WS1 `leading_lagging` payload (the exact prototype tab structure). WS5 adds the charts below; WS8 adds the Compliance export strip.

## 2. Panel content (from the WS1 payload — no fake data)
| Tab | Cards | Source |
|---|---|---|
| Leading | Near-miss:incident · Actions on time · Training & audit · Open hazards | `leading_lagging.leading` |
| Lagging | Incidents · LTIFR · TRIFR · Days LTI-free | `leading_lagging.lagging` |
| Compliance | WorkSafe notifiable · Ngā Paerewa · Hazardous substances · Fire safety · First-aid cover | `worklists.expiring` / `notifiable_events` (+ 2 static, flagged) |

- Nulls render `—`. KPI cards are `Link`s to their registers; left-border accent toned by value.
- **Deviation:** the prototype's 4th Leading card is "Worker participation 78%" — no payload source, so substituted with **Open hazards** (real). Logged.

## 3. Verify
- `npm run types` (H&S files clean; repo-wide `@/routes` wayfinder noise ignored) + `npx eslint` (clean, raw-colour guard) — both green.
- Browser/visual parity post-merge (worktree can't run vite build).

## 4. Deferred to later workstreams
- Overview worklists + site league → WS4. Tab charts (trend/severity/gauges/burn-down) → WS5. Compliance governance export strip → WS8. The legacy Overview body is the interim fill until WS4/WS5 restructure it.
