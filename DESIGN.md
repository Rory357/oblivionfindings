# DESIGN.md — OblivionFindings UI contract

This is the single entry point for how UI gets built in this app. Read it
before writing or changing any page, component, or style. It is deliberately
short: it tells you the rules, where the bounded primitives live, and which
deeper guide to open for the pattern you're building. Do not invent new
colours, type scales, spacings, or component variants — everything you need
already exists as a token or a primitive.

**Stack:** Laravel + Inertia + React/TypeScript, Tailwind v4 (CSS-first
config in `resources/css/app.css` `@theme`), shadcn/Radix primitives,
lucide-react icons, Recharts.

---

## Non-negotiables

1. **Semantic tokens only.** Never `bg-violet-600`, `text-emerald-500`, or
   inline hex. Use `bg-primary`, `text-status-success`, `bg-category-hr`,
   `var(--chart-1)`, etc. ESLint blocks raw palette classes
   (`eslint.config.js`). The whole UI retints when the admin changes the
   brand colour on Settings → Branding — a hardcoded colour breaks that.
2. **Dark mode is automatic.** Every semantic token has a `.dark` variant in
   `app.css`. If you use tokens you never write `dark:` colour pairs.
3. **Check the inventory before building.** `resources/js/components/ui/` is
   the primitive library; `resources/js/components/` holds shared app
   components. If something close exists, extend it — don't fork a one-off.
4. **Typography helpers, not ad-hoc sizes.** `.text-page-title`,
   `.text-section-title`, `.text-subtle`, `.text-caption` (defined in
   `app.css`). Don't compose `text-2xl font-semibold` inline.
5. **Accessibility floor.** WCAG 2.1 AA contrast (status token pairs are
   pre-verified); colour is never the only signal (pair with label/icon);
   `focus-visible:ring-2 focus-visible:ring-ring` on all interactive chrome;
   respect the global reduced-motion block; ≥44 px tap targets on frontline
   surfaces (`.frontline-tap`).
6. **Safety colours are brand-independent.** Allergen/conflict/emergency
   surfaces use fixed `status-critical`/`status-warning` pairs, never
   brand-derived tints — an admin's brand hue must not be able to push a
   safety warning below AA contrast.

---

## Colour tokens (full detail: `design_styles/DESIGN_TOKENS.md`)

| Family | Tokens | Use for |
|---|---|---|
| Core | `background/foreground`, `card`, `popover`, `primary`, `secondary`, `muted`, `accent`, `destructive`, `border/input/ring` | Everything structural |
| Status | `status-success/warning/critical/info/neutral` (+ `-bg`, `-foreground`) | Severity and lifecycle states — via `<StatusBadge>` |
| Live | `live` / `live-bg` | In-progress/running things (distinct from info) |
| Category | `category-ops/hr/compliance/incidents/governance/sites/fleet/finance` (+ `-bg`) | Module-level tinting, hue-rotated from brand |
| Charts | `--chart-1`…`--chart-5` | Recharts fills — `fill="var(--chart-1)"` |
| Calendar sources | `--src-event/inspection/compliance/…` (+ `-bg`, `-ln`) | Site Calendar obligation sources only |
| Sidebar | `sidebar-*` | The app sidebar only |

Status → **always** `<StatusBadge>` (`components/ui/status-badge.tsx`) or
`getStatusColor()` from `@/lib/status-colors` — never a hand-rolled span.
New status key? Add it to `status-colors.ts` once; it's used on 50+ pages.

---

## Layout shells — pick the right one

| Surface | Shell |
|---|---|
| Admin/back-office pages | `layouts/app-layout.tsx` (sidebar) + `components/page-shell.tsx` for the title/description/actions header |
| Frontline/staff mobile pages | `layouts/staff-page-shell.tsx` (+ `staff-bottom-nav`, `.staff-shell-content`, `.frontline-sticky-footer`, `.frontline-header-inset` safe-area helpers from `app.css`) |
| Auth screens | `layouts/auth/*` |
| Settings pages | `layouts/settings/layout.tsx` |
| Marketing pages | `layouts/marketing-layout.tsx` |

