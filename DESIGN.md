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

**The global shell chrome (approved 2026-09-05).** The top bar +
sidebar follow `design_styles/APP_SHELL_STYLE_GUIDE.md` — the "Event
Horizon chrome": one continuous ink surface for header and sidebar
(dark in light mode, by design), "Oblivion Care" ring-O wordmark,
centred command search, Report incident / Clock in / Messages / bell /
avatar in the header (no Live chip), collapsible module groups, an
edge-tab whole-sidebar collapse, and a neutral grey page ground with
white lifted cards. **The shell owns a single 20px gutter** (approved
2026-09-05; 10px was tried and revised up the same day) between the
ink chrome (sidebar + top bar) and page content — `p-5` in
`app-sidebar-layout.tsx`'s `DEFAULT_CONTENT_CLASS`
— and nothing re-pads it: `PageLayout` defaults to `padding="none"`,
and pages must not add their own outer padding against the chrome.
Any request to build, restyle, or "redo" the sidebar/top bar/page
background implements that guide. Enforcement points: `app-header.tsx`,
`app-sidebar.tsx`, `layouts/app/app-sidebar-layout.tsx`, shell tokens
in `app.css`.

### Page headers — the Event Horizon header (approved 2026-09-05)

**The top of every page has ONE source of truth:**
`design_styles/PAGE_HEADER_STYLE_GUIDE.md`. Every index/list page and
every record profile page opens with the same fixed-rhythm "Event
Horizon" header band (meter-row revision approved 2026-09-06): a top
row of identity (ring mark, title + status chip, one fact subline)
left and scoped search + action cluster right; then **one full-width
METER ROW** — instrument blocks (stat / delta stat / bar meter / donut
/ sparkline, 4–6 per page) carrying the page's key numbers, **every
block a link to its view** (Hazards → open hazards, Occupancy → beds,
…); then the page-specific filter row — **all INSIDE the header**;
the page's main view tabs on the bottom edge as the Rule 1
connected-tab rail. Above the band, the shell renders its slim
breadcrumb strip: every page passes a full trail rooted at **Home
(`/dashboard`)** to `AppLayout breadcrumbs` (added 2026-09-06). Lists
render nothing between the header and their content;
individual/profile pages add one tier-2 sub-nav strip (ghost tabs,
positional tones) below it. Visual reference:
`public/eh-hero-variants.html`.

Implemented (2026-09-06, meter-row revision included) as **one
`PageHeader` component** — `components/page/page-header.tsx` (with the
`PageHeaderMeterBlock` family) plus the `.eh-header` / `.eh-mark-ring`
/ `.eh-meter` utilities in `app.css`; reference migration
`pages/sites/index.tsx`. The sky is **always the branding colour's
own ramp** (approved 2026-09-06): whatever colour Settings → Branding
sets, a dark shade of `--primary` at the top fades into the actual
`--primary` at the bottom (relative-colour oklch keeps the hue) —
never a neutral/ink top, never a hand-picked palette.

This supersedes the `PageHero` banner system
(`components/page/page-hero.tsx`), the Governance Hero Guide, the
hero-unification plans, and the client/site profile heroes as
references — all of those are migration targets (conformance probe
12), not precedents. Do not consult or extend them for new work.
Both profile heroes are now migrated and deleted (sites 2026-09-06,
clients 2026-09-07 — `pages/operations/clients/show.tsx` is on the
`PageHeader` profile variant with its alert ribbon folded into the
meter row).

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
- **Index view tabs — main tabs live in the hero (corrected 2026-09-05).**
  A page's main view/filter tabs (e.g. All sites / At risk / …) render in
  the hero footer as the Rule 1 **connected-tab** rail
  (`design_styles/NAVIGATION_STYLE_GUIDE.md`): the active tab takes the
  page `--background` and sits flush with the hero's bottom edge — never
  as a tinted-pill strip below the hero. Counters follow the counter
  state-colour rule. Reference implementation: `PageHeaderRail` in
  `components/page/page-header.tsx` (used by `pages/sites/index.tsx`).
  `components/page/page-tabs.tsx`'s tinted-pill
  strip remains only for secondary in-page tab rows that have no hero
  rail to live in.
- **Count/warning pills on tabs and nav items follow their tab's state
  colour — never a fixed colour.** Active tab → `bg-primary/15 text-primary`
  (on an active connected tab, whose surface is the page background, the
  counter switches to tone-on-light pairs — never `text-primary-foreground`);
  inactive → `bg-muted text-muted-foreground`; alert counts →
  `bg-status-critical-bg text-status-critical`. A counter that keeps one
  colour across states goes invisible when its tab activates (the hero
  "Overview 7" white-on-white bug in `grouped-profile-nav.tsx`
  `WarningPill onHero`).
