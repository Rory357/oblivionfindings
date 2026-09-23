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
| Calendar sources | `--src-event/inspection/compliance/…` (+ `-bg`, `-ln`); Governance calendar: `--src-meetings/decisions/obligations/policies`; Finance calendar: `--src-invoice-due/bill-due/payment-run/gst-due/payroll/period-close` | Shared SiteCalendar sources only (Sites + Governance + Finance adapters) |
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

- **Calendars — always the Site Calendar style** (approved 2026-09-13).
  `resources/js/pages/sites/calendar/SiteCalendar.tsx` and `_parts.tsx`
  are the canonical calendar UI for My Calendar, site calendars and
  module calendars. Reuse the component with a data adapter for a
  different module's feed; do not create a separate FullCalendar skin,
  toolbar, month grid, colour scheme or calendar header. Keep each
  module's permissions, data ownership and workflows intact. Follow
  `design_styles/CALENDAR_STYLE_GUIDE.md` for the shared views, date
  anchor, source filters and responsive checks. **The viewed day,
  month and year must be obvious in the header**, not only in a small
  date picker. Existing calendars using other designs are migration
  targets; new calendar work must use this standard.

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
- **Premium attachment uploads** (approved by Stephan 2026-09-20) — invoice,
  evidence, photo and document fields reuse `FileDropzone` + `StagedFileCard`
  from `components/ui/file-dropzone.tsx`. Keep browse and keyboard access
  alongside drag/drop, show the owning module's file limits and truthful
  staged/uploading/saved/error states, and retain entries on failure.
  `AttachmentUploader` is for compatible existing-record endpoints; shared
  upload chrome does not supply storage, permissions or business approval.
  Full contract: `design_styles/POPUP_STYLE_GUIDE.md` § "Premium attachment uploads".
- **Searchable record selection in dialogs** (approved by Stephan 2026-09-20) —
  growing directories such as assets, vehicles, people and sites use a
  searchable picker, not a long unsearchable dropdown. Reuse shared
  `Popover` / `Command` primitives and an appropriate existing domain picker;
  show names with useful references/context and retain the canonical ID.
  Large lists use scoped server search with bounded results and clear
  loading/empty/error states. Small fixed choices keep their existing control;
  locked parent context stays locked. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Searchable record selectors".
- **Calendar date and range selection** (approved by Stephan 2026-09-20) —
  operational planning forms use the same visible calendar-selection pattern
  and chosen-date/range summary, including Report a problem and Plan appointment.
  Inspect `components/hr/leave-calendar-range.tsx` for the existing interaction;
  keep HR entitlement/hours/holiday policy with HR. Required dates stay required;
  offer Not known yet only where the owning workflow permits it. For timed
  appointments, place separate start/end times with a visible timezone below
  the dates. Preserve local-date meaning, validation and drafts. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Calendar date and range selection".
  The single-date variant (approved by Stephan 2026-09-20) also covers observation
  dates and deadlines: reuse the calendar interaction with one selected day,
  local Cancel/Use date and preservation of the paired time. Keep date ranges
  only where the owning workflow needs a range; see that guide's single-date
  clarification.
- **Clock and manual time entry** (approved by Stephan 2026-09-20) — operational
  date/time fields pair a consistent hour/minute clock picker with obvious manual
  entry and AM/PM controls. Accept every exact minute without rounding; keep
  local edits pending until Use time, and preserve the prior value on Cancel or
  Escape. Show the applicable timezone, keep controls labelled and keyboard
  operable, and preserve parent interval validation and recovery. Canonical
  `HH:mm` values stay separate from display; real scheduling requires explicit
  daylight-saving handling. Reuse shared primitives/tokens; the design prototype
  is not an existing production time component. Full contract:
  `design_styles/POPUP_STYLE_GUIDE.md` § "Clock and manual time entry".
- **Ticket-style work/record details** (approved by Stephan 2026-09-20) —
  suitable work orders, support issues and other owned-action records share a
  consistent body hierarchy: concise summary and linked sources, compact notes,
  prominent next action/progress, ownership, evidence and guarded completion.
  Keep the existing PageHeader/navigation and modal contracts. Each module owns
  terminology, fields, tabs, statuses, permissions and lifecycle; do not rename
  every record Ticket or copy Maintenance's release process into other modules.
  Full contract: `design_styles/WORK_RECORD_STYLE_GUIDE.md`.
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
- **Filterless rail tabs** (corrected 2026-09-08) — a header whose
  filter row is populated on one rail view and empty on another: the
  band collapses a row and the rhythm jumps between tabs. EVERY rail
  view supplies real filter pills for its own content (queue filters,
  a report range, a catalogue category, a board priority) — and never
  a decorative pill that filters nothing. Reference: IT & Support
  (`pages/it/index.tsx`), all seven views
  (PAGE_HEADER_STYLE_GUIDE.md §6).
