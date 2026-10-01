# Design Tokens

This app uses a **semantic token system** driven by CSS custom properties.
Every colour you use in a component should come from a token — not a raw
Tailwind colour class like `bg-violet-600`. When the Branding page changes
the `--primary` hex, the entire UI retints automatically.

## Quick reference

| Use case | Token utilities |
|---|---|
| Primary brand (active nav, accents, focus ring) | `bg-primary`, `text-primary`, `border-primary`, `ring-ring` |
| Solid brand fill that carries text (default button, badge, tooltip) | `bg-primary-fill`, `text-primary-fill-foreground` |
| Brand-coloured text on cards, page ground, tints | `text-primary` (reads `--primary-text`, mode-aware) |
| Brand-coloured text on an always-white surface (hero white button / pill) | `text-primary-strong` |
| Tint / subtle primary background | `bg-primary/10`, `bg-accent` |
| Secondary surfaces (cards, panels) | `bg-card`, `bg-popover`, `bg-background` |
| Subtle backgrounds (muted section fills) | `bg-muted`, `bg-muted/50` |
| Body text | `text-foreground` |
| Secondary text | `text-muted-foreground` |
| Destructive / danger actions | `bg-destructive`, `text-destructive-foreground` |
| Borders / inputs | `border-border`, `border-input` |

## Primary fills and foregrounds (added 2026-10-02)

`--primary` does three jobs: the brand fill, brand-coloured text, and the
base of the Event Horizon sky. One value can't keep text readable in all of
them, so `app.css` derives four tokens from it. They are **derived only** —
not a branding setting — and follow whatever `--primary` resolves to on
`<html>`: the default, an org theme, or a personal accent's inline style.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--primary-fill` | the brand; a brand that carries white text is never lighter than L 0.50 | the brand clamped to L ≤ 0.50 | solid fills that carry text: `.btn-soft-primary`, default badge, tooltip, checked checkbox, dropzone drag state, text selection |
| `--primary-fill-foreground` | white, or ink when the fill is L ≥ 0.585 | white | text on `--primary-fill` |
| `--primary-strong` | the brand clamped to L ≤ 0.50 | same | brand text on a surface that is white in both modes (`PageHeaderPrimaryButton`, the active white pills on hero rails and filters) |
| `--primary-text` (added 2026-10-02) | the brand clamped to L ≤ 0.46 | the brand lifted to L ≥ 0.70 | `text-primary` everywhere — registered as `--text-color-primary`, so Tailwind's `text-primary` (and `/opacity`, variants) reads it while `bg-`, `border-`, `fill-`, `ring-`, `decoration-primary` keep the fill colour |

`text-primary` measured ≥ 4.8:1 on the card, page ground, muted and
secondary surfaces for every brand above (including a personal accent's
inline `--primary`, which overrides `.dark`, and an org theme), in both
modes; brand icon tiles (`bg-primary/10` + `text-primary`) ≥ 5.6:1. The
light clamp is 0.46, not 0.50, because green and teal brands fell to 4.45:1
on the grey page ground at 0.48 once the browser gamut-maps them. Text on a
surface that stays white in dark mode (`bg-primary-foreground`, `bg-white`)
uses `text-primary-strong` — the lifted `text-primary` would be ~2.7:1 there.

Measured (WCAG 2.1) across the default brand, the five brand presets, the
Branding theme presets and a personal accent, light and dark: default-button
text ≥ 5.2:1 on the base and ≥ 4.6:1 on the top highlight; hero
white-button text ≥ 5.4:1. White text on a clamped fill holds for every hue
(worst case green: 5.6:1 base, 4.57:1 highlight). Ink holds for every hue
from L 0.62; a saturated magenta or violet brand between L 0.585 and 0.62
can fall to about 3.9:1 (no current preset is in that band).

**The foreground rule.** Text on a solid brand fill is white or ink,
whichever has the higher WCAG contrast — never a fixed luminance cut-off (the
old `> 0.5` gave white text at 3.6:1 on orange, teal and green brands).
`pickForeground()` in `lib/derive-palette.ts` applies it in JS (the sidebar
logo tile); `--primary-fill-foreground` applies the same crossover in CSS.
`--primary-foreground` is **not** a fill foreground: it is also the text on
the brand sky, whose upper shades are fixed dark (L 0.30–0.42), so it stays
white unless the brand itself is very light. New text-bearing fills use the
fill pair, never `bg-primary text-primary-foreground`.

## Status tokens — for badges and severity states