- **Filters** — a page's primary filters live in its header's filter
  row (`design_styles/PAGE_HEADER_STYLE_GUIDE.md` §5), never in a bar
  below the header; `components/filter-bar.tsx` remains only for
  secondary tables inside cards/sections.
- **Entity list surfaces — cards + tables (approved 2026-09-06).**
  Every listable record (sites, clients, incidents, staff, assets, …)
  renders through the two Event Horizon list contracts in
  `design_styles/LIST_STYLE_GUIDE.md`: the entity card (status
  meridian, identity row, fact chips, one optional metric slot, alert
  chips, utility footer) and the entity table (one shell, identity
  cell first, kebab last, columns picked from the cell library via a
  per-entity column spec). Both keep the kebab menu AND the
  right-click context menu on every card/row, fed by one `MenuItem[]`.
  No readiness/onboarding displays. Implemented (2026-09-06) in
  `components/lists/` (`EntityCard`, `EntityTable`, cell library,
  shared `entity-menu.tsx`, `ListCaption`); reference migration
  `pages/sites/index.tsx`.
- **Data tables & pagination** — table markup uses the
  `components/ui/table.tsx` primitives; server-paginated lists use
  `components/ui/laravel-pagination.tsx` (the canonical paginator — 64+
  pages already do); loading state is `skeleton-table`. Wide tables scroll
  inside their own `overflow-x-auto` container — the page body never
  scrolls horizontally. Entity list tables additionally follow the
  table contract in `design_styles/LIST_STYLE_GUIDE.md`.
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
- **Main view tabs below the hero** (corrected 2026-09-05) — a page's
  primary view/filter tabs rendered as a separate tinted-pill/underline
  strip on the page ground (the old `ViewTabs`/`PageTabs` row); they
  belong in the hero footer as the Rule 1 connected-tab rail.
- **Drop shadow on a connected-rail hero** (corrected 2026-09-05) — any
  `shadow-*` on a hero that hosts the Rule 1 connected-tab rail. The
  shadow darkens the page ground along the hero's bottom edge, so the
  active tab's `--background` fill reads white beside it and the merge
  breaks. Shadow-parting tricks (erasing it only under the tab) were
  tried and rejected — the ground beside the tab stays tinted. A
  connected-rail hero casts no shadow (see NAVIGATION_STYLE_GUIDE.md
  "Merge seam").
- **Conversational page headers** (corrected 2026-09-05) — greeting
  titles ("Kia ora … your sites at a glance"), live/sync eyebrows, or
  underlined phrases in a page header. Headers follow the Event
  Horizon contract (`design_styles/PAGE_HEADER_STYLE_GUIDE.md`): plain
  title + one `<StatusBadge>` chip, a one-line fact subline, the
  clickable meter-block row, and a white-primary + glass-secondary
  action cluster on the brand sky.
- **Missing or non-Home-rooted breadcrumbs** (reinforced 2026-09-07) —
  EVERY page on the Event Horizon header MUST pass a full trail rooted
  at **Home (`/dashboard`)** to `AppLayout breadcrumbs` — no exceptions,
  and it is a required step of every page migration (verify it before
  ticking the page off). `Home → Sites`, `Home → Sites → Aurora House`,
  `Home → Sites → Reports → Houses`. Two failure modes both count as the
  bug: (a) no `breadcrumbs` prop at all, and (b) a trail that starts at
  the section instead of Home. Watch the single-crumb trap: the shell
  **hides** a one-crumb trail (it would duplicate the band title), so a
  page below the top level that passes only `[{ Calendar }]` renders NO
  strip — it must be `[{ Home }, { Calendar }]`. The band never renders
  its own breadcrumbs; the strip is shell chrome
  (PAGE_HEADER_STYLE_GUIDE.md §2).
- **Dead or decorative meter blocks** (meter-row revision 2026-09-06) —
  a header block that doesn't navigate anywhere, or one whose trend/
  fraction data has no real backend source. Every block links to the
  view where its number lives; a block without live data is dropped,
  not faked.