Page heroes follow the shared hero pattern — see
`design_styles/GOVERNANCE_HERO_GUIDE.md` and `docs/hero-unification-v2-plan.md`
before styling a new hero.

### Record profile header — the client-profile contract

Every record profile page (clicking into a client, site, staff member, …)
opens with the gradient header modelled on the **client profile hero**
(`components/clients/profile/hero.tsx`, used by
`pages/operations/clients/show.tsx`). Its regions, top to bottom:

1. **Back link** to the index (left) + **record id** chip (right).
2. **Identity row** — avatar with presence dot, record name + status
   pill, identity sub-line (nickname · age / location), then glass chips
   and toned badges. Right-aligned: the **action cluster** (one white
   primary dropdown like "Add note", then glass secondary buttons — chat,
   edit, overflow) above **stat tiles** (2–3 headline stats: label +
   value).
3. **Context tiles row** — module-specific full-width tiles (e.g. the
   expandable next-shift tile, the safety-information strip).
4. **Vitals grid** — glass metric boxes (label, value, trend, one-line
   detail). The *set* of boxes is module-specific — fewer or different
   boxes are fine — but every box keeps this anatomy and the glass
   treatment (`border-primary-foreground/20 bg-primary-foreground/10`,
   all bound to semantic tokens).
5. **Hero footer** — the tier-1 group rail using the **connected-tab**
   treatment (active tab merges with the page background — see
   `design_styles/NAVIGATION_STYLE_GUIDE.md` Rule 1). Count and warning
   pills on these navs follow the counter state-colour rule — the number
   must stay visible when its tab activates.

New profile pages compose these regions (extract shared pieces rather
than fork); the site profile hero (`components/sites/profile/hero.tsx`)
is the sibling implementation to keep aligned with this contract.

---

## Recurring patterns — where the canonical version lives

- **Entity add/edit — the wizard dialog is the default.** Any add or edit of
  an entity record (site, client, staff, asset, incident, …) or any form with
  2+ sections uses the `WizardShell` modal from
  `components/wizard/shell.tsx`: stepper rail with icons + blurbs,
  completeness meter, "Step x of y" header, progress strip, review step
  (`ReviewCard`/`ReviewRow`), success pane (`WizardSuccessPane`). Reference
  implementations: `components/clients/add-client-dialog.tsx` (the original
  contract) and `components/sites/add-site-dialog.tsx`. Edit reuses the same
  wizard as Add, prefilled. Full anatomy and rules:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Entity wizard dialogs".
- **Simple dialogs** (single-section forms, confirmations, detail viewers) —
  follow `design_styles/POPUP_STYLE_GUIDE.md` exactly (shell/body split,
  `_dialogs.tsx` co-location, width tokens, tile pickers).
- **Buttons — the "soft depth" treatment (approved 2026-09-04).** Primary
  (`default`) = gradient-lit primary with inner highlight + violet glow;
  secondary (`outline`) = card surface with soft shadow and
  purple-tinting border on hover; both lift 1px on hover, press 0.5px,
  and use the 45%-mix ring focus. Full spec and implementation notes:
  `design_styles/BUTTON_STYLE_GUIDE.md`. The single enforcement point is
  `buttonVariants` in `components/ui/button.tsx` — never restyle
  primary/secondary buttons per page.
- **Destructive confirmation** — `components/confirm-dialog.tsx`; destructive
  buttons use `<Button variant="destructive">` and stay readable.