- **Rail without the Find chip** (corrected 2026-09-08) — every rail
  ends with the ghost "⌕ Find" chip, exactly as the Sites profile
  renders it. `PageHeaderRail` now renders it BY DEFAULT with a
  built-in palette over its own views; record profiles pass `onFind`
  for their richer grouped `TabSearchPalette` (`/` opens it there —
  elsewhere `/` belongs to the header's scoped search). Suppressing
  the chip is a migration gap, not a variant — only pages with no rail
  at all (leaf record details) are exempt
  (PAGE_HEADER_STYLE_GUIDE.md §7).
- **Wrapping rail (two-line view tabs)** (corrected 2026-09-10) — a rail
  whose tabs wrap onto a second line because the page declares more views
  than the viewport fits (first seen: Rostering's 10 views + Recurring).
  The rail is ONE line, always. `PageHeaderRail` now handles overflow
  itself (priority+): it measures its tabs against the available width
  and collapses the trailing views into a ghost "⋯ More" pill with a
  popover, keeping two invariants — the ACTIVE view is always a visible
  tab (promoted out of the overflow if needed, because the flush
  page-ground merge is the affordance), and alert counters never
  disappear (overflowed alert counts sum onto the More pill in the fixed
  critical pair). Do not "fix" a crowded rail per page with wrapping,
  horizontal scrolling, or icon-only tabs — and prefer ≤ 8 declared
  views; beyond that the overflow pill is the designed behaviour, not a
  defect (PAGE_HEADER_STYLE_GUIDE.md §7).
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
- **One sidebar link per register** (corrected 2026-09-14, Governance) —
  a module sub-panel that lists every register as its own link (Governance
  had 26 for chairs/secretaries). Group sibling registers into hubs: the
  sidebar shows ONE entry per hub and the siblings become the hub's
  connected-tab header rail, each keeping its canonical URL so deep links
  and server authorisation are unchanged. Keep the config in one place
  (reference: `lib/governance-sections.ts` + `GovernanceSectionRail`, with
  the sidebar entry staying lit across the hub). Don't orphan a module
  that has no other entry (Roadmap became a Strategy hub tab), and never
  use hidden nav as the security boundary.
- **Auditor and developer language in member-facing copy** (corrected
  2026-09-14, Governance: "for a normal person it is difficult to
  understand") — reference codes used as names (`RES-2026-004` as a title
  or breadcrumb), raw enum values (`full_board`, `no_quorum`,
  `charitable_trust`), formulas (`floor(N/2)+1`), Title Case, US spelling,
  and words like immutable, frozen, snapshot, electorate, recuse,
  attestation, fingerprint or "bound". Write for a volunteer: follow
  `docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md` —
  sentence case, NZ English, the record title first with the code as a
  muted `refSuffix()`, every enum through `lib/governance-labels.ts`
  (server: `GovernanceLabels`), a `GovernanceTermHint` for any term that
  needs explaining, "Not available" instead of a green 0 when data is
  missing, a confirm dialog that states the effect for consequential
  actions, and visible text saying why something is blocked and who can
  unblock it. Server-generated titles, flash and validation messages
  count as UI copy.
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

- **Section captions inside a sidebar module** (corrected 2026-09-16:
  "the left nav bar has headings in them which is not easy to
  differentiate") — small uppercase captions ("Fleet", "Assets",
  "Payroll") splitting a module's sub-links into sections, in either the
  expanded rail or the collapsed-rail flyout. They read as more nav rows
  and compete with the links. Each module renders ONE continuous list of
  links (`flattenSidebarGroups` in `app-sidebar.tsx`), with no caption
  and no extra gap between the former sections; keep the group builders
  for ordering only. The module header rows themselves stay.
- **Sidebar rail that snaps to the top on every click** (corrected
  2026-09-16) — pages render their layout inline, so the shell remounts on
  each Inertia visit and a fresh scroll container starts at offset 0.
  `preserveScroll` on the links does not cover this. The rail's offset is
  remembered across mounts (`persistScrollTop`/`readStoredScrollTop` in
  `app-sidebar.tsx`) and restored in a layout effect; any new scrollable
  chrome that survives a visit needs the same treatment.
- **Browser `prompt()` / `confirm()` as a form** (corrected 2026-09-16,
  Finance) — collecting a reference, a reason or any other evidence through
  a chain of `window.prompt()` calls. There is no validation, no labels, no
  cancel semantics past the first step, and the sequence aborts silently
  half-way. Evidence and references are collected in a dialog with real
  fields and validation; the gate itself is
  `components/confirm-dialog.tsx`.
- **Hero numbers repeated as body KPI cards** (corrected 2026-09-16,
  Finance) — the same figures rendered in the header meter row and again in
  a card grid below it (and, on one page, a third time in a table footer).
  A number lives once, in the meter row, where it links to the list it
  came from. Residual in-body stats use `components/ops-stat-card.tsx`.
- **Page-local counts labelled as totals** (corrected 2026-09-16, Finance)
  — meter or stat blocks reading "Posted (this page)" / "Active (this
  page)", counted from the current page of a paginated list. Either the
  controller returns the real total for the current filter, or the block
  goes; a label that admits the number is wrong is not a fix.
- **Approximating an approved mockup** (corrected 2026-09-23, PKG-02B
  vehicle profile) — an approved design is built "in spirit": sections
  re-laid-out, tables turned into tiles, extra cards added, or whole views
  left as restyled legacy "interim" content (the vehicle Calendar shipped
  as a bookings list instead of the approved five-view calendar). Every
  approved view is built to the mockup, section for section. Before
  calling a UI change done, open the mockup and the build side by side at
  the same width and walk every view, then record the comparison. Views
  not yet built stay unreleased; don't substitute interim content for them.

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
19. **Attachment and selector coverage** (approved by Stephan 2026-09-20) —
    flag plain visible file inputs or bespoke upload chrome for document /
    invoice / evidence fields instead of the shared premium pattern; missing
    type/size/count guidance or false "uploaded" states; and growing record
    directories rendered as unsearchable selects. Check keyboard access,
    error/retry/selection retention and scoped search against
    `design_styles/POPUP_STYLE_GUIDE.md`. These are conformance targets,
    not a claim that every existing caller already meets them.
20. **Planning-date consistency** (approved by Stephan 2026-09-20) — check
    operational date/range forms against the shared calendar-selection pattern,
    visible range summary, module-specific required/optional states, separate
    times/timezone where applicable, error focus and retained entries in
    `design_styles/POPUP_STYLE_GUIDE.md`. Flag copied HR policy, silent date
    shifts and a calendar selection presented as a confirmed booking.
21. **Time-entry consistency** (approved by Stephan 2026-09-20) — check operational
    time fields for clock and manual entry, exact-minute retention, correct
    noon/midnight conversion, labelled keyboard controls, invalid-input feedback,
    Cancel/Apply and picker-first Escape/focus. Check timezone/interval rules,
    Review/Back and failed-save retention, and a visible action footer against
    `design_styles/POPUP_STYLE_GUIDE.md`. A styled picker does not prove DST,
    persistence or scheduling conformance, or authorise a shared-component edit.
22. **Work-record detail consistency** (approved by Stephan 2026-09-20) —
    check suitable owned-action detail pages against
    `design_styles/WORK_RECORD_STYLE_GUIDE.md`: stable identity/source links,
    summary/action hierarchy, compact recoverable notes, accountable ownership,
    module-owned progress and an obvious guarded completion path. Flag copied
    domain statuses, forced Ticket terminology, hidden essential blockers and
    parallel task/evidence identities. This is not a bulk migration instruction.

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
- `design_styles/WORK_RECORD_STYLE_GUIDE.md` — ticket-style work/issue detail hierarchy with module-owned terminology and lifecycle
- `design_styles/BUTTON_STYLE_GUIDE.md` — the soft-depth button spec (primary/outline)
- `design_styles/NAVIGATION_STYLE_GUIDE.md` — two-tier section nav (connected tab + toned strip)
- `design_styles/LOADER_STYLE_GUIDE.md` — the Event Horizon brand loader (+ ring-only inline variant)
- `design_styles/PAGE_HEADER_STYLE_GUIDE.md` — the Event Horizon page header (index + profile variants, in-header search/filters, rail, sub nav)
- `design_styles/LIST_STYLE_GUIDE.md` — the Event Horizon list contracts (entity card grid + entity table, cell library, kebab/context-menu rules)
- `resources/css/app.css` — the tokens themselves (source of truth)
- `resources/js/lib/status-colors.ts` — status → class map
- `resources/js/lib/derive-palette.ts` — brand colour → derived palette
# User-authorized additions · 22 September 2026

- Maps and cross-profile geofencing follow [MAP_GEOFENCING_STYLE_GUIDE.md](design_styles/MAP_GEOFENCING_STYLE_GUIDE.md), based on the inspected Client Location implementation. Greyscale base tiles, coloured overlays, canonical shared boundaries, separate profile assignments and separate monitoring authority are required.
- Structured form choices follow the searchable catalog and interval amendment in [POPUP_STYLE_GUIDE.md](design_styles/POPUP_STYLE_GUIDE.md). Service types and service interval presets use searchable choices with explicit **Add custom** where the field represents a configurable catalog. Service recurrence is expressed in calendar months by default, with distance as an independent trigger.
- Vehicle checks require a reusable library owned with Maintenance checklists, editable questions and evidence requirements, profile/category assignment, controlled versions, and immutable submitted question/answer snapshots. A changed template must not rewrite previous checks. Workflow previews demonstrate configuration; approval of operational checklist content remains with its responsible owner.
