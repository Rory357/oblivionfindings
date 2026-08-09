# H&S Corrective Actions — Consistency, Workflow & Modal Audit

> Companion to `HEALTH_SAFETY_CORRECTIVE_ACTIONS_FIX_PROMPT.md` (the Claude Code build prompt).
> Scope: the **Corrective actions** register + its **sibling Events register** (they share a kit and a modal).
> NZ-only (HSWA 2015 s23/s24 notifiable; Ngā Paerewa NZS 8134:2021; ACC). Web-only (no phone frames).
> Audited against live code on 2026-06-19.

---

## 0. TL;DR — the real problem

The module already has a **documented, merged gold-standard hero kit**: `hs-hero-kit.tsx`
(dashboard + analytics unified onto it, merged & live-verified 2026-06-17 — see
`HEALTH_SAFETY_HERO_CONSISTENCY_GAP_ANALYSIS.md`). The **Incidents**, **Safeguarding** and
**Fleet incidents** registers also use it.

The **Corrective actions** and **Events** registers are the **only two pages** that *don't*.
They were built on a **parallel look-alike kit** — `governance-register-kit.tsx` (`DesignHeroSection`)
— with a **hardcoded indigo `oklch()` gradient**. So the inconsistency you're seeing is real and
structural, not cosmetic: there are two near-duplicate hero systems, and the corrective-actions page
is on the wrong one.

This is also a **half-finished migration**: the Events redesign tracker
(`HEALTH_SAFETY_EVENTS_GAP_ANALYSIS.md`) **decided** on *"the H&S gold standard: `hs-hero-kit`,
`TabStrip`, `ShiftContextMenu`"* (decision log, 18 Jun 2026) and still has these boxes **unticked**:

- **H1** — *"Hero/tab/filter/right-click/modal idioms match the app verbatim … one product with dashboard/analytics."*
- **H2** — *"Semantic tokens only (no raw `oklch()`)…"*

This audit + prompt **complete H1/H2** for both governance registers and add the full
corrective-action lifecycle to the right-click menu.

---

## 1. Files in scope

| Role | Path |
|---|---|
| Target page | `resources/js/pages/health-safety/corrective-actions/index.tsx` (538 ln) |
| Sibling page (same fixes) | `resources/js/pages/health-safety/events/index.tsx` |
| Reference / gold standard | `resources/js/pages/incidents/index.tsx` |
| Gold-standard kit | `resources/js/pages/health-safety/components/hs-hero-kit.tsx` |
| Kit to retire (hero parts) | `resources/js/pages/health-safety/components/governance-register-kit.tsx` |
| Shared tab strip | `resources/js/components/rostering/tab-strip.tsx` (`TabStrip`, `RosterTabItem`) |
| Shared filter | `resources/js/components/rostering/entity-filter.tsx` (`EntityFilter`) |
| Shared right-click | `resources/js/components/rostering/shift-context-menu.tsx` (`ShiftContextMenu`) |
| Shared modal | `resources/js/components/health-safety/event-detail-dialog.tsx` (`EventDetailDialog`) |
| Backend | `app/Http/Controllers/HealthSafety/HsCorrectiveActionController.php`, `app/Services/HealthSafety/HsCorrectiveActionService.php`, routes in `routes/health-safety.php` |

---

## 2. Consistency gaps — Corrective actions / Events vs the Incident gold standard

| # | Element | Incident page (gold standard) | Corrective actions / Events today | Verdict |
|---|---|---|---|---|
| C-1 | Hero component | `HeroShell` + `Hero*` family (`hs-hero-kit`) | `DesignHeroSection` + `Design*` family (`governance-register-kit`) | **Two parallel kits** — converge |
| C-2 | Hero gradient | `from-primary/90 via-primary to-primary/80` (semantic `--primary`) | Hardcoded `linear-gradient(135deg, oklch(51.1% 0.262 277/.94), …)` | **Raw `oklch()`** — violates H2 / `DESIGN_TOKENS.md` |
| C-3 | Tab strip | `TabStrip` (`RosterTabItem`, ArrowKey roving focus) | `DesignTabStrip` (`DesignTabItem`) | Duplicate component |
| C-4 | Footer filters | `HeroSegmented` pills + `EntityFilter` (searchable, `onDark`) | `HeroRangePill` + plain `HeroSelect` dropdowns | Site filter not searchable; different controls |
| C-5 | Hero CTA / primary action | "Report" popover launcher, top-right | **None** | Missing affordance |
| C-6 | Page shell | `flex flex-col gap-6 p-6` | `min-h-screen bg-[oklch(0.98_0.006_277)] px-4 py-5 … gap-4` | **Another raw `oklch()`** + different rhythm |
| C-7 | Search input | inline `rounded-lg` on-dark field | `HeroSearch` `rounded-full` pill | Cosmetic drift |
| C-8 | Right-click menu | `ShiftContextMenu` ✅ (same primitive) | `ShiftContextMenu` ✅ | **Mechanism already consistent** — only the *contents* differ (see §4) |