| Severity | Foreground | Background | Use for |
|---|---|---|---|
| success | `text-status-success` | `bg-status-success-bg` | approved, active, completed, verified, resolved |
| warning | `text-status-warning` | `bg-status-warning-bg` | pending, under review, medium severity, corrective action |
| critical | `text-status-critical` | `bg-status-critical-bg` | overdue, rejected, high severity, extreme risk |
| info | `text-status-info` | `bg-status-info-bg` | open, in progress, informational (info == primary tint) |
| neutral | `text-muted-foreground` | `bg-muted` | draft, cancelled, archived, superseded |

Prefer the `<StatusBadge>` component over hand-rolled spans:

```tsx
import { StatusBadge } from '@/components/ui/status-badge';

<StatusBadge variant="success">Approved</StatusBadge>
<StatusBadge status={record.status} />              // lookup in status-colors.ts
<StatusBadge variant="critical" size="sm">P1</StatusBadge>
```

For dynamic status-based class composition (e.g. on a table row), use
`getStatusColor()` from `@/lib/status-colors` — it returns a single
string of semantic-token utilities, already re-brandable.

## Tone tokens — tier-2 sub-tab cycle

The toned sub-tab strip (`NAVIGATION_STYLE_GUIDE.md` Rule 2) cycles
positionally through violet → teal → green → amber → rose (`index % 5`).
Four positions reuse `--primary` / status tokens; teal has its own token:

| Token | Light | Dark | Use for |
|---|---|---|---|
| `--tone-teal` | `oklch(45% 0.1 200)` | `oklch(72% 0.12 200)` | position-1 tint/text in the tier-2 cycle (NOT `--live`, which marks in-progress states) |
| `--tone-chip-teal/-success/-warning/-critical` | deep tone | same (no dark flip) | solid icon-chip fills only — deep in both themes so the white glyph keeps AA contrast |

Never hand-pick tones per tab; the cycle owns the assignment
(`TONE_CYCLE` in `components/page/grouped-profile-nav.tsx`).

## Category tokens — module-level tinting

Categories (ops, HR, compliance, incidents, governance, sites, fleet) are
generated from the current `--primary` hue via `oklch(from …)`, so each
module has its own distinctive tint that still harmonises with the brand.

| Category | Foreground | Background |
|---|---|---|
| Operations | `text-category-ops` | `bg-category-ops-bg` |
| HR / People | `text-category-hr` | `bg-category-hr-bg` |
| Compliance | `text-category-compliance` | `bg-category-compliance-bg` |
| Incidents & Safety | `text-category-incidents` | `bg-category-incidents-bg` |
| Governance | `text-category-governance` | `bg-category-governance-bg` |
| Sites | `text-category-sites` | `bg-category-sites-bg` |
| Fleet & Assets | `text-category-fleet` | `bg-category-fleet-bg` |

## Typography utilities

Prefer these instead of composing `text-2xl font-semibold` inline.

| Class | Style |
|---|---|
| `.text-page-title` | Page H1 — `text-2xl font-semibold tracking-tight` |
| `.text-section-title` | Section H2 — `text-lg font-semibold` |
| `.text-subtle` | Secondary text — `text-sm text-muted-foreground` |
| `.text-caption` | Small meta text — `text-xs text-muted-foreground` |

## Charts

Chart libraries (Recharts in this app) read from `--chart-1` through
`--chart-5`. The Branding palette derives these from the brand colour via
oklch hue rotations, so charts retint automatically.

```tsx
<Bar dataKey="value" fill="var(--chart-1)" />
```

Only use raw hex in charts as a last resort. If you need a 6th+ colour,
extend `derivePalette.ts` rather than inlining hex.

## Components

Prefer the shadcn primitives over raw HTML + classNames wherever you
can. They ship with consistent focus rings, disabled states, and
`asChild` support.

| Don't | Do |
|---|---|
| `<button className="rounded-md bg-primary …">` | `<Button>` |
| `<button className="border rounded-md …">` | `<Button variant="outline">` |
| `<button className="hover:bg-accent …">` | `<Button variant="ghost">` |
| `<button className="text-primary underline">` | `<Button variant="link">` |
| Icon-only trash/close/menu buttons | `<Button variant="ghost" size="icon">` |
| `<div className="rounded-lg border bg-card …">` | `<Card>` |

**Raw `<button>` is acceptable** when the element is a custom-layout
selector (theme picker, colour swatch, option card with preview
artwork inside) where `<Button>`'s default padding/height/gap would
break the design. In that case, still use semantic tokens (primary,
accent, muted-foreground) for the styling.

## Rules of thumb

1. **No raw Tailwind colour classes in components.** `bg-violet-600`,
   `text-emerald-500`, `border-blue-300` etc. are blocked by the
   ESLint guardrail. Replace with a semantic token.
