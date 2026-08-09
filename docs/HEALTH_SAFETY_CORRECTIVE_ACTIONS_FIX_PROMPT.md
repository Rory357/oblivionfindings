# Claude Code — H&S Corrective Actions redesign & lifecycle FIX PROMPT

> Paste this whole file to Claude Code. Re-audit the live code each pass before editing; tick the
> checklist as items land. Findings & rationale live in `HEALTH_SAFETY_CORRECTIVE_ACTIONS_AUDIT.md`.

## Role & mission

You are working in the **Oblivion Findings** NZ supported-living app (Laravel + Inertia + React + TS,
Tailwind, shadcn). Make the **Corrective actions** register consistent with the rest of the Health &
Safety module and add the corrective-action lifecycle to the row right-click menu. Bring the **sibling
Events register** along in the same pass — the two share a kit and a modal and must read as one product.

This **completes the unfinished standardisation** already decided in
`HEALTH_SAFETY_EVENTS_GAP_ANALYSIS.md` (open items **H1** "idioms match the app verbatim … one product
with dashboard/analytics" and **H2** "semantic tokens only — no raw `oklch()`"). The gold-standard hero
kit is **`hs-hero-kit.tsx`**, already merged & live (`HEALTH_SAFETY_HERO_CONSISTENCY_GAP_ANALYSIS.md`)
and used by `/incidents`, `/health-safety` (dashboard) and `/health-safety/analytics`.

## Hard guardrails (do not violate)

- **Semantic tokens only — zero raw `oklch()` / hex** in the touched pages. (Kills the indigo hero
  gradient and the `bg-[oklch(0.98_0.006_277)]` page background.)
- **`hs-hero-kit.tsx` is the single hero implementation.** Do **not** create a third kit, and do **not**
  hand-roll a primitive it already exports. Compose from it exactly as `/incidents` does.
- **Web-only** — no phone frames / mobile-app chrome. Responsive reflow only.
- **NZ-only** frameworks (HSWA 2015 s23/s24 notifiable; Ngā Paerewa NZS 8134:2021; ACC). No RIDDOR/HSE/CQC/TRIR.
- **No new DB schema / migrations.** Backend changes are serialisation-only (see §D).
- **Don't regress Events' existing behaviour** — convergence rows, source back-links, WorkSafe banner,
  closure gate, investigation workflow all keep working. Keep `/health-safety/events/{id}` as the
  deep-link fallback.
- A corrective action is **always event-scoped** — there is **no orphan create**. Do **not** add a
  "New action" hero CTA. Creation stays event-scoped via right-click "Add corrective action."
- Reuse the shared row primitives — don't reinvent `FlagBadge` / `RegisterTableHeader` / avatars.

## Reference files (read before editing)

- Gold standard page: `resources/js/pages/incidents/index.tsx`
- Gold standard kit: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
- Target: `resources/js/pages/health-safety/corrective-actions/index.tsx`
- Sibling (same migration): `resources/js/pages/health-safety/events/index.tsx`
- Kit being retired (hero parts): `resources/js/pages/health-safety/components/governance-register-kit.tsx`
- Shared: `resources/js/components/rostering/{tab-strip,entity-filter,shift-context-menu}.tsx`
- Shared modal: `resources/js/components/health-safety/event-detail-dialog.tsx`
- Backend: `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`, `routes/health-safety.php`
- Style guides: `docs/POPUP_STYLE_GUIDE.md`, `docs/DESIGN_TOKENS.md`

---

## A. Hero / tab / filter migration (both pages) — `hs-hero-kit`

Replace the `governance-register-kit` chrome with the `hs-hero-kit` chrome, matching `/incidents`:

| Replace (`governance-register-kit`) | With (`hs-hero-kit`) |
|---|---|
| `DesignHeroSection` (medallion/eyebrow/title/desc/clusters/footer) | `HeroShell` + `HeroMedallion` + `HeroStatusPill` + h1/description, `footer={…}` |
| indigo `linear-gradient(135deg, oklch(...))` | `HeroShell`'s built-in `--primary` gradient (no gradient prop) |
| `DesignHeroCluster` / `DesignHeroTile` | `HeroCluster` / `HeroClusterTile` (same label/value/caption/tone; keep `href` deep-links) |
| `DesignTabStrip` (`DesignTabItem`) | `TabStrip` (`RosterTabItem`) — map tones: `primary→primary, info→info, warning→warning, critical→critical, success→success` |
| `HeroRangePill` row (Due/Period) | `HeroSegmented` `variant="pill"` `label="Due"` (keep the All / This week / 30 days / Quarter presets) |
| `HeroSelect` Site dropdown | `EntityFilter` `label="Site"` `allLabel="All sites"` `items={sites}` `value={filters.site_id}` `onChange={(id)=>go({site_id:id})}` `onDark` |
| `HeroSelect` Priority / `HeroSelect` Category | keep as small on-dark `HeroSegmented` pills (corrective: Priority; events: keep Category/Source/WorkSafe toggle) |
| `HeroSearch` (`rounded-full`) | the inline on-dark search input pattern from `/incidents` (`rounded-lg`) |
| page wrapper `min-h-screen bg-[oklch(...)] px-4 py-5 … gap-4` | `flex flex-col gap-6 p-6` (Incident shell) |

Preserve the **corrective-actions corner badge** "Verifier ≠ completer" (render it as a `HeroStatusPill`
or a small chip in the hero header — keep the message, drop the `cornerBadge` prop that doesn't exist on `HeroShell`).

**Preserve non-hero helpers:** `FlagBadge`, `RegisterTableHeader`, `titleCase`, `fmt`, `initials`,
`entityTone`, `TONE_BG`, `TONE_DOT` are still used by the tables. Move them into a neutral shared module
(suggest `resources/js/pages/health-safety/components/register-row-kit.tsx`) and update imports in both
pages. Do **not** delete them with the hero.

## B. Hero CTA (parity with the Incident "Report" affordance)

Add a top-right hero action. **Not** a create button (actions are event-scoped). Use:

- **Corrective actions:** a primary button **"Traceability report"** → `/health-safety/reports/corrective-action-traceability`
  (route name `health-safety.reports.corrective-action-traceability`). It is gated **`permission:governance.view`**
  (not `hazards.manage`) — render the button only when the user has that permission (pass a `can.viewReports`
  boolean from the controller, or reuse an existing governance-view flag).
- **Events:** a translucent **"Board reports ▾"** popover mirroring the analytics action row
  (`HEALTH_SAFETY_HERO_CONSISTENCY_GAP_ANALYSIS.md` D1), listing the five `/health-safety/reports/*` routes
  (board-summary, worksafe-register, investigation-outcomes, corrective-action-traceability,
  risk-assessment-register). Optional this pass.

## C. Right-click — full corrective-action lifecycle (the headline change)

Rebuild `openRowCtx` in `corrective-actions/index.tsx` to build a status-aware `ShiftCtxItem[]`
(tones available: `primary | critical`). All write items require `can.manage` **and**
`action.event.status !== 'closed'`.

```
status 'open'        → { Start action }            primary  → router.post(`${base}/start`, {}, { preserveScroll:true })
status 'in_progress' → { Mark complete… }          primary  → openActionPane(action, 'complete')
status 'completed'   → { Verify… }                 primary  → openActionPane(action, 'verify')
                       { Return for rework… }       critical → openActionPane(action, 'return')
status 'verified'    → { Close action }                      → router.post(`${base}/close`, {}, { preserveScroll:true })
always (separator)   → Open corrective actions  → openEvent(event.id,{section:'actions'})
                       View parent event        → openEvent(event.id,{section:'overview'})
                       Add corrective action    → openEvent(event.id,{action:'add_action'})   (gated)
                       Open event full page     → router.visit(`/health-safety/events/${event.id}`)
```

where `base = '/health-safety/events/' + action.event.id + '/corrective-actions/' + action.id`.

- `start` and `close` are **inputless** → post directly (matches the modal's own inline `CorrectiveActionControls`).
- `complete` / `verify` / `return` need form input → **deep-link into the modal pane** (§E), reusing the
  existing validated forms. Do not raw-post these.
- **Verify guard:** hide/disable **Verify** when the viewer completed the action (use the new
  `can_verify` payload from §D); otherwise allow and let the server gate (`flash.error`) catch it.
- Keep the `tag` = priority label, `meta` = `${reference_number} · ${title}` on the menu header.

Also add a visible **per-row kebab (`⋮`) button** that opens the *same* menu payload (right-click has no
keyboard equivalent; this is the a11y + discoverability fix). Stop propagation so it doesn't also trigger
the row's open-on-click.

## D. Backend / payload (serialisation only — NO migration)

In `HsEventController@correctiveActions`, extend the `ActionRow` transform with:

- `completed_by_user_id: int|null`, `completed_by_name: string|null` (from `HsCorrectiveAction->completed_by_user_id`),
- `can_verify: bool` = `can.manage && status === 'completed' && completed_by_user_id !== auth()->id()`.

Mirror the new fields in the `ActionRow` TS type. No other backend change — every lifecycle route
already exists and is gated `hazards.manage`.

## E. Modal deep-link extension — `EventDetailDialog`

Additive only; keep `initialSection` / `initialAction` working.

```ts
// new optional prop:
initialActionTarget?: { actionId: number; pane: 'complete' | 'verify' | 'return' };

// in the pane useState initializer: if initialActionTarget set →
//   setSection('actions');
//   pane = { complete:'ca_complete', verify:'ca_verify', return:'ca_return' }[pane] + { actionId }
// also scroll the matching action row into view + briefly highlight it (M-2).
```

In `corrective-actions/index.tsx`, add `pendingActionTarget` state next to `pendingSection`/
`pendingAction`, set it from `openActionPane(action, pane)`, fetch `detail` via the existing
`router.get(..., { only:['detail'] })`, and pass it to `<EventDetailDialog initialActionTarget={…} />`.
Reset it on close.

Spot-check the `ca_complete`/`ca_verify`/`ca_return` panes against `docs/POPUP_STYLE_GUIDE.md`:
`Loader2` while `form.processing`, sentence-case verbs, **inline** errors (never `toast`),
`preserveScroll`/`preserveState` posts, separation-of-duties message retained on verify.

## F. Empty state

Replace the dead empty state with an actionable one: explain that corrective actions are raised from
safety events, with a button to the **Events register** (`/health-safety/events`) and (if permitted) a
link to the **Traceability report**.

---

## Checklist (tick as each lands)

- [ ] **A1** `corrective-actions/index.tsx` migrated to `hs-hero-kit` (`HeroShell`/`HeroCluster`/`HeroSegmented`/`EntityFilter`), `TabStrip`, Incident page shell.
- [ ] **A2** `events/index.tsx` migrated identically (closes Events **H1/H2**).
- [ ] **A3** Both raw `oklch()` usages removed (hero gradient + page bg); `git grep "oklch(" ` clean in both files.
- [ ] **A4** Row helpers relocated to `register-row-kit.tsx`; `governance-register-kit` hero no longer imported by either page.
- [ ] **B1** Corrective-actions hero CTA → Traceability report (gated).
- [ ] **C1** Right-click rebuilt with status-gated full lifecycle (Start/Complete/Verify/Return/Close).
- [ ] **C2** Per-row kebab opens the same menu (keyboard-reachable).
- [ ] **D1** `ActionRow` payload + TS type carry `completed_by_*` and `can_verify`.
- [ ] **E1** `EventDetailDialog` accepts `initialActionTarget`; register deep-links Complete/Verify/Return to the right pane.
- [ ] **E2** Modal scrolls/highlights the targeted action; panes pass `POPUP_STYLE_GUIDE.md`.
- [ ] **F1** Actionable empty state.

## Acceptance / verification (run before declaring done)

1. `npm run types` · `npm run lint` · `npm run build` clean for touched files; `npm run test` for touched specs.
2. `git grep -n "oklch(" resources/js/pages/health-safety/corrective-actions resources/js/pages/health-safety/events` → **no matches**.
3. `git grep -n "governance-register-kit" resources/js/pages` → only `register-row-kit` (or none); **no hero imports**.
4. Chrome on `.com`, side-by-side `/incidents` vs `/health-safety/corrective-actions` vs `/health-safety/events`:
   eyebrow pill, medallion, **primary gradient**, clusters, `TabStrip`, footer band and page rhythm read as **one product**.
5. Right-click each lifecycle state: Start (open), Complete (in_progress), Verify + Return (completed),
   Close (verified) all work; Complete/Verify/Return open the modal **on the correct action's pane**;
   Verify is blocked for the completer (verifier ≠ completer).
6. 0 console errors on both pages.

## Suggested pass order

1. Read the reference files + this prompt + the audit. Re-audit live code.
2. **A** (migrate corrective-actions; relocate helpers) → verify build → **A** for events.
3. **D** payload → **E** modal deep-link → **C** right-click + kebab.
4. **B** + **F** polish. Full §Acceptance sweep. Update the checklist + a one-line change-log entry.