**Net:** the right-click *primitive*, the row flag badges and the table-header strip are already
shared idioms. Everything in the **hero band, tab strip, filters and page shell** is on the wrong kit.

---

## 3. Workflow gaps

The backend exposes a **complete, gated lifecycle** (verified in `HsCorrectiveActionController`):

```
open ──start──▶ in_progress ──complete──▶ completed ──verify──▶ verified ──close──▶ closed
                                   ▲              │
                                   └──return──────┘  (return for rework)
```

- `start` — inputless (optional re-assign). Route: `POST …/{action}/start`
- `complete` — requires `completion_notes`. Route: `…/{action}/complete`
- `verify` — requires `effectiveness_confirmed`; **verifier ≠ completer** enforced server-side. Route: `…/{action}/verify`
- `return` — requires `reason`. Route: `…/{action}/return`
- `close` — inputless; auto-advances the parent event to `monitoring` when all actions resolved. Route: `…/{action}/close`
- (all nested under `/health-safety/events/{event}/corrective-actions/…`, gated `hazards.manage`)

**Gap W-1 — the register can't drive any of this.** Today the row right-click only *navigates*
(open actions pane / view parent / add action / open full page). To start, complete, verify, return
or close, the user must open the modal, find the **Corrective actions** section, find the row again,
then click. The lifecycle UI exists only inside `EventDetailDialog`'s `CorrectiveActionControls`.

**Gap W-2 — no "completed by" on the register.** `ActionRow` carries `completed_at` but **not**
`completed_by_*`. The separation-of-duties rule (verifier ≠ completer) is therefore invisible until
you open the modal, and the register can't grey-out "Verify" for the person who completed it.

**Gap W-3 — empty state is dead.** The "No corrective actions here" state offers no next step.

**Gap W-4 — no governance CTA.** There is a real **Corrective-action traceability** governance report
(`routes/health-safety.php` → `corrective-action-traceability`) with no entry point from this register.