- **Empty / loading / error states** — `components/ui/empty-state.tsx`,
  `loading-state.tsx`, `error-state.tsx`, and the `skeleton-*` set. Never a
  bare "No data" `<p>`. **Skeleton vs loader:** when the incoming layout is
  known, use the matching skeleton (`skeleton-table`, `skeleton-card-list`,
  `skeleton-card`) so the page doesn't jump; the Event Horizon loader (next
  bullet) is for boots, transitions, and indeterminate section loads where
  no layout can be promised.
- **Loading spinner — the "Event Horizon" brand loader (approved
  2026-09-04).** Page, transition, and section loading use the animated
  Oblivion wordmark (the O is a black-hole accretion ring in `--primary`
  that devours the letters tail-first); buttons and other small inline
  spots use its **ring-only** variant — never the animated word at small
  sizes. Tokens-only (retints with branding), transform/opacity/blur-only
  motion, static wordmark under reduced motion. Full spec + reference CSS:
  `design_styles/LOADER_STYLE_GUIDE.md`; delivered through
  `components/ui/loading-state.tsx`, not ad-hoc per page.
- **Toasts** — `components/flash-toaster.tsx` (server flash) and
  `undo-toast.tsx` (undoable actions).
- **Two-tier section navigation (approved 2026-09-04).** Tier-1 group
  rails inside a hero use the **"connected tab"**: the active tab takes
  the page `--background`, `--primary` text, `12px 12px 0 0` radius, and
  sits flush with the hero's bottom edge (the merge is the affordance);
  inactive pills stay translucent primary-foreground. Tier-2 sub-tab
  strips under an open section are **neutral at rest, toned only when
  active**, with tones assigned by position (`index % 5`:
  violet → teal → green → amber → rose) — never hand-picked. Full spec:
  `design_styles/NAVIGATION_STYLE_GUIDE.md`. Enforcement points:
  `GroupPillRail` + `TierTwoTabs` (`components/page/grouped-profile-nav.tsx`)
  and the rostering `TabStrip` (`components/rostering/tab-strip.tsx`).
- **Index view tabs — the tinted-pill tab.** For standalone view/filter
  tab rows on index pages (e.g. All sites / At risk / …): active =
  rounded `bg-primary/10 text-primary` wrap with a `border-b-2
  border-primary` underline; inactive = `text-muted-foreground` with
  `hover:bg-accent`; icon + label + count pill. Shared implementation:
  `components/page/page-tabs.tsx`; visual reference: `ViewTabs` in
  `pages/sites/index.tsx`.
- **Count/warning pills on tabs and nav items follow their tab's state
  colour — never a fixed colour.** Active tab → `bg-primary/15 text-primary`
  (on an active connected tab, whose surface is the page background, the
  counter switches to tone-on-light pairs — never `text-primary-foreground`);
  inactive → `bg-muted text-muted-foreground`; alert counts →
  `bg-status-critical-bg text-status-critical`. A counter that keeps one
  colour across states goes invisible when its tab activates (the hero
  "Overview 7" white-on-white bug in `grouped-profile-nav.tsx`
  `WarningPill onHero`).
- **Filters above tables** — `components/filter-bar.tsx`.
- **Data tables & pagination** — table markup uses the
  `components/ui/table.tsx` primitives; server-paginated lists use
  `components/ui/laravel-pagination.tsx` (the canonical paginator — 64+
  pages already do); loading state is `skeleton-table`. Wide tables scroll
  inside their own `overflow-x-auto` container — the page body never
  scrolls horizontally.
- **Scroll surfaces** — custom scroll areas use `.scrollbar-pretty` (thin,
  themed); `.scrollbar-none` is reserved for the icon sidebar rail.
  `.nice-scroll` is a meal-planner-era duplicate of `.scrollbar-pretty` —
  don't spread it further (consolidation candidate).
- **Icons** — lucide-react only, no second icon set and no emoji-as-icon.
  Sizes: `size-4` (16px) in buttons/menus/inputs, 15px in nav pills,
  `size-3.5` in dense chrome; default stroke. Icon-only controls always
  carry an `aria-label`.
