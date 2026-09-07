# List surfaces — the Event Horizon card & table contracts (approved 2026-09-06)

The single source of truth for how records are LISTED anywhere in the
app: the **entity card grid** and the **entity table**. Both are
entity-agnostic — sites, clients, incidents, staff, assets, meetings,
budgets, anything listable rides the same two shells, and only the
slot/column contents change per entity. Companion to
[PAGE_HEADER_STYLE_GUIDE.md](PAGE_HEADER_STYLE_GUIDE.md): lists sit
under the Event Horizon header, and the Cards–Table view toggle lives
in that header's filter row — never in the list itself.

**Status:** APPROVED + IMPLEMENTED (2026-09-06). The components live
in `resources/js/components/lists/`: `EntityCard` + `EntityCardGrid`
(`entity-card.tsx`), `EntityTable` (`entity-table.tsx`), the cell
library (`entity-cells.tsx`), the shared kebab + right-click context
menu with the `MenuItem[]` contract (`entity-menu.tsx`), and the
section caption row (`list-caption.tsx`). Reference migration:
`pages/sites/index.tsx`; `pages/operations/clients/index.tsx` migrated
2026-09-07. Canvas reference (page "Event Horizon", second
row — the card-lists and table-lists artboards show sites, clients and
incidents riding the same shells):
https://claude.ai/code/artifact/54948d2c-b736-4399-ad36-eb01b1e71163

Hex values are mockup refs at the default brand. Implementation is
**tokens-only**: brand-derived accents (icon tiles, avatars, progress
fills, links, hover tint) come from `--primary` via `color-mix`;
status colours are the fixed status token pairs (non-negotiable #6).

---

## 1. Shared rules (cards AND tables)

- Surfaces are **white cards on the grey ground**: `--card` fill,
  hairline border (`#cfd0d5` ref), soft shadow
  (`0 1px 3px rgba(16,17,26,.06)`), radius **14**.
- Each list is preceded by a **section caption row**: title (14/650) +
  "N of N shown" caption, optional grouping chip on the right.
- 20px between cards and between sections (the spacing rule).
- **Actions are non-negotiable:** every card and every row carries the
  **kebab menu** AND the **right-click context menu**, both fed by the
  SAME `MenuItem[]` — exactly as `pages/sites/index.tsx` and
  `pages/operations/clients/index.tsx` do today (edge-clamped
  positioning, Esc/scroll/click-away closes). A migration that drops a
  menu item or one of the two entry points is a regression.
- **Empty values are honest:** muted `—`, muted person disc +
  "No site lead" / "No key worker". Never fabricate data.
- **No readiness or onboarding displays** (removed 2026-09-06) — no
  readiness rings/columns, no onboarding bars/columns.
- Radius scale follows the header guide: 14 shell / 10 icon tile /
  8 chips / 6 counter pills; circles only for avatars and person
  discs.

## 2. The entity card

Anatomy, top to bottom — the skeleton is fixed; entities fill slots:

1. **Status meridian** — a 3px full-width bar across the card top,
   coloured by the record's **worst ALERT state**:
   `status-critical` (any critical alert) → `status-warning`
   (warnings only) → `status-success` (all clear). Record *status*
   (Active/Respite/…) does **not** drive it — a respite client with no
   alerts gets green.
2. **Identity row** — the **mark slot** (40px: brand-tinted icon tile,
   radius 10, for places/things; circular avatar or initials disc for
   people) + name (15/650) + one muted sub-line (location, or
   id · age) + the kebab.
3. **Fact chips row** — neutral chips (muted fill, radius 8,
   11.5/500, optional 10px icon); the record's status chip
   (`<StatusBadge>` pair) may lead this row.
4. **Metric slot** (at most one, optional) — 10px uppercase label +
   value line + 6px progress bar (brand fill, muted track). The
   entity decides the metric (e.g. sites: occupancy). Omit entirely
   when the entity has none.
5. **Alert/safety chips row** — status-pair chips (radius 8,
   11.5/600): "2 hazards", "19 overdue", "All clear".
6. **Utility footer** — a muted strip (top hairline, `#f7f8fa`-ref
   fill) bleeding to the card edges: 24px person disc (brand tint +
   initials; muted person icon when empty) + primary line (12/600) +
   context sub-line (10.5 muted, "Site lead · 6 clients") + a
   right-aligned brand **Open →** link.

Card grids: `grid gap-5`, equal-height rows; 3-up at desktop width.

## 3. The entity table

One reusable table component for **every table listable in the
project**, configured per entity by a column spec — never restyled
per page.

- **Shell**: one white card (§1 treatment), `overflow: hidden`; wide
  tables scroll inside their own `overflow-x-auto` — the page body
  never scrolls horizontally.
- **Header row**: 32px, muted fill (`#f7f8fa` ref), bottom hairline,
  labels 10/600 uppercase letter-spaced muted.
- **Rows**: 50px, hairline between rows, row hover = ~5% brand tint.
  The **identity cell is always first**; the **kebab cell is always
  last** (32px). Row click opens the record; kebab/right-click carry
  the actions.
- **Identity cell**: 30px mark (icon tile radius 8, or avatar circle)
  + name (13/600) + muted sub-line (11.5).
- Every other column picks from the **cell library** — never invent a
  new cell per page:
  | Cell | Anatomy |
  |---|---|
  | Neutral chip | muted fill, radius 8, 11.5/500, optional icon |
  | Status chip | `<StatusBadge>` pair, 5px dot + label |
  | Counter pill | 20px tall, radius 6, min-width 22, status pair, 11/700 |
  | Progress + value | 6px bar (brand or status fill) + 12/600 text |
  | Person | 22px disc (brand-tint initials; muted icon when empty) + name; muted `—` when unassigned |
  | Plain text | 12.5, `#2a2b3a` ref; muted `—` for empty |
  | Emphasis text | e.g. vacancy count in `status-success` |
  | Open link | brand link + arrow (rows without a kebab-only pattern) |
- **Column spec**: the component takes per-entity columns as
  `{key, label, cell, width(fr)}` and the row data; widths in `fr` on
  a CSS grid. New entities add a spec, not a fork.
- Implementation sits on the existing primitives:
  `components/ui/table.tsx` (or a grid-row equivalent with the same
  semantics), `components/ui/laravel-pagination.tsx` for paging,
  `skeleton-table` for loading.

## 4. Implementation notes

1. **One `EntityCard` and one `EntityTable`** — built in
   `components/lists/`, owning geometry, the meridian logic, and the
   cell library; pages supply slot/column config, row data, and the
   `MenuItem[]`.
2. The context-menu behaviour is the shared
   `components/lists/entity-menu.tsx` (`EntityKebab`,
   `EntityContextMenu`, `useEntityContextMenu`, `compactMenu`) — do
   not fork it, and verify every existing menu item survives each
   migration.
3. Sites (2026-09-06) and the clients index (2026-09-07) are
   migrated; migrate every other listable next. Tracked as DESIGN.md
   conformance probe 18.
4. All standing rules bind: semantic tokens only, lucide icons only,
   `<StatusBadge>`/status pairs for anything status-like, the 20px
   spacing rule, typography helpers, motion budget.
