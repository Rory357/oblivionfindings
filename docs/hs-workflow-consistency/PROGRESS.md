# H&S / Safety workflow consistency + Corrective Actions redesign

**Goal (user, 2026-06-19):** Implement `/health-safety/corrective-actions` from the Claude Design
handover, AND fix the "disjointed" feel across the recently-built safety surfaces
(`/incidents`, `/incidents?tab=near_misses`, `/fleet-assets/incidents`, `/safeguarding`,
`/health-safety`, `/health-safety/analytics`, `/health-safety/events`, `/health-safety/corrective-actions`),
AND reorganise the left nav so the workflows read in order. "Make it feel like one application."

NZ-only, web-only. Self-paced `/loop`.

Handover zip: `C:\Users\steph\Downloads\corrective-actions-handover\design_handoff_corrective_actions\`
(`README.md` = engineering spec; `Corrective Actions.dc.html` = visual prototype, do NOT copy).

---

## Diagnosis (parallel audit, 7 agents, 2026-06-19)

### The safety workflow spine (exists in data, invisible/one-way in UI)
`Report (ClientIncident / FleetIncident / SafeguardingConcern / near-miss) → converges into HsEvent
(via observers, idempotency_key = sha256("{class}:{id}:{category}")) → Investigate (HsInvestigation)
→ Corrective actions (open→in_progress→completed/awaiting-verify→verified→closed; verifier ≠ completer)
→ Close event → Monitor → Analyse (analytics)`. The H&S Dashboard is the command centre.

### Visual: 2 of 7 pages are off-pattern
- **hs-hero-kit** (gold standard): `/incidents`, `/safeguarding`, `/fleet-assets/incidents`, `/health-safety`, `/health-safety/analytics`.
- **governance-register-kit** (OLD `DesignHeroSection` chrome): `/health-safety/events` + `/health-safety/corrective-actions` ← the odd ones out. Handover = migrate both to hs-hero-kit.

### Connectivity gaps (the real "disjointed" cause)
- Backend: `ClientIncident` & `SafeguardingConcern` have **no `linkedHsEvent()`** resolver (only `FleetIncident` does); no source model has `->correctiveActions()`. `ControlRoomAlert` has no back-pointer. `HsCorrectiveAction.hs_event_id` NOT NULL; `createStandalone(HsEvent)` exists; sep-of-duties via `completed_by_user_id`/`verified_by_user_id`/`closed_by_user_id` (verify() throws if verifier===completer).
- Incident → raise corrective action lands on the register **index**, not the new row.
- Closing an incident = dead-end (no "next step / view in governance").
- Dashboard computes `open_safeguarding` / `fleet_incidents_30d` / `fleet_unresolved` but **never renders** them; no link to `/safeguarding` or `/fleet-assets/incidents`; **dashboard ↔ analytics not linked**; analytics has no "back to dashboard".
- Broken filter links: `/incidents?status=submitted|open` ignored (controller reads only `tab`).
- Silent incident→safeguarding auto-escalation (no UI feedback on the incident side).
- Events register: orphan "unwired" rows (source_id null) are dead-ends; `SiteHazard`/`RestraintEvent` sources have `url:null`; event `description`/`attachments` hardcoded empty in `buildEventDetail`.
- Corrective-actions page reaches origin only via 2 hops (action → parent event modal → originating card).

### Nav (all 7 are in the "Health & Safety" flyout, `buildSafetySubPanelGroups` app-sidebar.tsx:1140)
- Analytics sits at TOP (should be the "analyse" capstone); Corrective Actions stranded in a 3rd group;
  `/health-safety/events` mislabelled **"Investigations"**; Fleet Incidents **duplicated** in the
  Fleet & Assets flyout (different icon AlertOctagon / label "Incidents" / ungated).

### hs-hero-kit API (for the rebuild) — `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
`HeroShell{children,footer?}` (primary gradient, orbs) · `HeroStatusPill{children}` (green ping eyebrow) ·
`HeroMedallion{icon}` · `HeroCluster{title,icon,children}` (grid-cols-2 sm:grid-cols-4) ·
`HeroClusterTile{href?,label,value,caption,tone,delta?,deltaTone?}` (href ⇒ Inertia Link) ·
`HeroComplianceBadges{...}` · `HeroSegmented{label?,items,value,onChange,ariaLabel,variant?'pill'|'segmented'}` ·
`HeroSummaryStrip` · `HeroSummaryMetric{tone}` · `fmt` · `Tone='success'|'warning'|'critical'|'neutral'` · `DOT_CLASS`.
NO `cornerBadge` prop (render extra messages as `HeroStatusPill`). `EntityFilter` from `@/components/rostering`; `RoleLensBanner` from `./dashboard-tabs`.