- **Stat/KPI cards** — `components/ops-stat-card.tsx` / `fleet-stat-card.tsx`.
- **Multi-step flows** — `WizardShell` (see entity add/edit above).
  `components/wizard-stepper.tsx` is **legacy** — do not use it in new work.
- **Forms** — shadcn `<Input>`/`<Select>`/`<Textarea>`; dense native-element
  forms may use the `.input`/`.select`/`.textarea` utility classes from
  `app.css` (shifts-dialog pattern). Errors via `components/input-error.tsx`.
- **Density & motion** — user preference drives `html[data-density]` and
  `html.reduce-motion`; new chrome must not fight these. Motion budget:
  micro-interactions run 150–300ms (buttons 160ms, wizard panes 300ms)
  and animate transform/opacity/blur only — no layout properties; longer
  or looping motion is reserved for the brand loader and must be
  neutralised by the reduce-motion blocks.

---

## Named anti-patterns

These are recurring mistakes. Recognise and avoid them; each has burned us
before.

- **Raw palette classes / inline hex** — bypasses rebranding; ESLint blocks it.
- **Hardcoded hues inside CSS utilities** — ESLint only sees classNames, so
  hex hiding in `app.css` rules or `style={{}}` props escapes it. Known
  offender: `.icon-gradient-bg` (sidebar hover) hardcodes an
  indigo→purple→pink hex gradient — it won't retint with branding.
  Utility CSS uses tokens/`color-mix` like everything else.
- **`<SelectItem value="">`** — crashes Radix Select. Use
  `value={data.field || undefined}` for optional selects.
- **Hand-rolled status pills** — diverge from the verified-contrast token
  pairs; use `<StatusBadge>`.
- **Ad-hoc `text-2xl`/`text-xl` headings** — use the typography helpers.
- **`dark:` colour pairs on token-styled elements** — redundant and drifts.
- **Pinning a fixed hue to a module** (e.g. `bg-purple-500` for HR) — use
  `bg-category-hr` so rebrand cascades.
- **Unconditional animation** — must resolve safely under reduced-motion
  (transform-only, or covered by the global reduce block in `app.css`).
- **Ad-hoc `pb-[env(safe-area-inset-bottom)]` sprinkles** — use the
  `.safe-area-*` / frontline helpers.
- **Brand-tinted safety surfaces** — safety uses fixed status tokens (see
  non-negotiable #6).
- **Rebuilding an existing primitive** — check the inventory first.
- **Full-page create/edit wizards** — entity add/edit is a `WizardShell`
  modal opened from the index page, not a routed page.
  (`pages/sites/create.tsx` + `edit.tsx` on `wizard-stepper` are the last
  legacy holdouts — migration targets, not precedents.)
- **Hand-rolled multi-step chrome** — bespoke steppers, progress bars, or
  success screens inside a dialog; compose `WizardShell` and its companions
  instead.
- **Legacy two-tier nav styling** — white-pill active states on hero
  rails, or underline-only sub-tab strips; both are superseded by the
  connected tab (Rule 1) and toned strip (Rule 2) in
  `design_styles/NAVIGATION_STYLE_GUIDE.md`.
- **Sub-nav strip wrapped in a card** — the tier-2 strip is a bare flex
  row on the page background: no border, card fill, shadow, or container
  padding.
- **Hand-picked or semantic sub-tab tones** — tier-2 tones come from the
  positional cycle (`index % 5`), never chosen per tab; and tones must
  not appear on inactive tabs (neutral at rest).
- **Fixed-colour counters on stateful nav** — a count badge that doesn't
  change colour with its tab/pill's active state (goes invisible when the
  active state inverts the colours, e.g. white counter on a white active
  hero pill).
- **Generic spinners on loading surfaces** — ad-hoc `animate-spin` circles
  or bare `Loader2` for page/section loading instead of the Event Horizon
  loader (via `<LoadingState>`); and the full animated word at inline
  sizes where only the ring variant belongs.
