# The page kit — templates, layouts and blocks for every staff page (approved by Stephan 2026-10-20 / 21)

Every staff page is the same five parts: **top bar · sidebar · breadcrumbs · the
header (the sky) · the body**. The body uses one of six layouts and is built only from
the kit's blocks. A new module picks a template for each page and fills it with its
own words; it never invents a new page shape. Drawn in light and dark in the design
viewer (The page kit: claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy).

Related rules: the header geometry and the one-header-per-template rules are in
`PAGE_HEADER_STYLE_GUIDE.md`, cards and tables in `LIST_STYLE_GUIDE.md`, tabs in
`NAVIGATION_STYLE_GUIDE.md`, pop-ups in `POPUP_STYLE_GUIDE.md`, and words in
`WRITING_STYLE_GUIDE.md`.

## 1. The 13 templates — pick one for every page

| # | Template | Use when | Layout | Header |
|---|---|---|---|---|
| 1 | **Hub** | A module's home: up to 6 numbers, what needs attention, the module's areas | Main + side | Full |
| 2 | **List** | Records in a table (or cards when a picture helps); filters and search in the header | Full width | Full |
| 3 | **Record** | One person or thing: identity, status and main action up top; tabs for the rest | Main + side | Full |
| 4 | **Workspace** | A queue on the left, the selected item on the right | Split | Compact |
| 5 | **Calendar** | Month / week / day / timeline; the event opens in a side panel | Full width | Compact |
| 6 | **Map** | A list beside a map; picking in either moves the other | Split | Compact |
| 7 | **Board** | Columns of cards along a path (a pipeline, a work queue) | Full width | Compact |
| 8 | **Dashboard** | Numbers and charts; every chart opens the list behind it | Full width | Compact |
| 9 | **Settings** | A settings menu and config cards with switches (the Fleet pattern) | Settings | Compact |
| 10 | **Builder** | A full-screen designer: tools, canvas, inspector | Builder | None |
| 11 | **Document** | Read a policy, report or printout; facts and versions beside it | Main + side | Compact |
| 12 | **Full-page form** | Legacy create / edit pages only. New work uses a pop-up | Full width | Compact |
| 13 | **Outside page** | No staff shell: sign-in, the public site, careers, the family portal | Outside | The sky as hero |

A page that does something no template covers takes the nearest one. A new template is
a design decision for Stephan, never a module's own.

## 2. The six layouts

- **Main + side:** content plus a 360 px side column.
- **Full width:** never capped; the body fills the screen.
- **Split:** a 420 px list plus the rest.
- **Settings:** a 220 px menu plus config cards.
- **Builder:** tools 56 px · canvas · inspector 300 px.
- **Outside:** its own slim header with the brand slot.

One 20 px gutter (`p-5`) and 20 px between sections and cards on every layout.

## 3. The blocks — the body is built only from these

**Kit blocks:** table · cards · list · facts (key–value) · chart · empty (whole page) ·
calendar · map · board · settings card · form · note (a box) · builder · document.

**The Oblivion blocks (approved 2026-10-21) — use these instead of generic tiles:**

| Block | Use for | Instead of |
|---|---|---|
| **Tally** | Numbers that belong together, on one line between hairlines; each label carries its state ring; a zero folds into a sentence ("Nobody needs attention") | A row of stat tiles |
| **Ring** | Progress: the ring closes; complete is the ring closed with a centre; an optional checklist read by its rings | A percentage tile or progress bar per row |
| **Journey** | Ordered steps or stages, as stations on the brand line; the caption names who coordinated it | Numbered step lists, status timelines |
| **Log** | Anything time-ordered (activity, notes, alerts, history): the time first, then the state ring, then the words | Icon-led event lists |
| **People** | People cards: presence ring on the avatar, their day as a short Journey line | Avatar + name + pills card grids |
| **Places** | Houses and Sites: beds as rings, who is on shift now, the one thing that needs doing | House card grids |
| **Quiet** | A small empty: one line with the action in it | An empty-state illustration in a small card |
| **Point** | A chart that makes one point: values written on, the latest in the brand, one sentence on what changed | A bare bar chart |