---

## Plan (phased)

### ✅ Phase 0 — Audit + plan (DONE 2026-06-19)

### ✅ Phase 1 — Nav reorg (app-sidebar.tsx) — DONE 2026-06-19, `npm run types` clean
Reorder `buildSafetySubPanelGroups` to read as the workflow:
1. **Command centre** — H&S Dashboard
2. **Report & respond** — Incidents · Near Misses · Fleet Incidents · Safeguarding
3. **Investigate & resolve** — Events (relabel from "Investigations") · Corrective Actions
4. **Analyse & assure** — Analytics (moved down from group 1)
5+. (unchanged) H&S Management · Registers · Injury & Procedures · Compliance & Risk
- Reconcile Fleet-flyout duplicate (icon AlertOctagon→Truck, label "Incidents"→"Fleet Incidents"). Keep all gating.

### ✅ Phase 2 — Corrective Actions + Events migrate to hs-hero-kit — DONE 2026-06-19 (types+eslint clean)
- ✅ Backend payload D: `completed_by_user_id`/`completed_by_name`/`can_verify` on BOTH the corrective-actions
  register rows AND `buildEventDetail` dialog cards. `can.viewReports` (governance.view) added for Traceability CTA.
- ✅ A4: `register-row-kit.tsx` created (FlagBadge, RegisterTableHeader, titleCase, initials, entityTone, TONE_BG,
  TONE_DOT, Tone); both pages import it; `fmt` now from hs-hero-kit. **`governance-register-kit.tsx` DELETED** (retired).
  `git grep governance-register-kit resources/js` → only the comment in register-row-kit.
- ✅ corrective-actions/index.tsx: rebuilt on hs-hero-kit (HeroShell + eyebrow + "Verifier ≠ completer" chip + 2
  HeroClusters + footer Due/Site/Priority/search/Clear), rostering TabStrip, **kebab ⋮ per row** sharing the
  status-aware lifecycle menu (start→post / complete·verify·return→deep-link pane / close→post; Verify hidden unless
  can_verify), Traceability CTA (can.viewReports), actionable empty state ("Go to Events register" + Traceability).
  ActionRow TS wired with the new fields. Shell now `flex flex-col gap-6 p-6` (old oklch bg removed).
- ✅ events/index.tsx: migrated to hs-hero-kit + rostering TabStrip; "Board reports ▾" popover (5 report routes);
  footer Period/Site/Category/Source/WorkSafe-toggle/search inline onDark; ALL prior behaviour preserved (source
  cells, back-links, ctx menu, WorkSafe banner, closure gate, orphan/unwired notice). No oklch.
- ✅ EventDetailDialog: `initialActionTarget?: {actionId; pane:'complete'|'verify'|'return'}|null` deep-link
  (scroll+highlight), CorrectiveActionControls (Start/Complete/Verify+Return/Close per status, Verify guarded by
  can_verify, sep-of-duties note), 4 panes (Complete/Verify/Return/Add) with inline errors + Loader2. (Much
  scaffolding pre-existed — ca_add/ca_complete/ca_verify/ca_return panes; agent filled the gaps.)
- Verified: `npm run types` clean (filtered), `npx eslint` 0/0 on all 5 touched files, no raw oklch, no old-kit imports.

### 🔄 Phase 3 — Cross-link completion + wayfinding ("one app") — items 1-5 DONE 2026-06-19 (types+eslint clean)
- ✅ Backend (Agent C): `ClientIncident::linkedHsEvent()` + `linkedCorrectiveActions()`; same on `SafeguardingConcern`.
  Uses `HsEvent::buildIdempotencyKey(class,id,category)` (incident vs near_miss handled like the observer). php -l clean.
- ✅ Incident → raise corrective action (Agent H): success now `router.visit('/health-safety/corrective-actions?event='+d.hs_event.id)`
  → lands on the parent event's Corrective actions pane (the new action), not the bare index. In-dialog "Open register" link too.