- **Per-page button restyling** — custom backgrounds, shadows, or hover
  transforms on `default`/`outline` buttons (via `className` or raw
  elements) instead of the soft-depth treatment owned by
  `components/ui/button.tsx` (see `design_styles/BUTTON_STYLE_GUIDE.md`).
- **Record profile pages without the profile header contract** — a
  show/profile page opening with a plain heading or a one-off banner
  instead of the client-profile hero regions (identity row, action
  cluster, stat tiles, vitals grid, group-pill footer).
- **Non-existent colour tokens** — utilities like `bg-warning/20` or
  `text-warning-foreground` reference a `warning` token that isn't in the
  `@theme` registry, so they silently render as nothing. The real tokens
  are `status-warning` / `status-warning-bg` / `status-warning-foreground`.
  If a utility's token isn't in `app.css` `@theme`, it doesn't exist.

---

## Conformance sweep (run on request)

When asked to "check everything conforms", audit the codebase against this
file. Concrete, mechanically-checkable probes:

1. **Legacy wizards** — any import of `components/wizard-stepper.tsx`
   (known: `pages/sites/create.tsx`, `pages/sites/edit.tsx`,
   `pages/sites/_wizard.tsx`) and any routed full-page create/edit wizard.
   Target state: a `WizardShell` add/edit dialog on the index page (the
   Add Site dialog `components/sites/add-site-dialog.tsx` already exists —
   check the *edit* path uses it too).
2. **Bespoke multi-step dialogs** — dialogs with their own step state that
   don't import `components/wizard/shell.tsx`. Also audit the parallel
   wizard helper modules (`components/wizard/primitives.tsx`,
   `components/hr/wizard.ts`, `components/finance/wizard.ts`) — they must
   wrap/re-export the shell, not fork its chrome.
3. **Add/edit flows for entity records** still using simple dialogs or plain
   pages when the record has 2+ sections of fields.
4. **Raw palette classes / inline hex** — ESLint catches new ones; sweep for
   grandfathered ones, plus the places ESLint can't see: hex in
   `style={{…}}` props and in CSS rules (known: `.icon-gradient-bg` in
   `app.css` — migrate to token-based `color-mix` or drop it).
