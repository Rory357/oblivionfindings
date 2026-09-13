# Page header — the "Event Horizon" header (approved 2026-09-05, meter-row revision approved 2026-09-06)

The single source of truth for the top of every page. Two variants of
one fixed-anatomy header: the **index/list header** and the **record
profile header**. Every page redesign starts here; no other hero/banner
pattern is current, and no other layout is permitted for a page top.

**Status:** APPROVED + IMPLEMENTED (2026-09-06, meter-row revision
included). The component is
`resources/js/components/page/page-header.tsx` (band, top row, the
meter-block family `PageHeaderMeterBlock` / `…Big` / `…Bar` / `…Spark`
/ `…Donut` / `…Delta` / `…Caption`, filter pieces, rail) with the
`.eh-header` / `.eh-mark-ring` / `.eh-meter` utilities in
`resources/css/app.css`. Reference migrations: `pages/sites/index.tsx`
(index variant) and `pages/sites/show.tsx` +
`pages/operations/clients/show.tsx` (profile variant — avatar/initials
ring mark, palette-backed `PageHeaderSearchTrigger`, group rail +
tier-2 strip). Visual reference: `public/eh-hero-variants.html`.

**Supersedes** (2026-09-05): the `PageHero` banner system
(`components/page/page-hero.tsx` and its ~559-page rollout), the
Governance Hero Guide (deleted), `docs/hero-unification-v2-plan.md`
(deleted), `docs/hero-unification-v3-handoff.md` (kept for a security
runbook reference only), and the client/site profile heroes — both
migrated + deleted along with their alert ribbons (sites 2026-09-06,
clients 2026-09-07; alert counts fold into the meter row, and the
client vitals strip's meal/sleep/mood detail lives on its Health
monitoring tab). The meter-row revision
additionally supersedes this guide's own v1 right-column anatomy
("orbit" stat pills row, fact chips row, alert chips row) and the
`.eh-stat-pill` utility — remove them with the component rework.

