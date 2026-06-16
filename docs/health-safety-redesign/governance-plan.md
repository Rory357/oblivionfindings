# Health & Safety Redesign — Workstream Plan: Governance exports, site league, legacy cleanup (WS8)

> Final workstream. Spec = `PROTOTYPE_DIGEST.md` §2.3 (Overview/Compliance) + §3.8 (site league). NZ-only.

## 0. Goal
Finish the prototype's content and retire the old dashboard body.

## 1. Parts
- **WS8a (additive — done):** site safety league + governance export strip.
  - Backend: `HsDashboardService::siteLeague(from,to)` — per-site incidents (HsEvent, site_id) + open hazards (SiteHazard, site_id), ranked by `incidents×2 + hazards`; controller emits `site_league`.
  - Frontend: `charts.tsx` `SiteLeagueCard` (horizontal bars, tone by score, link to `/sites/{id}`); `dashboard-tabs.tsx` `GovernanceExports` (5 tiles → real `HsGovernanceReportController` routes: board-summary / worksafe-register / investigation-outcomes / corrective-action-traceability / risk-assessment-register). Overview gains the league + a mini incident trend; Compliance gains the export strip.
- **WS8b (legacy removal — next):** delete the old Overview body now superseded by the worklists (WS4) + tabs (WS3) + new charts (WS5): KPI grid, old recharts (area trend / severity donut / radial gauges / hazard bar / monthly comparison), backbone cards, drill-compliance table, recent-activity lists, quick-actions. Remove the dead `KPI_CONFIG` / `QUICK_ACTIONS` consts, the chart-derivation block, and now-unused imports (recharts, KPI icons). Overview final = worklists + league + mini trend (matching the prototype). Keep the page green (types/lint) after the deletion.

## 2. Final verification gate (after WS8b)
- `npm run types` H&S-clean; `npx eslint` clean incl. raw-colour guard; NZ grep of the touched surface (zero CQC/RIDDOR/HSE/COSHH/OSHA/TRIR).
- Backend feature tests run post-merge in the parent (worktree has no vendor).
- Browser parity + axe + drive every wizard → post-merge on the deployed site.
- Post the closing summary; the loop's definition of done is then met.

## 3. Notes
- Concurrent `/analytics` loop owns `analytics()` — left untouched. LTIFR/TRIFR service duplication = post-merge consolidation (logged in PROGRESS).
