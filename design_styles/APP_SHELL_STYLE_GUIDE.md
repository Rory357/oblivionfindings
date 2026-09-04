# App shell — the "Event Horizon chrome" (approved 2026-09-05)

The approved design for the global app shell: the top bar, the sidebar,
and the page ground they frame. This replaces the old light sidebar +
light header starter-kit shell. Design canvas reference (mockup, page
"Shell"): https://claude.ai/code/artifact/5ef121f5-b552-4a1e-ba58-17048c0a23d7

**Status:** designed and approved, NOT yet implemented. Target files:
`resources/js/components/app-header.tsx`,
`resources/js/components/app-sidebar.tsx`, tokens in
`resources/css/app.css`. Until implemented, the code carries the old
shell — this guide is the contract, not a description of current code.

All hex values below are mockup reference values. Implementation is
**tokens-only** (DESIGN.md non-negotiable #1): each hex maps to a token
so the shell retints with branding and dark mode stays automatic.

---

## 1. The one big idea

Header and sidebar share a single near-black ink surface
(ref `#15151f` ≈ `oklch(0.155 0.015 277)`) and read as **one continuous
dark chrome frame** — an L on the top and left — around a light grey
page. The chrome is dark in light mode by design; it is the app's
identity anchor, so page content and heroes stay quiet by comparison.

Layering, darkest → lightest:

1. Chrome ink (`#15151f`) — header + sidebar, one colour, no seam.
2. Page ground — neutral light grey (see §4).
3. Cards — white, lifted.

## 2. Top bar (58px, full width)

Left → right:

- **Wordmark**: the Event Horizon ring stands in for the O
  (ring ref: 21px circle, 3.5px border `#7b7ef0`, soft glow — same
  family as LOADER_STYLE_GUIDE.md), then "blivion" (white, 650) and
  "Care" (muted `#8f91b3`, 450). The product wordmark is
  **"Oblivion Care"** — not "Findings".
- **Workspace/site switcher chip**: dark chip (`#23232f`, border
  `#2e2e3f`), building icon + current site + chevron.
- **Command search, truly centred** (`grid-template-columns: 1fr auto
  1fr` — flex spacers drift off-centre): 430×36px dark input
  (`#23232f`/`#30303f`), placeholder "Search or jump to…", ⌘K kbd hint.
- **Right cluster**, in order: **Report incident** (solid primary
  button — this action always stays in the global chrome), **Clock
  in/out** (outline chip, clock icon), **Messages** (chat icon with
  unread-count badge in **primary violet**), **Notifications** (bell
  with **status-critical red** dot), **user avatar** (opens the user
  menu — the user's identity lives here, not in the sidebar).
- **No "Live"/sync chip in the header.** Explicitly removed. Live/sync
  status belongs on page-level surfaces that need it.

Badge semantics: violet count = conversations waiting; red dot/count =
alerts needing attention. Never swap them.

## 3. Sidebar (264px, ink, collapsible)

Top → bottom:

- **Personal items** (flat, 37px rows): My Day, Overview, Today,
  My Calendar, All Tasks (+ overdue count pill, status-critical pair
  on ink: ref bg `rgba(242,109,117,.16)`, text `#ff8f96`).
  Active item: `rgba(255,255,255,.08)` fill, white text, icon tinted
  light-violet `#a3a5f7`. Inactive: text `#a2a4bc`, icon `#767893`.
- **Module groups — collapsible rows** (36px: icon + label 600
  `#b9bad0` + chevron right/down): Sites & Locations, Operations,
  People & HR, Compliance, Incidents, Governance, Fleet, Finance.
  - Expanded group: **indented text-only sub-items** (31px, text
    `#8f91ad`, padding-left aligns under the group label ≈38px). Sub
    items do not repeat icons — the group header carries the icon.
  - Collapsed group: **alert counts stay visible** on the header row
    (e.g. Incidents "1"). Folding a group must never hide an alert.
- **Settings** pinned to the bottom above a `rgba(255,255,255,.08)`
  divider. No user block in the sidebar footer (moved to header
  avatar).
- Group labels/captions on ink keep AA: caption-grade text no lighter
  than ref `#8e90ae` on `#15151f`.

**Whole-sidebar collapse**: a vertical tab handle on the sidebar's
right edge, vertically centred — ref **12×56px**, same ink as the
sidebar, radius on the right corners only (`0 7px 7px 0`) so it flows
out of the edge as a lip, soft outward shadow, chevron pointing left.
Collapsed: sidebar becomes an icon-only rail, handle chevron flips
right, group icons keep their count badges. The visual is slim, so give
the control an invisible ≥44px hit area and keyboard focus
(`focus-visible:ring-2`).

## 4. Page ground and cards

- Light-mode `--background` is a **neutral mid-light grey** — ref
  `#dcdde0`, i.e. chroma ~0 (drop the violet tint). Chosen after
  iterating: `#f7f8fd` (old, too white/tinted) → `#eff0f2` → `#e8e9eb`
  → charcoal `#313237` (**rejected: too dark**) → `#dcdde0` (final).
  Do not creep back toward tinted white, and do not go dark.
- `--card` stays white. Cards on the grey ground: hairline border (ref
  `#cfd0d5`) + soft shadow (`0 1px 3px rgba(16,17,26,.06)`).
- Page context (page title + date, e.g. "Today · Saturday, 5 September
  2026") lives **in the page**, not the header. Muted text sitting
  directly on the grey ground needs the darker muted step (ref
  `#55566a`) to hold ≥4.5:1 — re-check `--muted-foreground` usage on
  ground-level surfaces during implementation.

## 5. Implementation notes (when asked to build it)

1. Tokens first (`app.css` + `@theme`): retune light-mode `--sidebar*`
   to the ink values (the shell chrome is dark in both modes; dark
   mode keeps its existing near-black family), point the header at the
   sidebar tokens (one chrome surface), and shift light `--background`
   to the neutral grey. `--background` cascades app-wide — sweep
   ground-level muted text, borders, filter bars, tab strips, and
   empty states for contrast after the change.
2. `app-header.tsx`: the §2 anatomy. Messages badge count and bell dot
   follow the counter state-colour rule in DESIGN.md.
3. `app-sidebar.tsx`: §3 anatomy; group collapse state persists per
   user (as the current sidebar state hook does); whole-sidebar
   collapse via the edge tab handle.
4. Keep the ring-O wordmark consistent with LOADER_STYLE_GUIDE.md
   (same ring, tokens-only, reduced-motion safe if animated).
5. Existing rules still apply: lucide icons only, typography helpers,
   `<StatusBadge>` semantics for anything status-like, motion budget,
   density/reduced-motion preferences.