All hex/rgba values below are mockup refs at the default brand.
Implementation is **tokens-only** (DESIGN.md non-negotiable #1): every
brand-derived value is expressed via `--primary` + `color-mix`/`oklch`
so the header retints from Settings → Branding, and dark mode stays
automatic.

---

## 1. The one big idea

One fixed "sky band" opens every page — a surface that runs from a dark
shade of the branding colour down into the branding colour itself (the
event horizon). Inside it, top to bottom:

1. **Top row** — identity (ring mark, title + status chip, one fact
   subline) left; scoped search + actions right.
2. **The meter row** — one full-width row of instrument blocks
   carrying the page's important numbers. Any number of blocks
   (4–6 recommended); **every block is a link to its view**.
3. **Filter row** — the page's compact filter pills, right-aligned.
4. **The rail** — the page's main view tabs on the band's bottom edge
   as the Rule 1 connected-tab rail.

Consistency is the point: the band's row rhythm, slot layout, and
height never change between pages — only the slot *contents* (title,
blocks, filters, tabs) do.

## 2. Geometry (fixed on every page)

- Band: **~250px** tall, radius **16**, `overflow: hidden`, **no
  shadow** (the connected-rail rule — a shadow breaks the active tab's
  merge with the page ground). A §5 two-row profile grows the band by
  one meter row; everything else keeps this rhythm.
- Inside, top to bottom (padding `18px 22px 0`):
  - top row (~48px): identity left, search/actions right;
  - **13px** gap, then the meter row (**min-height 80px**, fixed so
    the band height matches across pages whatever the block mix — a
    96px trial on 2026-09-06 was reverted the same day; block padding
    `8px 12px 9px`, and the block's inner rows spread to fill its
    height — `align-content: space-between`, head top, visual middle,
    caption bottom — never top-clustered over dead space);
  - **10px** gap, then the filter row (**23px**), then 12px;
  - **46px rail** (padding `0 14px`) on the bottom edge.
- **Above the band: the shell breadcrumb strip** (added 2026-09-06,
  reinforced 2026-09-07). Every page passes a full trail to `AppLayout
  breadcrumbs` **rooted at Home (`/dashboard`)** — no exceptions. This
  is a MANDATORY step of every page migration: add the Home-rooted trail
  and verify it before ticking the page done (DESIGN.md anti-pattern
  "Missing or non-Home-rooted breadcrumbs"). Trails read
  Home → Sites, Home → Sites → Aurora House,
  Home → Sites → Reports → Houses — and the shell renders it as its slim
  muted-foreground strip (`components/breadcrumbs.tsx`). Spacing: **10px above AND 10px
  below the crumbs** (approved 2026-09-06, revised down from a 15px
  trial the same day — the strip's one exception to the 20px shell
  rhythm; the shell's content wrapper drops its top padding beneath
  the strip so nothing stacks on it). The strip is
  shell chrome, never part of the band; the band never renders its own
  breadcrumbs. A single-crumb trail stays hidden (it would duplicate
  the band's title).
- Below the band:
  - **Index/list pages: nothing.** Content starts after the standard
    20px gutter. No sub-bar, no filter bar, no tab strip — those all
    live inside the header.
  - **Record/profile pages: one 36px sub-nav strip** (tier-2, §7),
    then content.

## 3. The sky surface (branding-derived — non-negotiable)

The band's colours ALWAYS derive from the branding colour set in
Settings → Branding: **the bottom of the band is the actual branding
colour, and the top is a darker shade of that same colour, fading down
into it.** Never a neutral/grey/ink top, never a hand-picked palette —
whatever colour branding sets, the band is that colour's own
dark-to-true ramp. Layered, top to bottom:

1. Linear gradient of the brand colour's own ramp:
   `oklch(from var(--primary) 0.3 calc(c * 0.6) h)` (top) →
   `oklch(from var(--primary) 0.42 calc(c * 0.8) h)` (55%) →
   `var(--primary)` (100%). Relative-colour oklch keeps the brand HUE
   in the dark shades, so the ramp retints automatically. (Lightened
   2026-09-07 from the original L 0.22/0.34 ramp — the top stays a
   *dark* shade, just softer; white header text must stay AA.)
2. Two radial glows rising from the bottom edge: a bright one
   (brand lightened ~25% toward white, alpha .92) bottom-right, a
   supporting one (brand, alpha .55) bottom-left — the "horizon" fade.
3. Two hairline decorative **orbit arcs** (giant circles, brand
   lightened ~45%, alpha .2 / .14), one swinging low-left, one
   high-right. `pointer-events: none`.

**Safety colours never retint** (non-negotiable #6): critical/warning
accents anywhere in the band use the fixed `status-critical` /
`status-warning` tokens (lightened toward white for AA on the dark
sky); success uses `status-success`. Only neutral/brand accents derive
from `--primary`.

## 4. Top row — identity (left) + actions (right)

Identity, left (13px gap to the mark, 3px stack gap):

1. **Mark** (48px, always circular):
   - List: the module icon inside an Event Horizon **ring** — 3px
     border of brand-25%-white, outer glow (brand .5) + inner glow
     (brand .35). Same family as the loader ring
     (LOADER_STYLE_GUIDE.md).
   - Profile: the record's avatar/initials inside the same ring.
   - Profile only: a 32px glass **back chip** (radius 10, white/8 fill,
     white/20 border, chevron-left) sits before the mark.
2. **Title row**: 22px/700 white title + one `<StatusBadge>`-language
   chip (e.g. "13 active", "Active") using the status token pair.
3. **Fact subline**: 13px muted (`rgba(255,255,255,.65)`):
   - **Index pages — ONE line**: the page description and/or key facts
     joined with middle dots — e.g. "Occupancy, leads and safety ·
     3 regions · 28 clients supported".
   - **Record profiles — TWO stacked lines** (revision 2026-09-06):
     line 1 is the record's **address** (all site types — house, head
     office, facility); line 2 is what the place IS — record type ·
     support category(ies) from its active service contexts ("Respite",
     "Residential") · region, middle-dot joined, empties dropped.
   - Never a greeting, never conversational (DESIGN.md anti-patterns).

There are **no fact-chip or alert-chip rows** (v1, removed 2026-09-06):
facts fold into the subline; alert counts live in the meter row where
they are clickable.

Actions, right (36px controls, radius 10):

- **Scoped search field** — ALWAYS inside the header, scoped to the
  section: "Search sites, regions, leads…" on an index, "Search this
  site…" on a profile. Glass (white/9 fill, white/18 border), leading
  search icon, trailing `/` kbd hint. The global ⌘K command search
  stays in the top bar — the two never merge.
- Glass secondary buttons (white/10 fill, white/20 border) — e.g.
  "Select", icon-only chat/edit/overflow chips on profiles.
- Exactly **one white primary button** (white fill, brand-dark text):
  "Add site", "Add note ▾", … Never two primaries.

## 5. The meter row — the page's instruments (full width)

**Calendar date anchor (approved 2026-09-13):** calendars use the Site
Calendar implementation and place a prominent date anchor in the first
meter: large viewed day number, full month and four-digit year, with the
weekday/full date or week range below. The period's entry count is the
secondary value. This uses the existing meter surface and header rhythm;
do not add a separate banner. See `CALENDAR_STYLE_GUIDE.md`.

One row spanning the band, holding as many blocks as the page needs —
**4–6 on desktop** (blocks `flex: 1 1 0` share the row; below desktop
the row wraps). This row replaces the v1 stat pills entirely and is
where every page surfaces its important, relevant numbers.

**Two-row variant** (approved 2026-09-07): a record profile whose
module genuinely needs more than ~6 instruments splits them into
**two deliberate full-width rows** — never one cramped 7+ block line.
Each row is its own `min-h-[80px]` flex line inside the `meters` slot
(`empty:hidden` so a permission-emptied row collapses) and the band
grows to fit; the split is thematic, not arbitrary. Reference:
the Client Profile (`pages/operations/clients/show.tsx`) — row 1
"Next shift · Needs attention · Safety · Care plan goals · Daily
notes", row 2 "Meals today · Mood · Sleep · Medications". **Dense
module hubs qualify too** (extended 2026-09-08): an index page whose
module genuinely carries more than ~6 important instruments splits the
same way rather than cramming one tight line — reference IT & Support
(`pages/it/index.tsx` via `components/it/it-hero.tsx`): row 1 "Open ·
Unassigned · Breaching soon · Breached" (queue pressure), row 2
"Awaiting reply · Waiting · SLA met donut · Provisioning" (flow &
delivery). Ordinary index pages with ≤6 blocks stay on the single row.

**Block surface**: radius 10, dark glass (`--eh-ink` at 45% alpha),
1px border. Neutral blocks take a brand border (brand-25%-white at
.45) with white values; toned blocks take the fixed status-toned
border and tint their label AND headline value. **No glow** — the
toned border alone is the accent.

**Block anatomy** (always these three slots, spread across the
block's full height):

1. **Head**: 10px/600 uppercase letter-spaced label, left; optional
   headline value 13px/700 tabular, right.
2. **Visual** (or the big number): one of the five types below.
3. **Caption**: 10.5px muted context line ("across 3 regions",
   "0% occupied · 41 available") or a toned delta ("▲ 4 this week").

**The seven block types** — a page composes from these only:

| Type | Visual | Use for |
|---|---|---|
| Stat | 20px/700 big number | a plain count |
| Delta stat | big number + toned ▲/▼ delta | a count with week/month movement |
| Bar meter | 5px progress bar (radius 3) | progress toward a capacity (occupancy, funding) |
| Donut | 34px ring + centred % | share of a whole, with the fraction in the caption |
| Sparkline | 7 bars (4px, radius 1.5) + delta | a 7-day trend on an alert count |
| Avatar stack | overlapping 26px faces (photo or initials), "+N" overflow disc, hover card with name + one detail line | the people at a record (residents, attendees, clients) |
| Key contacts | up to 3 compact rows — uppercase role label + "Name · phone"; a missing contact reads **"No info"**, never disappears | a record's operational contacts (manager, site lead, after hours) → the contacts view |

**Graph-first rule** (reinforced 2026-09-06): a block renders a VISUAL
whenever its number has an honest visual form with a live backend
source — capacity → bar meter, share of a whole → donut, daily series →
sparkline, people → avatar stack — with the count moved to the head
value. The plain stat is the fallback for numbers with no visual form,
never the default. (Still bounded by the no-fake-data rule: a visual
whose series/fraction has no real source is dropped, not invented.)

The avatar stack tightens its overlap as it grows and hovering a face
lifts it (transform-only) and shows a tooltip card — name plus one
short detail line (e.g. "Active · Room 2"). The people COUNT lives in
the head value, never repeated inside the stack. **Two-level click**
(approved 2026-09-06): the block still navigates to its view (the
people tab), but a click on an individual face stops there and
deep-links to that person's own profile — the tooltip says so.

**Tones**: `neutral` (brand) · `critical` · `warning` · `success` —
the safety tones are the fixed status tokens lightened for AA on the
sky, never brand-derived. Deltas colour by *meaning* (a falling
overdue count is a green ▼), not by direction.

**Every block is a link — no exceptions.** Clicking a block navigates
to the view where that number lives: Hazards → the open-hazards view,
Occupancy → beds, Checks on time → audits, Incidents → the incidents
list… (a rail tab, a filtered index, or a module page). Hover
affordance: 1px lift, brighter border (toned blocks brighten in their
tone), and a corner **↗** arrow fading in (11px, top-right). Blocks
render as `<a>`/`<Link>` with an accessible name ("View open
hazards").

Trend deltas, donut fractions and sparkline series need real backend
numbers — never ship a block whose data source doesn't exist yet; drop
the block instead.

## 6. Filter row

Right-aligned, below the meter row; every field is one **23px** box
(`box-sizing: border-box`), radius 8, glass fill, 11–12px text.
Dropdown chips (label + chevron, optional 10px leading icon), a
checkbox chip ("Archived"), and/or a segmented view toggle (glass
container, white active segment radius 6 with brand-dark text).
**The page decides the pills, never the position**: a list gets
types/regions/status/archived + Cards–Table; a profile gets
date-range/staff; a dashboard gets whatever it filters by. All fields
in the row share the same height — no odd one out.

**Every rail view carries its own pills** (reinforced 2026-09-08): on
a page whose rail switches views, EACH tab supplies real filter pills
for its own content — a queue gets its queue filters, a report view its
range, a catalogue its category, a board a priority narrow. The filter
row never sits populated on one tab and empty on the next (the band
visibly collapses and the rhythm jumps); if a view truly has nothing
filterable, give it its honest control (a range, a scope) rather than
none — and never a dead pill that filters nothing. Reference: IT &
Support, where all seven views keep the row live.

## 7. The rail — main view tabs (bottom edge)

The page's primary view/filter tabs render here as the **Rule 1
connected-tab rail** (NAVIGATION_STYLE_GUIDE.md):

- Active tab: 40px, page `--background` fill, brand-dark text,
  `border-radius: 12px 12px 0 0`, flush with the band's bottom edge —
  the merge with the page ground is the affordance.
- Inactive tabs: 34px text pills (white/78), radius 9, `margin-bottom:
  6px`.
- **One optical text line — a permanent invariant** (reinforced
  2026-09-06, re-verified 2026-09-07): every rail label's centreline
  sits **exactly 23px above the band's bottom edge**, active and
  inactive alike — one horizontal line through all of them, on every
  page, always. The active tab is taller than the inactive pills, so
  without compensation its label would sit ~3px lower; the active tab
  carries `padding-bottom: 6px` so the centrelines coincide — inactive:
  34/2 + 6px margin = 23; active: (40 − 6)/2 + 6px padding = 23. This is
  measured, not eyeballed (repro on 2026-09-07 read both labels at 23.00px,
  Δ = 0.00px). **Do NOT "correct" the active label by eye**: on the white
  active tab a dark label reads as sitting slightly high next to the light
  labels on the sky — that is an optical impression, not a real offset, and
  nudging it with an ad-hoc margin/translate BREAKS the equation and the
  shared rail everywhere. The alignment lives ONLY in the shared
  `PageHeaderRail` (`components/page/page-header.tsx`) — never per page;
  any change to rail heights, margins, or padding must re-solve the
  centreline equation to keep both sides at 23px, and be re-measured.
- Counters follow the **counter state-colour rule** (radius 6): active
  tab → brand-tint pair; inactive → white/15 on white; alert counts →
  the fixed critical pair. A counter must never disappear when its tab
  activates.
- **The Find chip — on EVERY rail** (reinforced 2026-09-06; made
  mandatory 2026-09-08): every page that renders the rail ends it with
  the compact ghost "⌕ Find /" chip, exactly as the Sites profile does —
  inline with the tabs at the row's far end (`margin-left: auto`), sized
  like an inactive pill (34px, radius 9, 6px bottom margin). It opens
  the page's jump palette: the grouped section palette on record
  profiles (`TabSearchPalette`, via `PageHeaderRail onFind` — `/` opens
  it there), or the rail's own BUILT-IN views palette everywhere else
  (rendered by `PageHeaderRail` automatically when no `onFind` is
  passed). `/` ownership: on pages whose header carries the scoped
  search input, `/` stays with the search — the built-in chip opens on
  click and shows no kbd hint.
  Quick section-finding is part of the rail, not a separate bar — and a
  rail without the Find chip is a migration gap, not a variant. (Pages
  with no rail at all — leaf record details — are the only exemption.)

## 8. Sub nav — individual/record pages only

One bare 36px strip on the page ground under the band (never a card,
border, or shadow), per NAVIGATION_STYLE_GUIDE.md Rule 2 with the app
anatomy:

- **Inactive tabs are ghosts**: transparent — icon + label sitting
  directly on the page background, neutral text (`#3f4050` ref), plain
  outline icon.
- **Active tab**: tone-tinted pill (radius 9), label in the deep tone
  (semibold), the icon flips into an **18px solid deep-tone circle
  with a white glyph** (the `--tone-chip-*` treatment), and a **3px
  tone underline bar** (radius 2, ~60% pill width) under the pill.
- **Tone by position**, never hand-picked: violet → teal → green →
  amber → rose (`index % 5`). Position 0 is `--primary`-derived (so it
  retints); the others are the fixed `--tone-teal`/status tokens.

Lists do NOT get this strip — their scoping controls are the header's
filter row and rail.

## 9. Radius scale

| Radius | Where |
|---|---|
| 16 | the band |
| 10 | search field, action buttons, meter blocks, profile back chip |
| 9 | rail inactive pills, sub-nav pills |
| 8 | title-row status chip, filter fields |
| 6 | counters, view-toggle active segment |
| 3 | bar-meter track/fill |
| circle | ring-O, avatar ring, donut, sub-nav icon discs, orbit arcs — nothing else |

No full-capsule (`999px`) pills anywhere in the header or sub nav.

## 10. Implementation notes

1. **One `PageHeader` component** — `components/page/page-header.tsx`
   owns the geometry, surface, rail, and the §2 stacked rows as slots;
   pages supply slot content only, composed from its exported pieces.
   The `meters` slot renders `PageHeaderMeterBlock` links (`tone`,
   `href` or `onClick`, accessible name) whose bodies compose from
   `PageHeaderMeterBig` / `…Bar` / `…Spark` / `…Donut` / `…Delta` /
   `…Caption`. A `variant="index" | "profile"` switch covers the
   mark/back-chip and sub-nav differences — never fork the band per
   page.
2. Tokens only: express the sky, glows, ring, borders and tints via
   `--primary` + `color-mix`; safety accents via fixed status tokens.
   Verify AA for white-on-glow text at extreme brand hues (amber is
   the harshest test).
3. The sub-nav strip is the existing `TierTwoTabs`
   (`components/page/grouped-profile-nav.tsx`) restyled to §8 — one
   enforcement point, not a new component.
4. Migration: every page currently on `PageHero`, the client/site
   profile heroes, a below-header filter bar, or the v1 header rows
   moves to this layout. Tracked as DESIGN.md conformance probe 12.
5. Visual reference: `public/eh-hero-variants.html` (Sites + Clients
   examples and the block library). Its trend/compliance figures are
   placeholder data.
6. Existing rules still bind: lucide icons only, `<StatusBadge>`
   semantics, typography helpers, 20px gutter/gap rule, motion budget,
   reduced-motion, density.