- ✅ Dashboard hub (Agent D): new "Across the safety system" HeroCluster on command-centre-hero — Safeguarding (→/safeguarding),
  Fleet unresolved + Fleet 30d (→/fleet-assets/incidents), Analytics tile. Bidirectional dashboard↔analytics pills
  ("View analytics" / "H&S command centre"). KPIs were already in the `kpis` payload — just rendered now.
- ✅ Broken `?status=` links fixed: needs-attention.tsx + compliance/index.tsx → `?tab=open`. (Dashboard files had none.)
- ✅ Incident→safeguarding escalation banner (Agent H): prominent Overview InfoCard "Escalated to safeguarding — concern {ref}"
  with link, respecting need-to-know redaction (restricted note when not viewable).
- ✅ **Item 6 — shared `WorkflowRibbon`** (Report→Investigate→Resolve→Analyse, H&S home anchor, you-are-here active +
  links) added as the first child of all 5 register pages (incidents/safeguarding/fleet = report, events = investigate,
  corrective-actions = resolve). New `workflow-ribbon.tsx`. types+eslint clean.
- Verified: `npm run types` clean (filtered), `npx eslint` 0/0 on dashboard.tsx, command-centre-hero.tsx, analytics.tsx,
  incident-detail-dialog.tsx, needs-attention.tsx, compliance/index.tsx.

### 🔄 Phase 4 — Verify + deploy + Chrome-verify
- ✅ `npm run build` clean (exit 0; needed parent `vendor` JUNCTION + copied `.env` in the worktree so the wayfinder
  vite plugin could boot — ⚠️both gitignored, but DELETE the vendor junction before any worktree removal).
- ✅ Adversarial review Workflow (run `wf_2d5d231b-026`, 7 area-finders + per-finding verify): 1 finding, 1 confirmed.
  **Confirmed MAJOR bug FIXED**: EventDetailDialog derived section/pane only in useState initializers → re-targeting a
  different corrective action on the SAME already-open event (dialog keyed by event id → no remount) left the deep-link
  pane stale (silent no-op). Fix: added a prop-sync `useEffect` (deps = incoming prop values only, so it never fights
  in-dialog nav). types+eslint clean after.
- ⬜ commit → merge --no-ff → push (deploy webhook) → Chrome-verify on .com → update memory.
- `npm run types` · `npm run lint` · `npm run build`; touched specs. Backend tests in PARENT after merge (junction caveat).
- Merge → deploy webhook (~5-8min) → Chrome-verify on .com as Demo Admin; 0 console errors.

---

## Worktree env notes
- This worktree has **no `vendor/`** (frontend-only; node_modules is a junction) → `php artisan` can't boot here.
  Copied `resources/js/{routes,actions,wayfinder}` from the PARENT repo so `npm run types`/`npm run build`
  resolve `@/routes/*` & `@/actions/*` (they're gitignored generated stubs, absent in a fresh worktree).
- Reliable type-check signal: `npm run types 2>&1 | Select-String 'error TS' | Select-String -NotMatch '@/routes|@/actions'`.
- Backend verification: do it in the PARENT after merge (junction loads parent app/). PHP edits here travel with the branch.

## Log
- 2026-06-19: Phase 0 audit done (7 agents). Diagnosis + plan above.
- 2026-06-19: Phase 1 nav reorg DONE + types clean. Backend payload D DONE (agent). Wayfinder stubs copied into worktree.
- 2026-06-19: Phase 2 DONE — A4 (register-row-kit + delete old kit), corrective-actions + events migrated to
  hs-hero-kit (2 agents in parallel for dialog + events), kebab/lifecycle/deep-link panes wired. types+eslint clean.
  Next: Phase 3 cross-links/wayfinding. Files touched: app-sidebar.tsx, register-row-kit.tsx, corrective-actions/index.tsx,
  events/index.tsx, event-detail-dialog.tsx, HsEventController.php (correctiveActions can.viewReports + buildEventDetail can_verify).
- 2026-06-19: Phase 3 items 1-5 DONE (3 parallel agents C/D/H + 2 self link-fixes). types+eslint clean. Phase-3 files:
  ClientIncident.php, SafeguardingConcern.php, dashboard.tsx, command-centre-hero.tsx, analytics.tsx,
  incident-detail-dialog.tsx, needs-attention.tsx, compliance/index.tsx. NEXT: item 6 ribbon, then Phase 4 build+deploy+verify.