2. **Exception: charts and one-off visualisations** may use `var(--chart-N)`
   but never `#hex` inline.
3. **Status → StatusBadge / status-colors.ts, not ad-hoc classes.** If
   you need a new status, add it there once — it's used across 50+ pages.
4. **Categories tint to brand.** Don't pin `bg-purple-500` to HR — use
   `bg-category-hr` so rebrand cascades.
5. **Dark mode is automatic.** Semantic tokens have light + dark
   variants; you don't need `dark:bg-*` pairs if you use the token.
6. **Text on a solid brand fill uses the fill pair.** `bg-primary-fill` +
   `text-primary-fill-foreground` stay ≥ 4.5:1 for any brand in both modes;
   `bg-primary text-primary-foreground` does not (see "Primary fills and
   foregrounds").

## Calendar source tokens

Every shared `SiteCalendar` source has a fixed triple in `app.css` —
`--src-<key>` (mark), `--src-<key>-bg` (fill) and `--src-<key>-ln` (line).
Hues are fixed (not brand-derived) and deliberately spaced so adjacent
sources stay distinguishable. The module sets:

| Module | Sources |
|---|---|
| Sites / operations | `event`, `inspection`, `compliance`, `credential`, `checklist`, `hazard`, `vendor`, `asset`, `meal`, `damage`, `emergency`, `drill`, `respite`, `participation`, `ppe`, `medication` |
| Governance | `meetings`, `decisions`, `obligations`, `policies` |
| Finance | `invoice-due`, `bill-due`, `payment-run`, `gst-due`, `payroll`, `period-close` |
| Shared | `external` (two-way sync busy — desaturated on purpose) |

A source colour never signals urgency: an entry whose `status` is
`overdue` renders critical regardless of its source.

## Adding a new token

Edit [`resources/css/app.css`](../resources/css/app.css):

1. Add the variable under `:root` (light) and `.dark` (dark) blocks.
2. Register it in the Tailwind `@theme` block as `--color-<name>: var(--<name>);`
   so utilities like `bg-<name>` become available.
3. Document it here.
4. If it should respond to brand colour, express it via
   `oklch(from var(--primary) …)` rather than a fixed value.

## Accessibility: contrast floor & reduced motion

- **Safety colours are brand-independent.** Allergen/conflict banners and safety
  pills must use the fixed `status-critical` / `status-warning` token pairs (solid
  background + contrast-checked white or `status-*-foreground`), **never**
  `primary-foreground` opacity over the brand gradient — contrast there tracks
  whatever hue an admin picks and can fall below WCAG 1.4.3 (4.5:1). The meal
  planner hero conflict banner is pinned to solid `bg-status-critical text-white`
  for this reason.
- **Colour is never the only signal.** Pair every colour-coded state with a text
  label or icon (MealCard "Allergen"/"Texture"/"Diet check" pills, the composed
  `aria-label`, the left status bar) so it survives WCAG 1.4.1.
- **Small pills.** Keep safety-pill text ≥11px or give sub-11px pills a bordered /
  iconned non-colour cue.
- **Focus is always visible.** Interactive chrome (hero CTAs, sub-tabs, MealCards,
  Add buttons, menu items) carries `focus-visible:ring-2 focus-visible:ring-ring`.
- **44 px targets.** The root font is 14 px, so rem sizes such as `min-h-11`
  render at 38.5 px. `.frontline-tap` makes a control at least 44×44 px.
  `.frontline-hit` gives a compact control (row kebab, pagination, tier-2 tab,
  dialog, wizard and sheet ✕) an invisible 44×44 px target and leaves its
  drawn size alone. Keep 44 px between the centres of neighbouring
  `.frontline-hit` targets, and keep each target on screen: the `Sheet` ✕
  has a 16 px minimum inset because a sheet touches the screen edge.
- **Reduced motion.** A global `@media (prefers-reduced-motion: reduce)` block in
  `app.css` disables `animate-ping` / `animate-spin` / `animate-pop` /
  `animate-pulse`. New animated chrome should rely on that block (or a
  `motion-reduce:` utility) rather than animating unconditionally (WCAG 2.3.3).

## See also

- [`resources/css/app.css`](../resources/css/app.css) — token definitions
- [`resources/js/lib/derive-palette.ts`](../resources/js/lib/derive-palette.ts) — single-colour → full palette
- [`resources/js/lib/status-colors.ts`](../resources/js/lib/status-colors.ts) — status → classes map
- [`resources/js/components/ui/status-badge.tsx`](../resources/js/components/ui/status-badge.tsx) — the preferred badge component
