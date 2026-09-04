# Design decision — Two-tier section navigation (approved 2026-09-04)

Applies app-wide: **Rule 1** to every tier-1 group/tab rail inside a page hero
(e.g. client profile: Snapshot / Daily care / …), **Rule 2** to every tier-2
sub-tab strip under an open header section (e.g. Overview / Personal Details / …).
Reference implementations: `GroupPillRail` and `TierTwoTabs`
(`resources/js/components/page/grouped-profile-nav.tsx`) and the rostering
`TabStrip` (`resources/js/components/rostering/tab-strip.tsx`), restyled per
below.

## Rule 1 — "Connected tab" hero rail (tier 1)

The hero itself is unchanged (gradient `from-primary/90 via-primary to-primary/80`,
decorative circles, trailing "Find /" control). Only the tabs change.

### Inactive tabs

- Pill: `rounded-full`, min-height 42px, padding 0 15px, 14px / weight 500,
  icon 15px, gap 7px
- Text `--primary-foreground` at 72% opacity; hover: `primary-foreground/10`
  background + full-opacity text
- Rail is bottom-aligned; inactive tabs keep an 8px bottom margin
- Warning badges (amber count chips) unchanged

### Active tab — connects to the page

- Background: **page background token** (`--background`) — the tab visually
  merges with the content area below the hero
- Text: `--primary`, weight ~550, min-height 46px, padding 0 18px
- Radius: `12px 12px 0 0` (square bottom corners, flush with the hero's
  bottom edge — hero gets `padding-bottom: 0`, active tab margin-bottom 0)
- No shadow, no border; the merge IS the affordance
- Focus ring: 2px hero-colour + 2px white double ring (visible on violet)

## Rule 2 — Dynamic toned sub-tab strip (tier 2)

### Container

- A bare flex row (`flex-wrap`, 4px gap) directly on the page background.
- **Anti-pattern: never wrap the sub-nav strip in a card** (no border, no
  card background, no shadow, no container padding).

### Anatomy (per tab, from rostering TabStrip)

- min-height 40px, padding 0 12px, radius 9px, 13px / weight 600, gap 8px
- Leading icon chip: 22×22px, radius 6px, icon 14px
- Optional count badge: `rounded-full`, 10px bold, tabular-nums

### States

- **Inactive (neutral at rest — tones must NOT show):** text
  `--muted-foreground`; icon chip `bg-muted text-muted-foreground`;
  count badge `bg-muted text-muted-foreground`
- **Hover (still neutral):** `bg-accent`, text `--foreground`
- **Active (tone appears only here):** background = tone at ~12% mix over
  transparent; text = tone; icon chip = solid tone with white icon; count
  badge = tone at ~18% mix with tone text; 2px underline bar in tone,
  inset 14px from each side, at the tab's bottom edge
- Focus ring: 2px `--ring` at ~55%

### Tone assignment — automatic, never hand-picked

Tones are assigned **by position index, cycling**:
`[violet, teal, green, amber, rose]`, wrapping for strips longer than five
(index % 5). Works for any number of sub-tabs in any section; position 0 is
always brand violet, giving every section a consistent landing colour.

Tone → token mapping (light and dark handled by the tokens themselves):

1. violet → `--primary`
2. teal → derived teal, hue 200 (light `oklch(45% 0.10 200)`, dark
   `oklch(72% 0.12 200)`) — **needs a named token**, suggest `--tone-teal`
3. green → `--status-success`
4. amber → `--status-warning`
5. rose → `--status-critical`

Solid chip fills use the deep (light-theme) tone values in both themes so the
white icon always passes contrast; tinted backgrounds/text use the theme-aware
tone tokens. No raw hex anywhere.

## Interaction between rules

The two tiers must contrast, not compete: tier 1 is the loud "you are here"
(page-coloured connected tab on violet); tier 2 stays quiet until active,
with colour used only as the active accent.

---

## Implementation & conformance

**Enforcement points (three components own this app-wide):**

1. `GroupPillRail` in
   [`resources/js/components/page/grouped-profile-nav.tsx`](../resources/js/components/page/grouped-profile-nav.tsx)
   → Rule 1. Replaces the current white active pill
   (`bg-primary-foreground text-primary shadow-sm`) with the connected tab.
   The hosting hero needs `padding-bottom: 0` on its footer region so the
   active tab sits flush.
2. `TierTwoTabs` in the same file → Rule 2. Replaces the current
   underline-only treatment (`border-b-2` on a bordered, backdrop-blurred
   container) with the bare toned strip — the sticky container's border and
   background go away.
3. Rostering `TabStrip`
   ([`resources/js/components/rostering/tab-strip.tsx`](../resources/js/components/rostering/tab-strip.tsx))
   → Rule 2 (it is the anatomy donor; align its tones to the positional
   cycle).

**Token prerequisite:** add `--tone-teal` to `resources/css/app.css`
(`:root` light `oklch(45% 0.10 200)`, `.dark` `oklch(72% 0.12 200)`) and
register `--color-tone-teal` in the `@theme` block. The other four tones
reuse existing tokens (`--primary`, `--status-success`, `--status-warning`,
`--status-critical`). Note `--live` is a *different* teal pair reserved for
in-progress states — don't reuse it here.

**Counter rule interplay:** the state-colour counter rule in `DESIGN.md`
still applies. Under Rule 1 the active connected tab is page-coloured, so
its count/warning pills must switch to the tone-on-light treatment (e.g.
warning pills `bg-status-warning-bg text-status-warning`), never keep the
white-text hero styling. Under Rule 2 the count badge follows the tab's
tone when active and `bg-muted text-muted-foreground` at rest — exactly as
specced above.

**What the conformance sweep looks for:**

1. Hero rails still using the white-pill active state instead of the
   connected tab (any `bg-primary-foreground` active tab in a hero footer).
2. Sub-tab strips wrapped in cards/bordered containers, or still
   underline-only.
3. Tones hand-picked per tab (semantic or aesthetic choices) instead of
   positional `index % 5` assignment.
4. Toned styling leaking into inactive states (inactive must be fully
   neutral).
5. Solid icon chips using theme-aware tone values in dark mode (must use
   the deep light-theme values so the white icon keeps contrast).
6. `--tone-teal` missing from `app.css` / `@theme` (ghost-token risk if the
   strip ships first).

## See also

- [`DESIGN.md`](../DESIGN.md) — the app-wide UI contract
- [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) — token reference (add
  `--tone-teal` there when implemented)