> **Data-model note (don't get this wrong):** a corrective action is **always event-scoped** — even the
> "standalone" `store` requires an `HsEvent`. There is **no orphan corrective action**. So a naive
> "New action" hero CTA (like Incidents' "Report") would mislead. Creation stays event-scoped via the
> right-click **"Add corrective action."** The hero CTA slot should instead hold the **Traceability
> report** (recommended) or an **Export** — a meaningful top-right action with parity to the Incident hero.

---

## 4. Right-click menu — current vs target

**Current (corrective actions)** — navigation only:
`Open corrective actions` · `View parent event` · `Add corrective action` (if `can.manage` & event open) · `Open event full page`.

**Target — full lifecycle inline**, status-gated and permission-gated. `ShiftContextMenu` supports
item tones `primary | critical` only; use `primary` for the forward step, `critical` for Return/override.

| Row status | Lifecycle item(s) to add | How it fires |
|---|---|---|
| `open` | **Start action** (`primary`) | direct `router.post(…/start)` — inputless |
| `in_progress` | **Mark complete…** (`primary`) | deep-link modal → `ca_complete` pane (needs notes) |
| `completed` | **Verify…** (`primary`) + **Return for rework…** (`critical`) | deep-link → `ca_verify` / `ca_return` panes |
| `verified` | **Close action** | direct `router.post(…/close)` — inputless |
| any | separator → `Open corrective actions` · `View parent event` · `Add corrective action` (gated) · `Open event full page` | navigation (existing) |

Guards: hide all write items unless `can.manage` **and** `event.status !== 'closed'`. For **Verify**,
prefer disabling/hiding when the current user is the completer (needs W-2 payload); otherwise rely on
the server gate (surfaces as `flash.error`) as a fallback.

Mirror the Incident page's depth: it already context-switches items by row state (`Continue draft`,
`Submit for review`, `View client`, `View Control Room alert`).

---

## 5. Modal audit — `EventDetailDialog` (shared by both registers)

This modal is the intentional **detail-as-modal governance workspace** (Events redesign D1), not a
simple `_dialogs.tsx` popup — that's correct. It is sound: rail sections
(`overview · investigation · actions · risk · timeline · evidence`), gated Options bar, inline
per-action `CorrectiveActionControls`, and explicit **separation-of-duties** messaging on the verify pane.

Gaps:

- **M-1 — deep-link API stops at event level.** Public props are `initialSection: EventSectionKey`
  and `initialAction: EventActionKey` where
  `EventActionKey = 'close' | 'worksafe_notify' | 'worksafe_acknowledge' | 'investigation' | 'add_action'`.
  `paneFromAction()` maps **only** these. There is **no way to deep-link to a specific corrective
  action's** `ca_complete` / `ca_verify` / `ca_return` pane from the register. This is the one change
  that unlocks §4's "Mark complete / Verify / Return."
- **M-2 — no scroll/highlight to the clicked action.** Opening from a corrective-actions row passes
  only the *event* id, so it lands on the actions list with the relevant row un-highlighted; the user
  re-finds it. Should scroll-into-view + highlight the target action.
- **M-3 — confirm pane hygiene vs `POPUP_STYLE_GUIDE.md`.** Verify the complete/verify/return panes
  show `Loader2` while `form.processing`, use sentence-case verbs ("Mark complete", "Verify action"),
  render errors inline (never `toast`), and post with `preserveScroll`/`preserveState`. (Spot-check;
  they appear to already follow this.)

Recommended additive change (no breaking changes):

```ts
// EventDetailDialog props — ADD (keep existing initialSection/initialAction working):
initialActionTarget?: { actionId: number; pane: 'complete' | 'verify' | 'return' };
// In the pane useState initializer, if initialActionTarget is set, force section='actions'
// and map { complete|verify|return } → { kind: 'ca_complete'|'ca_verify'|'ca_return', actionId }.
```

The register already mirrors the `pendingSection`/`pendingAction` pattern — add a parallel
`pendingActionTarget` and pass it through. Keep `/health-safety/events/{id}` as the deep-link fallback.

---

## 6. Payload gaps (backend → Inertia)

| Gap | Where | Add |
|---|---|---|
| W-2 verifier guard | `ActionRow` (corrective-actions index transform in `HsEventController@correctiveActions`) | `completed_by_user_id`, `completed_by_name`, and the viewer's id (or a `can_verify` boolean computed server-side) |
| M-2 highlight | already have `action.id` on the row | pass it through as the deep-link target |

No new schema/migration is expected — `HsCorrectiveAction` already records the completer
(`completed_by_user_id`); this is a transform/serialisation change only.

---

## 7. Accessibility & polish

- **A-1** Right-click is the only path to row actions. Add a visible **kebab (`⋮`) button** per row
  that opens the *same* `ShiftContextMenu` payload — discoverable + keyboard-reachable (right-click has
  no keyboard equivalent).
- **A-2** Keep the existing row `tabIndex`/Enter-to-open and `focus-visible` ring (already present — good).
- **A-3** `TabStrip` brings ArrowLeft/Right/Home/End roving focus for free — another reason to adopt it
  over `DesignTabStrip`.
- **A-4** No colour-only meaning: lifecycle items keep their icon + label (not tone alone).

---

## 8. Prioritised recommendations

**P0 — consistency (completes Events H1/H2)**
1. Migrate `corrective-actions/index.tsx` **and** `events/index.tsx` from `governance-register-kit`
   → `hs-hero-kit` (`HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroCluster`/`HeroClusterTile`,
   `HeroSegmented`), `TabStrip`, and `EntityFilter` (searchable Site).
2. Kill both raw `oklch()` usages (C-2 gradient, C-6 page background); adopt the Incident page shell
   (`flex flex-col gap-6 p-6`).
3. Preserve the non-hero helpers the tables need (`FlagBadge`, `RegisterTableHeader`, `titleCase`,
   `fmt`, `initials`, `entityTone`, `TONE_BG`, `TONE_DOT`) — move them to a neutral shared module
   (e.g. `register-row-kit.tsx`) rather than deleting with the hero.

**P0 — workflow (the headline ask)**
4. Add the **full lifecycle to the right-click** (§4) + per-row kebab (A-1).
5. Extend `EventDetailDialog` with `initialActionTarget` (M-1) and scroll/highlight (M-2).
6. Add `completed_by_*` / `can_verify` to the `ActionRow` payload (W-2).

**P1 — parity polish**
7. Hero CTA → **Traceability report** (W-4); make the empty state actionable (W-3).
8. Confirm modal panes meet `POPUP_STYLE_GUIDE.md` (M-3).

**P2 — later (out of this pass)**
9. Retire `governance-register-kit.tsx` entirely once both pages are migrated; grep for stragglers.
10. Consider the same lifecycle-on-right-click treatment for the Investigation rows for symmetry.

---

## 9. Verification gate (per loop convention)

`npm run types` · `npm run lint` · `npm run build` clean for touched files; `npm run test` for touched
specs. Then, side-by-side in Chrome on `.com`: `/incidents` vs `/health-safety/corrective-actions` vs
`/health-safety/events` must read as **one product** — identical eyebrow pill, medallion, gradient,
clusters, tab strip, footer band, page rhythm. Right-click a row in each lifecycle state and confirm
Start / Complete / Verify / Return / Close behave and gate correctly (including verifier ≠ completer).
Grep proves zero raw `oklch(` in both migrated pages and zero remaining `governance-register-kit` hero imports.