- **Numbers-only meter blocks** (corrected 2026-09-06; budget case
  corrected 2026-09-07) — a header meter block rendered as a bare big
  number when the metric has an honest visual form with a live source:
  capacity → bar meter, share of a whole → donut, daily series →
  sparkline, people → avatar stack (photos/initials with hover name
  cards). **Spend against a configured budget/cap is the bar-meter form
  too** (the guide's "funding" case): the money amount STAYS the block's
  focal 20px number, with the budget-consumed bar rendered beneath it
  and "$X left of $Y" in the caption (warning tone + "$X over" when
  exceeded) — never a bare money stat while a budget exists, and never
  the amount demoted to the small head value (reference: the Meal
  Planner Week-cost block, corrected 2026-09-07).
  Graph-first: the visual carries the block and the count moves to the
  head value; a plain stat is the fallback only when no real visual form
  exists — e.g. the same money figure with NO budget configured
  (PAGE_HEADER_STYLE_GUIDE.md §5).
- **Sunken active-rail labels** (corrected 2026-09-06, re-verified
  2026-09-07) — the Rule 1 connected active tab is taller than its
  inactive pills, so an uncompensated label sits ~3px below their shared
  text line. The active tab carries bottom padding (6px in the Event
  Horizon rail) so every rail label's centreline sits **exactly 23px
  above the band edge** — one optical line, active and inactive alike,
  on every page. Re-solve the centreline equation (and re-measure)
  whenever rail geometry changes. The inverse anti-pattern is just as
  bad: **do NOT eyeball-"fix" the active label** — the dark label on the
  white active tab reads as slightly high next to the light labels on
  the sky, but that is an optical impression (measured Δ = 0.00px), and a
  per-page margin/translate nudge breaks the shared `PageHeaderRail` for
  every page. The alignment lives ONLY in that shared component
  (PAGE_HEADER_STYLE_GUIDE.md §7), never per page.
- **Sub-nav strip wrapped in a card** — the tier-2 strip is a bare flex
  row on the page background: no border, card fill, shadow, or container
  padding.
- **Pinned sub-tabs** (removed 2026-09-06) — pin/unpin buttons (or any
  per-tab management affordance) on the tier-2 sub-tab strip. Sub-tabs
  are NEVER pinnable; the strip is tabs only. `TierTwoTabs` has no pin
  API by design — do not grow one back.
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
- **Page tops that bypass the Event Horizon header** — a page opening
  with a plain heading, a one-off banner, a legacy `PageHero`, or a
  search/filter bar sitting BELOW the header, instead of the fixed
  contract in `design_styles/PAGE_HEADER_STYLE_GUIDE.md` (search and
  filters live in the header; lists get no sub-bar; individuals add
  the tier-2 sub-nav strip).
- **Bespoke entity cards or list tables** — hand-rolled card
  anatomies, per-page table styling, or invented table cells instead
  of the contracts in `design_styles/LIST_STYLE_GUIDE.md` (status
  meridian + slot anatomy for cards; identity-first/kebab-last +
  cell library for tables). Includes dropping either actions entry
  point — every card/row keeps the kebab AND the right-click context
  menu with the full existing `MenuItem` set — and reintroducing
  readiness/onboarding displays (removed 2026-09-06).
- **A global "Live"/sync chip in the top bar** — removed from the
  approved shell (2026-09-05); live/sync status belongs on the page
  surfaces that need it, not in the global chrome.
- **Violet-tinted or pure-white page grounds** — the light
  `--background` is a neutral mid-light grey with white cards on top
  (see `APP_SHELL_STYLE_GUIDE.md` §4; charcoal grounds were tried and
  rejected as too dark). Don't reintroduce tinted near-whites behind
  cards or hardcode a per-page ground.
- **Stacked chrome-to-content gutters** — the app shell owns ONE 20px
  gutter between the ink chrome (sidebar + top bar) and page content
  (`p-5` in `app-sidebar-layout.tsx`; approved 2026-09-05, replacing
  a 56/64px stack of layout + PageLayout padding; 10px was tried and
  revised up the same day). Never re-pad it: no
  outer `p-*`/`px-*`/`py-*` on a page's root wrapper, no `padding`
  value on `PageLayout` inside the shell (its default is `none`), no
  `contentClassName` overrides that change the gutter. Surfaces outside
  the shell (marketing, staff, auth) are exempt.
- **Off-scale gaps between sections and cards** — the 20px rule
  (approved 2026-09-05) also governs the space BETWEEN things on a
  shell page: section-to-section stacks (hero → tabs → content —
  `PageLayout`'s `gap-5` is the enforcement point) and gaps between
  cards in a grid/list are `gap-5` (20px), not `gap-4`/`gap-6`/
  `gap-[15px]`/`space-y-6`. Spacing INSIDE a card (its own
  padding and micro-layout) is unaffected. Existing pages migrate via
  conformance sweep probe 17.
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
   should be `<StatusBadge>`, and duplicated tone maps (the known
   `BADGE_TONE` offender left with the client profile hero, deleted
   2026-09-07).
6. **Ad-hoc heading sizes** — `text-2xl`/`text-xl font-semibold` composed
   inline instead of the typography helpers.
7. **`dark:` colour pairs** on token-styled elements.
8. **Safe-area sprinkles** — raw `env(safe-area-inset-*)` outside the
   helpers in `app.css`.
9. **Tab strips** — (a) two-tier nav conformance per
   `design_styles/NAVIGATION_STYLE_GUIDE.md`: `GroupPillRail` (Rule 1
   connected tab) and `TierTwoTabs` (Rule 2 toned strip) were restyled and
   `--tone-teal` added 2026-09-05; the rostering `TabStrip` still needs
   its tones aligned to the positional cycle. Flag hero rails still using
   white-pill active states, carded/underline-only sub-strips,
   hand-picked tones, or toned inactive states. (b) pages whose main view tabs still render below
   the hero as tinted-pill strips (`ViewTabs` in
   `pages/sites/calendar/SiteCalendar.tsx`; the clients index migrated
   2026-09-07) — migrate to the
   connected-tab hero rail (reference: `PageHeaderRail` in
   `components/page/page-header.tsx`).
10. **Nav counters** — count/warning pills that don't follow their tab's
    state colour. (The known `WarningPill onHero` offender in
    `grouped-profile-nav.tsx` — `text-primary-foreground` on the active
    pill plus the ghost `warning` token — was fixed 2026-09-05; it now
    uses the verified `status-warning-bg`/`status-warning` pair in all
    states.)
11. **Ghost tokens** — grep utility classes against the `@theme` registry
    in `app.css`; any `bg-*/text-*/border-*` naming a token that isn't
    registered renders as nothing and must be re-pointed at a real token.
12. **Page header coverage** — enumerate index and record show/profile
    routes and flag any whose page top doesn't follow the Event
    Horizon contract (`design_styles/PAGE_HEADER_STYLE_GUIDE.md`):
    legacy `PageHero` usages, hand-rolled banners, missing header
    slots, search/filter bars below the header, list pages with
    sub-bars, group navs whose counters disappear when active.
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
17. **20px spacing rule** (approved 2026-09-05) — on app-shell pages:
    (a) anything re-padding the shell's single `p-5` gutter (outer
    padding on page root wrappers, `PageLayout padding=` values inside
    the shell, `contentClassName` gutter overrides); (b) off-scale gaps
    between sections or between cards (`gap-4`, `gap-6`+, `gap-[Npx]`,
    `space-y-*` on section stacks and card grids/lists — should be
    `gap-5`). Card-internal spacing is out of scope. Migrate one
    module at a time.
18. **List contract coverage** (approved 2026-09-06) — enumerate
    listable-entity index views and flag any not on the
    `design_styles/LIST_STYLE_GUIDE.md` contracts: hand-rolled entity
    cards, per-page table styling or invented cells, cards/rows
    missing the kebab or right-click context menu (or with diverged
    `MenuItem` sets between the two), dishonest empty states, and any
    remaining readiness/onboarding displays. `pages/sites/index.tsx`
    (2026-09-06, the reference) and
    `pages/operations/clients/index.tsx` (2026-09-07) are migrated.

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
- `design_styles/APP_SHELL_STYLE_GUIDE.md` — the global shell: ink header + sidebar chrome, collapse behaviour, grey page ground
- `design_styles/POPUP_STYLE_GUIDE.md` — dialog anatomy and conventions
- `design_styles/BUTTON_STYLE_GUIDE.md` — the soft-depth button spec (primary/outline)
- `design_styles/NAVIGATION_STYLE_GUIDE.md` — two-tier section nav (connected tab + toned strip)
- `design_styles/LOADER_STYLE_GUIDE.md` — the Event Horizon brand loader (+ ring-only inline variant)
- `design_styles/PAGE_HEADER_STYLE_GUIDE.md` — the Event Horizon page header (index + profile variants, in-header search/filters, rail, sub nav)
- `design_styles/LIST_STYLE_GUIDE.md` — the Event Horizon list contracts (entity card grid + entity table, cell library, kebab/context-menu rules)
- `resources/css/app.css` — the tokens themselves (source of truth)
- `resources/js/lib/status-colors.ts` — status → class map
- `resources/js/lib/derive-palette.ts` — brand colour → derived palette