**The ten block rules.**
1. A number appears once on a page: in the header or in the body, never both.
2. No tile per number. Numbers that belong together share one line; a zero is a sentence.
3. People are never numbers. Name them where the work is, with their presence ring.
4. Progress closes the ring, steps are the Journey line, and every chart makes one point.
5. No coloured side borders. A surface that matters carries the horizon line on its top edge.
6. Icons tell kinds apart; rings tell state. No icon on a row just because there is room.
7. A card tells its thing's own story (a person's day, a house's beds), not a template of icon, title and pills.
8. A small empty is one quiet line. The islands are only for whole empty pages, first run and success.
9. A box (note) only for a rule that changes what you can do now: a safety stop or a legal step.
   A line that only restricts who can see goes in the next block's sub-line. Safety,
   privacy and consent boxes always stay boxes, word for word.
10. The brand colour means "you, now, act". Structure stays slate; not every icon and chip is purple.

## 4. Tabs

The first level of tabs lives in the header's rail. A second row appears only under a
tab. There is never a third level: a third level becomes its own page. A record's tabs
group its content so a page rarely runs past three screens.

## 5. Rows and tables

- Every row has a ⋮ menu, and right-click opens the same menu.
- Clicking the row opens the record.
- A pager sits under every table. After about 25 rows the pager takes over.
- Column headings stick inside their card while the table scrolls.
- Ticked rows bring up the selection bar (pop-up kit).
- Full detail is in `LIST_STYLE_GUIDE.md`.

## 6. Long pages

- **When the header scrolls away,** a slim bar stays under the top bar: the mark, title,
  tabs and main button, with the horizon line under it. Records keep the back arrow.
- **The side column** follows while the main column scrolls, when it fits the screen.
- **Settings** pin the save bar to the bottom as soon as something changes.
- **Pop-ups** keep their header and footer; only the body scrolls.

## 7. States

Every page and block has its loading, couldn't load (with Retry), no access and nothing
yet states, using the kit's empty and quiet blocks and `LOADER_STYLE_GUIDE.md`. A failed
load is never shown as an empty list. A number the viewer can't see reads "Not
available", never 0.

## 8. Forms and builders

Create and edit forms are pop-ups from the pop-up kit (Form or Wizard). The only
full-screen editing surfaces are builders (a plan, page or report designer). A legacy
full-page form is a migration target, not a precedent.

## 9. Settings pages

Settings follow the Fleet pattern:
- the 220 px settings menu;
- config cards with Switch toggles and one line of help each;
- the save bar only when something changed, pinned to the bottom;
- secrets (API keys, client secrets, passwords, PINs) always masked, with an audited reveal where one exists.

## 10. Brand on every page

- Brand colour comes only through `--primary`: the sky, tints, the active tab, the main button.
- Every logo spot is the brand slot. Never a hard-coded logo, client name or colour.
- Check every new page with a second brand colour as well as the default.
- Status and safety colours never follow the brand.

## 11. Outside pages

Sign-in, account, the public site, careers and the family portal use the Outside
layout with the brand slot:
- The public site is the client's own website, never Oblivion marketing.
- The family portal shows only what is shared with consent, and keeps every "shared with you" and consent line word for word.

## 12. Before a new module ships

- Every page has a template from §1 and a layout from §2.
- Every header follows the one-header-per-template rules (`PAGE_HEADER_STYLE_GUIDE.md` §11).
- The body uses only §3 blocks and keeps the ten block rules.
- Every pop-up is a kit type at a kit width (`POPUP_STYLE_GUIDE.md`). Long dropdowns are searchable.
- Every word follows `WRITING_STYLE_GUIDE.md`.
- Light and dark, the default brand and a second brand colour are checked.
- The new pages are compared side by side with an existing module's pages of the same template: same header shape, same blocks, same words.
