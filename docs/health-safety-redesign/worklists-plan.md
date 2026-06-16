# Health & Safety Redesign — Workstream Plan: Worklists + detail modal + context menu (WS4)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §4. Visual spec = `PROTOTYPE_DIGEST.md` §4/§5. NZ-only.

## 0. Goal
Turn the WS1 worklist payloads into the standardised actionable-row idiom: status pill + title/sub + owner + due; left-click → read-only detail modal; right-click → context menu with the client/staff deep-link jumps.

## 1. Build (reuse-first)
- **`components/hs-detail-dialog.tsx`** — generic `HsDetailDialog` on the Add-Client `WizardShell` chrome (single pane, `ReviewCard`/`ReviewRow`, footer Options bar), mirroring `emar/prn-detail-dialog`. Footer = Close · View register · Client · Staff · Print — all real `router.visit` deep-links (`/clients/{id}`, `/staff/{id}`, the module register). No Edit/Open-CA buttons yet (those open wizards → WS6/WS7; omitted, not stubbed).
- **`components/worklists.tsx`** — `HsWorklists` orchestrator: per-type pure row builders (corrective actions / investigations / notifiable / expiring) → a common `NormRow`; renders worklist cards (header + "View register" link + rows + empty state); manages one `ShiftContextMenu` (`@/components/rostering`) + one `HsDetailDialog`. Right-click items: View detail · View client · View staff · (sep) · View register · Print. Client/staff ids come straight from the WS1 builders (joined via `HsEvent`).
- Reuse `ShiftContextMenu` (`ShiftCtxState`/`ShiftCtxItem`), `WizardShell`/`ReviewCard`/`ReviewRow`, `Card`. Tokens only; plain string URLs.

## 2. Placement
- **Overview tab:** `HsWorklists show={['corrective_actions','notifiable','expiring']}` at the top (the actionable lead), above the retained legacy body.
- **Lagging tab:** `HsWorklists show={['investigations']}` below the lagging KPI cards.
- The `worklists` Props type tightened from loose `Record<string,unknown>[]` to the exported `WorklistsPayload` (precise row types matching the backend builders).

## 3. Deferred
- **Site safety league** (incidents + open hazards per site) → **WS8** (with the governance strip) — needs a new per-site payload key (`HsEvent.site_id` incidents + `SiteHazard.site_id` hazards). Not built here to keep WS4 backend-free.
- **Open-hazards worklist** in the Leading tab → deferred (no row payload; the Open-hazards KPI card already links to the register).
- Detail "Edit" / "Open corrective action" wizard actions → WS6/WS7.

## 4. Verify
- `npm run types` H&S-clean (repo-wide `@/routes` wayfinder noise excluded); `npx eslint` clean — the one raw-`<button>` worklist row carries a documented `no-restricted-syntax` disable (custom full-width selector). Browser/keyboard/visual parity post-merge.
