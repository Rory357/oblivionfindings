# Design decision — Oblivion loader "Event Horizon" (approved 2026-09-04, concept 01)

Live demo: [`oblivion-loader-01-demo.html`](./oblivion-loader-01-demo.html)
(open in a browser tab — the motion doesn't play in static previews).

The brand loader for OblivionFindings. The capital **O** of "Oblivion" is a black
hole: a rotating accretion ring in the brand colour. The remaining letters
("blivion") are devoured one by one — **tail first** (n, then o, i, v, i, l, b) —
each letter dragged left across the word into the O, shrinking, rotating and
blurring as it crosses the event horizon. The word then re-materialises from the
right and the cycle repeats.

---

## Design rule

- **Concept:** the product name consumes itself — "fed to oblivion". The O is
  both the spinner and the wordmark's first letter; there is no separate
  spinner glyph.
- **Devour order is tail-first** so the word shortens right-to-left with no
  gaps; each letter travels the full distance into the O (dragged, not faded).
- **Colour:** ring uses `--primary` sweeping to a bright tip
  (`color-mix(in oklch, var(--primary) 45%, white)`); letters use
  `--foreground`. **No hardcoded hues** — the loader retints automatically when
  the admin changes the brand colour (Settings → Branding) and needs no `dark:`
  pairs.
- **Motion budget:** transform, opacity and filter:blur only — GPU-cheap, no
  layout thrash.
- **Reduced motion:** under the app's global reduce block (`html.reduce-motion`
  / `prefers-reduced-motion`) all animation stops and the loader renders as the
  static wordmark "Oblivion" with a static ring for the O.
- **Sizing:** everything is in `em`, so the loader scales with `font-size`
  (34px default for full-page loads; ~20px for inline/section loading).
- **Usage:** full-page boots, Inertia page transitions, and large section
  suspense states. Small inline spinners (buttons, table cells) keep the plain
  ring only (the `.eh-o` element on its own) — never the full animated word at
  small sizes.
- **Accessibility:** container carries `role="status"` and
  `aria-label="Loading"`; letters are `aria-hidden` (screen readers should hear
  "Loading", not a word being spelled).

## Motion spec

| Property | Value |
|---|---|
| Letter cycle | 3.4s, infinite |
| Stagger | `(7 − index) × 0.13s` — last letter ("n") first |
| Phase 1 (0–12%) | letter materialises: from `translateX(.4em)` + `blur(6px)` + opacity 0 → rest |
| Phase 2 (12–50%) | hold, fully legible |
| Phase 3 (50–68%) | pulled into the O: `translateX(−(i × .55em + .2em))`, `scale(.1)`, `rotate(−90deg)`, `blur(3px)`, opacity → 0; easing `cubic-bezier(.6, −.1, .9, .4)` (accelerating pull) |
| Phase 4 (68–100%) | consumed (held invisible) |
| Ring | conic gradient `transparent → --primary → bright tip → transparent`, rotating 1.5s linear; blurred duplicate behind it as the glow |

---

## Reference implementation (standalone HTML/CSS)

```html
<div class="oblivion-loader" role="status" aria-label="Loading">
  <span class="eh-o"></span>
  <span aria-hidden="true"
    ><i style="--i:1">b</i><i style="--i:2">l</i><i style="--i:3">i</i><i style="--i:4">v</i><i style="--i:5">i</i><i style="--i:6">o</i><i style="--i:7">n</i></span>
</div>
```

