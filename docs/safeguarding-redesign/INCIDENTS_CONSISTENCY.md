# Safeguarding ↔ Incidents consistency log (§7 hard gate)

> Safeguarding is the **near-twin** of the shipped Incidents redesign and must read as one product.
> On every UI step, open the Incidents equivalent side-by-side, match it 1:1, adopt shared primitives
> rather than forking. Inconsistencies found **in Incidents itself** are logged here with file+line —
> **do not refactor Incidents** beyond genuinely-shared additive primitives (list those separately).

## Incidents reference surfaces (post-redesign, on `feat/safeguarding-redesign` base @ e5d65f54)
- List: `resources/js/pages/incidents/index.tsx` (+ `.design-drops/incidents-redesign/Incidents.dc.html`)
- Detail modal: `resources/js/components/incidents/*` (to confirm in Step 4)
- Hero kit: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`
- Tabs/filters: `@/components/rostering` (`TabStrip`, `RosterTabItem`, `EntityFilter`, `MultiEntityFilter`)
- Right-click rows: `ShiftContextMenu` (`@/components/rostering`); `openRowCtx` copied from `pages/emar/PrnRecords.tsx:193`
- Wizard chrome: `@/components/wizard/shell` + `@/components/wizard/primitives`; Add-Client contract `resources/js/components/clients/add-client-dialog.tsx`

## Per-step comparison
### Step 1 (schema & enum) — no UI surface
Backend-only; nothing to compare. Incidents-parity gate applies from Step 3 (list) onward.

### Step 2 (lifecycle guards + triage) — no UI surface
Backend-only. The service+controller+gate-reasons pattern mirrors how Incidents enforces its lifecycle;
UI parity verified in Steps 4–5. Nothing to log.

### Step 3 (list page) — matched 1:1, adopted as-is
`safeguarding/index.tsx` was authored directly from `incidents/index.tsx`, reusing the **same** primitives
(no forks):
- `HeroShell` + `HeroMedallion` + `HeroStatusPill` + `HeroCluster`/`HeroClusterTile` + `HeroSegmented`
  from `@/pages/health-safety/components/hs-hero-kit` — identical composition/spacing/footer band.
- `TabStrip`/`RosterTabItem` + `EntityFilter` (onDark) + `ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`
  from `@/components/rostering` — identical tab chip/badge API and the same `openRowCtx` right-click
  mechanism + row click → open.
- Same table anatomy (When/ref · subject avatar · severity pill · stage pill · assigned · flags),
  same empty-state + `LaravelPagination` pattern, same `Card`/`CardContent` wrapper.
Intentional safeguarding deltas (per spec, not divergences): `ShieldAlert` medallion vs `AlertTriangle`;
"need-to-know" eyebrow; **counts-only** clusters (no compliance badges); Subject column is redactable
(Restricted hatched row) vs Incidents' Client; 8 safeguarding stages; reviews/monitoring worklist
instead of follow-ups; external-referral banner.
**No inconsistencies found in Incidents.** No shared primitives modified (pure reuse).

## Inconsistencies found in Incidents (do NOT refactor — log only)
- _(none yet)_

## Shared primitives edited (additive, both modules use)
- _(none yet)_