5. **Hand-rolled status pills** — spans styled with `status-*` tokens that
   should be `<StatusBadge>`, and duplicated tone maps (known:
   `BADGE_TONE` in `components/clients/profile/hero.tsx` mirrors
   `StatusBadge`'s `VARIANT_CLASSES` — consolidate to one source).
6. **Ad-hoc heading sizes** — `text-2xl`/`text-xl font-semibold` composed
   inline instead of the typography helpers.
7. **`dark:` colour pairs** on token-styled elements.
8. **Safe-area sprinkles** — raw `env(safe-area-inset-*)` outside the
   helpers in `app.css`.
9. **Tab strips** — (a) two-tier nav conformance per
   `design_styles/NAVIGATION_STYLE_GUIDE.md`: restyle `GroupPillRail` to
   the connected tab (Rule 1), `TierTwoTabs` and the rostering `TabStrip`
   to the toned strip (Rule 2); add the `--tone-teal` token to `app.css`
   + `@theme` first; flag hero rails still using white-pill active
   states, carded/underline-only sub-strips, hand-picked tones, or toned
   inactive states. (b) local copies of the index view-tab pattern
   (`ViewTabs` in `pages/sites/index.tsx`,
   `pages/operations/clients/index.tsx`,
   `pages/sites/calendar/SiteCalendar.tsx`) — consolidate on
   `components/page/page-tabs.tsx`.
10. **Nav counters** — count/warning pills that don't follow their tab's
    state colour. Known: `WarningPill onHero` in `grouped-profile-nav.tsx`
    hardcodes `text-primary-foreground` (invisible on the active white
    hero pill) and uses the non-existent `warning` token
    (`bg-warning/20` → renders nothing; should be `status-warning` pairs).
11. **Ghost tokens** — grep utility classes against the `@theme` registry
    in `app.css`; any `bg-*/text-*/border-*` naming a token that isn't
    registered renders as nothing and must be re-pointed at a real token.
12. **Profile header coverage** — enumerate record show/profile routes
    (clients, sites, staff, assets, …) and flag any whose header doesn't
    follow the client-profile contract (see "Record profile header"):
    missing regions, hand-rolled banners, group navs whose counters
    disappear when active.
13. **Button conformance** — apply the soft-depth spec
    (`design_styles/BUTTON_STYLE_GUIDE.md`): (a) update `buttonVariants`
    `default`/`outline` in `components/ui/button.tsx` if not yet done;
    (b) hand-rolled primary/secondary buttons (raw elements styled with
    `bg-primary` / `border bg-card`); (c) `className` overrides on
    default/outline `<Button>`s that fight the treatment; (d) `unstyled`
    usages reimplementing a standard button look.
14. **Loader conformance** — apply
    `design_styles/LOADER_STYLE_GUIDE.md`: (a) implement the loader
    (keyframes/classes in `app.css` incl. `html.reduce-motion`
    neutralisation, `components/ui/oblivion-loader.tsx`, wire into
    `loading-state.tsx`); (b) migrate ad-hoc `animate-spin`/`Loader2`
    page- and section-loading spinners to `<LoadingState>`; (c) switch
    inline/button spinners to the ring-only variant; (d) flag the full
    animated word at inline sizes and any hardcoded hues.
15. **Tables, pagination & scroll surfaces** — hand-rolled `<table>`
    styling that bypasses `components/ui/table.tsx`; paginated lists not
    using `laravel-pagination.tsx`; wide tables without their own
    `overflow-x-auto` wrapper; scrollbar utilities other than
    `.scrollbar-pretty` on new scroll areas (`.nice-scroll` usages are
    consolidation targets).
16. **Icons** — non-lucide icon usages (other sets, inline SVGs
    duplicating lucide glyphs, emoji-as-icon); icon-only buttons/links
    missing `aria-label`; off-scale icon sizes in standard chrome.

Report findings grouped by pattern with file:line references; fix only when
asked, and migrate one pattern at a time.

## Keeping this document alive

This file only works if it reflects reality. The loop:

1. When a design mistake is corrected **twice**, name it in *Named
   anti-patterns* above (one line, why it's wrong, what to do instead).
2. If the mistake is mechanically detectable, also encode it as an ESLint
   `no-restricted-syntax` rule in `eslint.config.js` — the rule's message
   should point back here.
3. New tokens/primitives: add to `app.css` / `components/ui/`, document in
   `design_styles/DESIGN_TOKENS.md`, and add a one-line pointer here if it's a
   pattern others will reach for.
4. Keep this file short. Detail belongs in the linked guides; this is the
   map, not the territory.

## Deeper guides

- `design_styles/DESIGN_TOKENS.md` — full token reference, charts, adding tokens
- `design_styles/POPUP_STYLE_GUIDE.md` — dialog anatomy and conventions
- `design_styles/BUTTON_STYLE_GUIDE.md` — the soft-depth button spec (primary/outline)
- `design_styles/NAVIGATION_STYLE_GUIDE.md` — two-tier section nav (connected tab + toned strip)
- `design_styles/LOADER_STYLE_GUIDE.md` — the Event Horizon brand loader (+ ring-only inline variant)
- `design_styles/GOVERNANCE_HERO_GUIDE.md` — page hero pattern
- `resources/css/app.css` — the tokens themselves (source of truth)
- `resources/js/lib/status-colors.ts` — status → class map
- `resources/js/lib/derive-palette.ts` — brand colour → derived palette
