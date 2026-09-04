# Design decision — "Soft depth" action buttons (approved 2026-09-04)

Applies to the primary/secondary action-button pair (first seen on Site →
Clients: "Create client" + "Link existing client"). Intended to become the
app-wide `default` and `outline` button treatments.

## Shared anatomy (both buttons)

- Height: 44px (`min-h-11`), padding: 0 18px, gap between icon and label: 8px
- Radius: 10px (`--radius-md`, i.e. `calc(var(--radius) - 2px)`)
- Font: Instrument Sans, 14px, weight 550 (variable axis; fall back to 500)
- Icon: 16px (`size-4`), stroke 2
- Motion: `transition: all .16s ease`; press state `active:translate-y-[0.5px]`
- Focus: no outline; 3px ring `color-mix(in oklch, var(--ring) 45%, transparent)`

## Primary — "Create client"

- Text: `--primary-foreground` (white)
- Background (gradient-lit, top-lightened):
  `linear-gradient(180deg, color-mix(in oklch, var(--primary) 82%, white) 0%, var(--primary) 55%)`
- Shadow (inner highlight + violet glow):
  - `inset 0 1px 0 rgb(255 255 255 / 0.25)`
  - `0 1px 2px color-mix(in oklch, var(--color-purple-700) 50%, transparent)`
  - `0 4px 14px -4px color-mix(in oklch, var(--primary) 60%, transparent)`
- Hover: `translateY(-1px)`; glow expands to
  `0 2px 4px purple-700@50%` + `0 8px 20px -4px primary@65%` (inset highlight kept)

## Secondary — "Link existing client"

- Background `--card`, text `--foreground`, border 1px `--border`
- Rest shadow: `0 1px 2px oklch(0.15 0.015 277 / 0.05), inset 0 -1px 0 oklch(0.15 0.015 277 / 0.04)`
- Hover: `translateY(-1px)`; border tints to
  `color-mix(in oklch, var(--color-purple-400) 55%, var(--border))`;
  shadow `0 3px 8px oklch(0.15 0.015 277 / 0.08)`

## Theming

All colours come from semantic tokens (`--primary`, `--card`, `--border`,
`--ring`, hue-277 purple scale), so dark mode derives automatically — no
hard-coded hex anywhere.

---

## Implementation & conformance

**Single enforcement point.** The treatment lands in
[`resources/js/components/ui/button.tsx`](../resources/js/components/ui/button.tsx)
`buttonVariants`:

- `default` variant → the **Primary** spec above (replacing
  `bg-primary … hover:bg-primary/90 shadow-xs`).
- `outline` variant → the **Secondary** spec above (replacing
  `border border-input bg-background … shadow-xs`).

The multi-layer shadows and `color-mix` gradients exceed what inline Tailwind
utilities express cleanly — define them as utility classes in
`resources/css/app.css` (e.g. `.btn-soft-primary`, `.btn-soft-secondary`,
layered on semantic tokens only) and reference those from the cva variants.
Once the variants are updated, every `<Button>` / `<Button variant="outline">`
in the app conforms with no per-page work.

**Sizing nuance.** The 44px `min-h-11` height describes the page-level action
pair (effectively the `lg`-ish size). The *visual treatment* (gradient,
shadows, motion, focus ring, radius, weight) applies across all `size`
variants; the height keeps following the `size` prop so `sm`/`xs`/`icon`
buttons stay compact.

**What the conformance sweep looks for:**

1. `buttonVariants` `default`/`outline` not yet on the soft-depth spec.
2. Hand-rolled primary/secondary buttons — raw `<button>`/`<a>` styled with
   `bg-primary` or `border bg-card` outside `<Button>` (the DESIGN_TOKENS.md
   Don't/Do table already bans most of these).
3. `<Button className="…">` overrides that fight the treatment (custom
   backgrounds, shadows, or transforms on default/outline buttons).
4. `unstyled` Button usages that reimplement a standard primary/secondary
   look instead of using the variant.

## See also

- [`DESIGN.md`](../DESIGN.md) — the app-wide UI contract
- [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) — token reference and the
  button Don't/Do table