```css
.oblivion-loader {
  display: flex;
  align-items: center;
  font-size: 34px;            /* scale knob — everything below is in em */
  font-weight: 600;
  letter-spacing: .01em;
  color: var(--foreground);
}
.oblivion-loader i { font-style: normal; }

/* The O — a black hole with an accretion ring */
.eh-o { width: .72em; height: .72em; position: relative; margin-right: .07em; flex: none; }
.eh-o::before,
.eh-o::after {
  content: "";
  position: absolute; inset: 0; border-radius: 50%;
  background: conic-gradient(from 0deg,
    transparent 0deg,
    var(--primary) 200deg,
    color-mix(in oklch, var(--primary) 45%, white) 290deg,
    transparent 340deg);
  mask: radial-gradient(closest-side, transparent calc(100% - 3.5px), #000 calc(100% - 3px));
  animation: eh-spin 1.5s linear infinite;
}
.eh-o::after {                 /* glow */
  inset: -5px; filter: blur(7px); opacity: .7;
  mask: radial-gradient(closest-side, transparent calc(100% - 10px), #000);
}
@keyframes eh-spin { to { transform: rotate(360deg); } }

/* The letters — devoured tail-first */
.oblivion-loader i {
  display: inline-block;
  animation: eh-suck 3.4s ease-in infinite;
  animation-delay: calc((7 - var(--i)) * .13s);
}
@keyframes eh-suck {
  0%       { opacity: 0; transform: translateX(.4em); filter: blur(6px); }
  12%, 50% { opacity: 1; transform: none; filter: none;
             animation-timing-function: cubic-bezier(.6, -.1, .9, .4); }
  68%      { opacity: 0; filter: blur(3px);
             transform: translateX(calc(var(--i) * -.55em - .2em)) scale(.1) rotate(-90deg); }
  100%     { opacity: 0;
             transform: translateX(calc(var(--i) * -.55em - .2em)) scale(.1); }
}

/* Reduced motion: static wordmark, static ring */
@media (prefers-reduced-motion: reduce) {
  .oblivion-loader i, .eh-o::before, .eh-o::after { animation: none !important; }
  .oblivion-loader i { opacity: 1 !important; transform: none !important; filter: none !important; }
}
```

## App integration sketch (OblivionFindings)

- CSS above goes into `resources/css/app.css` (keyframes + classes near the
  other loading utilities); the app's existing `html.reduce-motion` block must
  also neutralise `eh-suck` / `eh-spin`.
- Component: `resources/js/components/ui/oblivion-loader.tsx`:

```tsx
const LETTERS = ['b', 'l', 'i', 'v', 'i', 'o', 'n'];

export function OblivionLoader({ className, size = 34 }: { className?: string; size?: number }) {
  return (
    <div role="status" aria-label="Loading" className={cn('oblivion-loader', className)} style={{ fontSize: size }}>
      <span className="eh-o" />
      <span aria-hidden="true">
        {LETTERS.map((ch, idx) => (
          <i key={idx} style={{ '--i': idx + 1 } as React.CSSProperties}>{ch}</i>
        ))}
      </span>
    </div>
  );
}
```

- Wire it into `components/ui/loading-state.tsx` (the canonical loading
  primitive per DESIGN.md) rather than dropping it ad-hoc onto pages.

---

## Implementation & conformance

**Enforcement points:**

1. Keyframes + classes (`eh-spin`, `eh-suck`, `.oblivion-loader`, `.eh-o`)
   land in `resources/css/app.css` near the other loading utilities, and the
   app's `html.reduce-motion` block must neutralise both keyframes (the
   `prefers-reduced-motion` block in the reference CSS covers the OS
   setting; the in-app toggle needs the same treatment).
2. Component `resources/js/components/ui/oblivion-loader.tsx` (sketch
   above), then wired into `components/ui/loading-state.tsx` so every
   existing `<LoadingState>` call site upgrades for free.
3. **Ring-only variant** (`.eh-o` alone) becomes the standard small inline
   spinner (buttons, table cells). This supersedes the `Loader2
   animate-spin` convention in `POPUP_STYLE_GUIDE.md` § Submit button —
   `Loader2` is the interim spinner only until the ring variant ships.

**Where each form is used:**

| Surface | Form |
|---|---|
| Full-page boots, Inertia transitions | Full loader, 34px |
| Large section suspense / `<LoadingState>` | Full loader, ~20px |
| Buttons, table cells, small inline | Ring only (`.eh-o`) — never the animated word |

**What the conformance sweep looks for:**

1. The loader not yet implemented (CSS + component + `loading-state.tsx`
   wiring).
2. Ad-hoc `animate-spin` / `Loader2` / skeleton-less spinners on page or
   section loading surfaces — migrate to the loader via `<LoadingState>`.
3. Button/inline spinners still on `Loader2` after the ring-only variant
   ships.
4. Full animated word used at small sizes (inline contexts) — ring only.
5. `html.reduce-motion` not neutralising `eh-spin`/`eh-suck`.
6. Any hardcoded hue in loader usages (must be `--primary`/`--foreground`
   + `color-mix` only).

## See also

- [`DESIGN.md`](../DESIGN.md) — the app-wide UI contract
- [`POPUP_STYLE_GUIDE.md`](./POPUP_STYLE_GUIDE.md) — submit-button spinner
  (interim `Loader2` until the ring variant ships)
