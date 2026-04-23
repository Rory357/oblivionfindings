# Design Tokens

This app uses a **semantic token system** driven by CSS custom properties.
Every colour you use in a component should come from a token — not a raw
Tailwind colour class like `bg-violet-600`. When the Branding page changes
the `--primary` hex, the entire UI retints automatically.

## Quick reference

| Use case | Token utilities |
|---|---|
| Primary brand (buttons, active nav, focus ring) | `bg-primary`, `text-primary`, `border-primary`, `ring-ring` |
| Tint / subtle primary background | `bg-primary/10`, `bg-accent` |
| Secondary surfaces (cards, panels) | `bg-card`, `bg-popover`, `bg-background` |
| Subtle backgrounds (muted section fills) | `bg-muted`, `bg-muted/50` |
| Body text | `text-foreground` |
| Secondary text | `text-muted-foreground` |
| Destructive / danger actions | `bg-destructive`, `text-destructive-foreground` |
| Borders / inputs | `border-border`, `border-input` |

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

## Adding a new token

Edit [`resources/css/app.css`](../resources/css/app.css):

1. Add the variable under `:root` (light) and `.dark` (dark) blocks.
2. Register it in the Tailwind `@theme` block as `--color-<name>: var(--<name>);`
   so utilities like `bg-<name>` become available.
3. Document it here.
4. If it should respond to brand colour, express it via
   `oklch(from var(--primary) …)` rather than a fixed value.

## See also

- [`resources/css/app.css`](../resources/css/app.css) — token definitions
- [`resources/js/lib/derive-palette.ts`](../resources/js/lib/derive-palette.ts) — single-colour → full palette
- [`resources/js/lib/status-colors.ts`](../resources/js/lib/status-colors.ts) — status → classes map
- [`resources/js/components/ui/status-badge.tsx`](../resources/js/components/ui/status-badge.tsx) — the preferred badge component
