# PPE & Equipment Register — Design Spec (`/health-safety/ppe`)

**Source of truth:** `docs/ppe-redesign/_design_reference/PPE Register.dc.html` (1032-line interactive React prototype) + `HANDOFF.md`.
This document is the implementation-ready build spec. It captures the prototype's exact visual + behavioural intent, mapped to the codebase's existing H&S gold-standard components and **semantic tokens only** (zero raw hex / oklch / `bg-amber-*` / `border-l-*` in the final build).

> The prototype hard-codes raw `oklch(...)` and a JS `T`/`TONE` palette purely because it runs standalone. **Every one of those values has a token equivalent** — the mapping table in §13 is authoritative. Do not copy the prototype's inline colours.

`TODAY` in the prototype is fixed to **2026-06-20** (all "days until" math is relative to this). The real build computes against `now()`.

---

## 0. Page skeleton & layout

Breadcrumb: `Health & Safety / PPE & Equipment` (AppLayout breadcrumbs `[{title:'Health & Safety', href:'/health-safety'}, {title:'PPE & Equipment', href:'/health-safety/ppe'}]`). Note current code says "PPE Management" — rename to **"PPE & Equipment"**.

Page container in prototype: `max-width:1320px; margin:0 auto; padding:24px 28px 56px; display:flex; flex-direction:column; gap:22px`.
⚠️ **MEMORY override `feedback_full_width_layout`**: the app is full-width — do NOT cap at `max-w-[1320px]`. Use the app's standard full-width page body (the existing page uses `flex flex-col gap-6 p-6`). Keep the `gap-5/6` vertical rhythm between hero → tab strip → table card.

Vertical stack, top to bottom:
1. **Hero** (`HeroShell`) — gradient banner with workflow ribbon, title row + "Add to register", two stat clusters, compliance badges, and a **footer filter band**.
2. **Tab strip** (`TabStrip`) — 9 tabs with live server counts.
3. **Table card** — `rounded-2xl border bg-card shadow-sm overflow-hidden`; contains a `RegisterTableHeader` + the active table.
4. Overlays (rendered at root): context menu, detail modal, wizard modals, toast.

Card radius `14px` (use `rounded-2xl` ≈ matches token `--radius` scale; HANDOFF says cards 14px, modals 16px). Table card shadow `shadow-sm`.

---

## 1. HERO (`HeroShell` + hs-hero-kit)

The prototype hand-builds the hero; the build composes `HeroShell` (gradient + orbs + footer slot are already in the kit). Internal order inside `HeroShell`'s `children`:

### 1.1 Workflow ribbon (top line) — `WorkflowRibbon`
- Five stages, pill-shaped, with a `chevron-right` separator between each.
- Stages (icon, label): **Catalogue** (`Hexagon`) → **Stock** (`Package`) → **Issue** (`User`) → **Inspect** (`ClipboardCheck`) → **Retire** (`Ban`).
- `cur = 1` in the prototype → stages 0 and 1 are **active**, stages 2–4 inactive. Active pill: `background rgba(255,255,255,.18); color #fff`. Inactive: `background rgba(255,255,255,.07); color rgba(255,255,255,.55)`. Separator chevron: `color rgba(255,255,255,.3)`.
- "current stage highlighted" = all stages up to and including `cur` are lit. The real `WorkflowRibbon` component (`resources/js/pages/health-safety/components/workflow-ribbon.tsx`) takes the stage list + a current index; verify its prop shape and pass `currentStage` accordingly. **Choosing the live stage:** default to `Stock` (index 1) as the prototype does, OR derive from the active tab (Catalogue tab → 0, Allocations → 2, Inspection-due → 3, Condemned → 4). Recommend: derive from tab for liveness; fall back to `Stock`.
- Font: `11px / 600`. Pill padding `4px 10px`, gap `5px`, radius `999px`. Icons 13px (separator 12px).

### 1.2 Title row
Flex row, space-between, `gap:16px`, wraps.

**Left cluster** (`gap:16px`):
- **Medallion** (`HeroMedallion icon={ShieldCheck}`): 76×76 circle, `border:4px solid rgba(255,255,255,.2)`, `bg rgba(255,255,255,.1)`, drop-shadow. Icon `ShieldCheck` 38px white. (Kit's `HeroMedallion` renders 72–80px responsive — use it.)
- **Status pill** (`HeroStatusPill`): animated green `status-success` ping dot + text **"PPE register · synced just now"**, uppercase `11px/600`, letter-spacing `.07em`, `bg rgba(255,255,255,.15)`, padding `4px 11px`, radius `999px`.
- **H1**: "PPE & Equipment" — `28px / 700`, letter-spacing `-.02em`, line-height `1.1`, margin 0.
- **Description** (`p`, max-width 560px, `14px`, line-height 1.5, `color rgba(255,255,255,.72)`):
  > "Catalogue, issue, inspect and retire personal protective equipment — fit-tested, in-date and acknowledged across every site."

**Right cluster** — **"Add to register"** button + popover:
- Button: white bg, `color var(--primary)`, `13px/600`, padding `9px 14px`, radius `9px`, icon `Plus` 16px left + `ChevronDown` 14px right (caret tinted `rgba(81,30,180,.5)` → use `text-primary/50`). Drop-shadow. (This is an on-dark/white affordance — use a plain `<button>` with the sanctioned `eslint-disable no-restricted-syntax` comment, OR a shadcn `Popover` trigger styled white. Match the Add-Client header pattern.)
- Popover panel: `width 248px`, white, `border var(--border)`, radius `12px`, padding `6px`, drop-shadow, `animation pop .12s`. Positioned `right:0; top:46px`. Backdrop overlay closes it (full-screen transparent `position:fixed inset-0 z-30`).
- **Popover items** (each `display:flex; gap:10px; padding:9px 10px; radius:8px`, hover bg `--accent`):
  | Icon | Title (`13px/600`) | Blurb (`11px muted`) | Opens wizard |
  |---|---|---|---|
  | `Hexagon` | Add PPE type | New catalogue entry | `type` |
  | `Package` | Add inventory item | Physical stock at a site | `inventory` |
  | `User` | Allocate PPE | Issue an item to a worker | `allocate` |
  - Each item's icon sits in a 26×26 rounded-7px tile, `bg accent` (`oklch(0.94 0.03 277)` → `bg-accent`/`bg-primary/10`), `color primary`.

### 1.3 Stat clusters (`HeroCluster` × 2 / `HeroClusterTile`)
Two clusters side-by-side: `grid; grid-template-columns:repeat(2,1fr); gap:12px` (kit cluster wraps to 1 col below `sm`). Each cluster: titled card (`border rgba(255,255,255,.15); bg rgba(255,255,255,.05); padding:12px; radius:16px`) with an uppercase `11px/600` title row (icon 14px + label) and a 4-tile grid inside (`grid-template-columns:repeat(4,1fr); gap:8px`).

Each **tile** (`HeroClusterTile`, rendered as a link/button to a tab): dot (`6px`, tone colour, `filter:brightness(1.6)`) + uppercase `10.5px` label, then a `25px/700 tabular-nums` value, then a `10.5px` caption. Hover bg `rgba(255,255,255,.2)`. Clicking sets the tab.

**Cluster A — "Live · register"** (icon `Activity`):
| Label | Value (source) | Caption | Tone | Links to tab |
|---|---|---|---|---|
| Total items | `inv_all` count | in register | `neutral` | `inv_all` |
| Allocated | `inv_allocated` | issued out | `info`(→primary) | `inv_allocated` |
| Available | `inv_available` | ready to issue | `success` | `inv_available` |
| Inspections due | `inv_inspection` | next 30 days | `warning` | `inv_inspection` |

**Cluster B — "Needs attention"** (icon `Bell`):
| Label | Value (source) | Caption | Tone | Links to tab |
|---|---|---|---|---|
| Insp. overdue | `overdue` (insp `< 0`) | past cadence | `critical` | `inv_inspection` |
| Expiring | `expiring` (exp `≤60`) | ≤60 days / expired | `warning` | `inv_expiring` |
| Condemned | `condemned` | awaiting disposal | `critical` | `inv_condemned` |
| Unacknowledged | `unack` | allocations | `warning` | `alloc_unack` |

> Note: "Insp. overdue" tile links to the **`inv_inspection`** tab (the prototype routes overdue → inspection-due tab, since overdue is a subset of due). Keep this.

**Count definitions (server `hero` block must compute these):**
- `total` = count of all inventory rows (after filters? prototype `counts()` is **unfiltered** — counts reflect the whole register, not the filtered view). **Server tabCounts/hero should be unfiltered global counts** so the badges don't jump as you filter.
- `available` = inventory `status === 'available'`.
- `allocated` = inventory `status === 'allocated'`.
- `inspections (due)` = inventory `next_inspection_due != null && daysUntil <= 30` (includes overdue).
- `overdue` = inventory `next_inspection_due != null && daysUntil < 0`.
- `expiring` = inventory `expiry_date != null && daysUntil <= 60` (includes expired).
- `condemned` = inventory `status === 'condemned'`.
- `unack` = allocations `status active (returned_at null) && acknowledged === false`.

### 1.4 Compliance badges (`HeroComplianceBadges items={…}`)
The prototype renders a **bespoke PPE chip row** (not the canonical 5 H&S chips). Use the kit's **`items` override prop** (`HeroComplianceBadge[]`) — the kit already supports this exactly for "a page tells its own compliance story while keeping identical chip chrome". Pass `BadgeTone` (`success | warning | critical`) and `LucideIcon`, never pre-formatted-then-toned.

Row (`mt-3 flex flex-wrap gap-2`), 5 chips:
| # | Icon | Tone logic | Label (count-fed) |
|---|---|---|---|
| 1 | `Wind` | **`warning`** (hard-coded in prototype) | `RPE fit-test · {n} due` — prototype hard-codes "1 due". **Build must feed the real count** = active allocations where the type is respiratory AND fit-test required AND not done. Tone: `critical` if any overdue/missing else `warning` if due else `success` ("RPE fit-test · all current"). The prototype's flat warning is a simplification; implement the real Fire-style escalation. |
| 2 | `AlertTriangle` | `overdue > 0 ? 'critical' : 'success'` | overdue>0: `Inspections · {overdue} overdue`; else `Inspections · current` |
| 3 | `AlertTriangle` | `expiring > 0 ? 'warning' : 'success'` | expiring>0: `Expiry · {n} item{s} expiring`; else `Expiry · all in date` |
| 4 | `Ban` | `warning` (prototype flat); **build: `condemned > 0 ? 'warning' : 'success'`** | `Condemned · {n} awaiting disposal` |
| 5 | `ShieldCheck` | `success` (coverage boolean) | `Hi-vis & footwear · Covered` — boolean: are there available/allocated hi-vis AND footwear items across sites? If a coverage gap, `warning` + "Hi-vis & footwear · Gaps". |

**Fire-style red-overdue-vs-amber-due pattern** (required by HANDOFF, matches kit's `fireTone`): for each badge where a "due" and an "overdue/expired" state both exist, **overdue/expired → `critical` (red)** outranks **due-soon → `warning` (amber)**, else **`success`**. Apply to badge 1 (RPE) and badge 2 (inspections) explicitly. Pluralise correctly (`item` vs `items`, `drill` vs `drills` idiom).

Chip chrome comes from the kit: `inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium`; tone classes via `CHIP_CLASS`/`CHIP_ICON` (on-dark: success = faint white, warning = `status-warning/25`, critical = `status-critical/25`).

### 1.5 Hero footer = filter bar (`HeroShell footer={…}`)
Border-top band, `flex flex-wrap items-center gap-x-[14px] gap-y-[10px]; padding:12px 28px`.
- Eyebrow label "Filter" — uppercase `11px/600`, `color rgba(255,255,255,.6)`.
- **Site filter** (`EntityFilter` from `@/components/rostering`): options "All sites" + each site (prototype seeds Tāmaki House / Waikato Lodge / Ōtautahi Villa). On-dark select styling: `border rgba(255,255,255,.2); bg rgba(255,255,255,.12); color #fff; 12px/500; padding 6px 9px; radius 8px`.
- **Category select**: "All categories" + respiratory / head / eye / hand / foot / Hi-vis (`high_visibility`) / Fall protection (`fall_protection`) / Hearing (`ear`). (Note `body`/`other` exist in the model enum but the prototype filter omits them — include them in the build for completeness, label "Body"/"Other".)
- **Status select**: "Any status" + available / allocated / in_repair ("In repair") / condemned. (Model also has `maintenance`, `disposed`, `retired` — surface the model's real enum.)
- **Search** (right-aligned via `margin-left:auto`): `type=search`, placeholder **"Search PPE…"**, `width 188px`, search icon inset-left. Matches across type name / brand / model / serial / site / location (inventory), worker / type / serial (allocations), name / standard / hazards (types).
- **Clear** button (only when any filter active): `X` icon 13px + "Clear", `11px/500`, `color rgba(255,255,255,.75)`, transparent bg.

**All filters drive** `router.get('/health-safety/ppe', { ...filters, ...next }, { preserveState:true, preserveScroll:true, replace:true })`. Server-side filtering retained (controller already filters site/category/status/ppe_type — add `q` search + `condition` if missing). Keep `preserveScroll` and `replace`.

### 1.6 Hero right-click quick actions (`onContextMenu` → `ShiftContextMenu`)
Right-clicking anywhere on the hero opens the context menu with `tag:'PPE'`, `meta:'Quick actions'`, items:
| Icon | Label | Tone | Action |
|---|---|---|---|
| `Hexagon` | Add PPE type | primary | open `type` wizard |
| `Package` | Add inventory | — | open `inventory` wizard |
| `User` | Allocate PPE | — | open `allocate` wizard |
| — | *(separator)* | | |
| `ExternalLink` | Export register (CSV) | — | export (toast "Export queued" in prototype; wire a real CSV export route) |

HANDOFF also lists "Go to analytics" as a hero quick action — **add** `{ icon: Activity/BarChart3, label: 'Go to analytics', href: '/health-safety/analytics' }` to match the dashboard idiom (prototype omits it; HANDOFF §1 requires it).

---

## 2. TAB STRIP (`TabStrip` / `RosterTabItem[]`)

Flat list, no separators, wraps. Container: `flex flex-wrap items-center gap-1 rounded-2xl border bg-card p-1.5 shadow-sm`, `role="tablist" aria-label="PPE views"`. Each tab is `role="tab" aria-selected`. Changing tab → `router.get(..., { preserveScroll:true })` (server-driven so counts stay live & pagination resets per tab).

Each tab: icon chip (22×22, rounded-6px) + label (`13px/600`) + count pill (`tabular-nums`, `10px/700`, bg `color-mix(var(--bg) 60%)`). Active tab gets a tinted bg + a 2px underline bar (`bottom:-1px`, inset 14px). Hover (inactive) → `bg-accent text-foreground`.

**Order, label, icon, tone, filter:**
| # | key | Label | Icon | Tone | Filters to |
|---|---|---|---|---|---|
| 1 | `inv_all` | All inventory | `List` | **primary** | all inventory rows (after filters) |
| 2 | `inv_available` | Available | `PackageCheck` | **success** | inventory `status === 'available'` |
| 3 | `inv_allocated` | Allocated | `User` | **info**(→primary) | inventory `status === 'allocated'` |
| 4 | `inv_inspection` | Inspection due | `ClipboardCheck` | **warning** | inventory `next_inspection != null && daysUntil ≤ 30` |
| 5 | `inv_expiring` | Expiring | `AlertTriangle` | **critical** | inventory `expiry != null && daysUntil ≤ 60` |
| 6 | `inv_condemned` | Condemned | `Ban` | **critical** | inventory `status === 'condemned'` |
| 7 | `alloc_active` | Allocations | `BadgeCheck` | **info**(→primary) | allocations `status active` (returned_at null) |
| 8 | `alloc_unack` | Unacknowledged | `AlertTriangle` | **warning** | allocations active && `!acknowledged` |
| 9 | `types` | Catalogue | `Hexagon` | **primary** | all PPE types |

Tone palette per tab (`TA` map): primary/info → accent bg + primary fg + primary bar; success → `success-bg`/`success`; warning → `warning-bg`/`warning`; critical → `critical-bg`/`critical`. Map to `TabStrip`'s tone API (it already supports per-tab tone + badge count — verify the `RosterTabItem` shape and pass `tone` + `badge`).

`tabCounts` server prop must provide all 9 counts (global, unfiltered by tab; the active filters MAY apply — match the prototype which keeps counts global). **Decision: counts are global (filter-independent) in the prototype.** Recommend keeping global so the strip is a stable overview; if you want filter-aware counts, be consistent with the hero tiles.

---

## 3. TABLE (3 row shapes) — `register-row-kit`

Active table chosen by tab prefix: `inv_*` → Inventory table; `alloc_*` → Allocation table; `types` → Type table.

**Card header** (`RegisterTableHeader`): accent-tiled icon (32×32, `bg-primary/10 text-primary`) + bold `14px` title + `· {n} item(s)` muted subtitle, and a right-aligned **hint** with `MousePointer2` icon:
- Inventory: icon `Package`, title "Inventory", subtitle "{n} item(s)", hint **"Right-click a row for the full lifecycle"**.
- Allocations: icon `BadgeCheck`, title "Allocations", subtitle "{n} active issue(s)", hint "Right-click a row for the full lifecycle".
- Types: icon `Hexagon`, title "PPE catalogue", subtitle "{n} type(s)", hint **"Right-click to edit or retire"**.

**Table**: `width:100%; border-collapse:collapse; font-size:13.5px`. `thead tr` border-bottom. `th`: `padding:10px 16px; text-align:left; 11px/600 uppercase; letter-spacing .04em; color muted; white-space nowrap`. `td`: `padding:12px 16px; vertical-align:top`.

**Row behaviour (copy Incidents row exactly):** every `<tr>`:
- `onClick` → open detail modal (`openDetail(invId)`).
- `onContextMenu` → row context menu.
- `tabIndex={0}`, **Enter/Space** opens detail (prototype omits keyboard on rows — **add it**, HANDOFF §3 requires `tabIndex={0}` + Enter/Space).
- Hover: `hover:bg-muted/45` (prototype uses `bg muted` on hover; use `bg-muted/45` per HANDOFF) + focus ring.
- All tone via `TONE_BG` / `TONE_DOT` / `FlagBadge` — **no raw colours**.

### 3.1 INVENTORY columns
| Col | Content | Tone / FlagBadge |
|---|---|---|
| **Type** | 30×30 rounded-8px category-icon tile (`bg/fg` = category tone) + `name` (`600`) + sub `"{Category} · {standard}"` (`11.5px muted`). | tile tone = `CAT_TONE[category]` |
| **Site / location** | `site` (`500`) + `location` (`12px muted`). | — |
| **Identification** | `"{brand} {model}"` (`500`) + sub `serial` (`11.5px muted tabular-nums`) `+ " · ×{qty}"` when qty>1. | — |
| **Condition** | `chip(condTone(condition), Titlecased condition)`. | new=success, good=info(→primary), fair=warning, poor=warning, condemned=critical |
| **Status** | `chip(statusTone(status), statusLabel)`. | available=success, allocated=info, in_repair=warning, condemned=critical, retired=neutral |
| **Next inspection** | `dateCell(next_inspection_due, daysUntil, warnWin=30)`: calendar icon + `fmtDate`, plus a sub-line. | **FlagBadge tone:** `days<0`→critical ("`{n}d overdue`"), `days≤30`→warning ("`in {n}d`"), else neutral (no sub). |
| **Expiry** | `dateCell(expiry_date, daysUntil, warnWin=60)`. | `days<0`→critical ("`{n}d overdue`" — i.e. expired), `days≤60`→warning ("`in {n}d`"), else neutral. |
| **Flags** | wrap of `chip`s from `invView.flags`; em-dash placeholder if none. | see flag rules below |

**Inventory `flags` rules** (a row can carry several):
- Inspection: `daysUntil(next) < 0` → `{icon:Clock, 'Inspection overdue', critical}`; else `≤30` → `{Clock, 'Inspection due', warning}`.
- Expiry: `daysUntil(expiry) < 0` → `{AlertTriangle, 'Expired', critical}`; else `≤60` → `{AlertTriangle, 'Expiring', warning}`.
- Condemned: `status === 'condemned'` → `{Ban, 'Awaiting disposal', warning}`.

`dateCell` detail: value row `12.5px`; tone-coloured (icon + text) when not neutral, `600` weight; sub-line `11px` tone-coloured, left-indented 18px.

### 3.2 ALLOCATION columns
| Col | Content | Tone / FlagBadge |
|---|---|---|
| **Worker** | 30×30 **round** initials avatar (`bg-accent text-primary`, `11px/700`) + `worker` name (`600`). Use `initials()` + `entityTone(id)` from kit. | — |
| **Item** | category icon (15px, tone fg) + `typeName` (`500`) + `serial` (`11.5px muted`). | icon tone = `CAT_TONE[cat]` |
| **Allocated** | `fmtDate(allocated)` (`12.5px muted`, nowrap). | — |
| **Fit-test** | If `fitRequired`: done → `chip(success, 'Pass · {date}', Check)`; not done → `chip(critical, 'Required', Wind)`. Else → "N/A" (`12px muted`). | required+missing = critical |
| **Training** | done → `chip(success, 'Done', Check)`; else → `chip(warning, 'Outstanding')`. | — |
| **Acknowledged** | ack → `chip(success, 'Acknowledged', BadgeCheck)`; else → `chip(warning, 'Pending')`. | **this is the FlagBadge-Unacknowledged surface** |
| **Flags** | wrap of `allocView.flags`; em-dash if none. | see below |

**Allocation `flags` rules** (active issues only):
- `status === 'active' && !ack` → `{AlertTriangle, 'Unacknowledged', warning}`.
- `status === 'active' && fitRequired && !fitDone` → `{Wind, 'No fit-test', critical}`.

### 3.3 TYPE columns
Row `onClick` → opens the **Edit type wizard** (not a detail modal — types have no detail modal). `onContextMenu` → type context menu.
| Col | Content | Tone |
|---|---|---|
| **Type** | 30×30 rounded-8px category-icon tile + `name` (`600`) + `hazards` truncated (`11.5px muted`, max-width 320px ellipsis). | tile = `CAT_TONE` |
| **Category** | `chip(CAT_TONE[category], CAT_LABEL[category])`. | category tone |
| **Standard** | `standard` (`12.5px/500`). | — |
| **Inspection** | `frequency` (`12.5px muted`, capitalize). | — |
| **Lifespan** | `"{lifespan} mo"` (`12.5px muted`). | — |
| **Status** | active → `chip(success, 'Active', Check)`; else → `chip(neutral, 'Retired')`. | — |

**Category tone & icon maps** (drive tiles, chips, dots everywhere):
```
CAT_ICON  = {head:HardHat, eye:Eye, ear:Ear, respiratory:Wind, hand:Hand, foot:Footprints,
             high_visibility:Shirt, body:Shirt, fall_protection:Anchor, other:Package}
CAT_LABEL = {head:'Head', eye:'Eye', ear:'Hearing', respiratory:'Respiratory', hand:'Hand',
             foot:'Foot', high_visibility:'Hi-vis', body:'Body', fall_protection:'Fall protection', other:'Other'}
CAT_TONE  = {respiratory:info, head:warning, eye:success, ear:neutral, hand:info, foot:warning,
             high_visibility:success, fall_protection:critical, body:neutral, other:neutral}
```
(`info` tone → primary/accent in the token system. Note: `CAT_TONE` differs from the current page's `categoryColor` — adopt the prototype's map.)

**Chip primitive** (`chip(tone,label,icon?)`): `inline-flex items-center gap-1.5 rounded-[7px] px-2 py-[3px] text-[11px] font-bold whitespace-nowrap`, `color/bg` = tone pair. Map to `register-row-kit` `FlagBadge` (for the flagged ones) and a small `TONE_BG` chip for condition/status/category.

**Empty states** (`emptyState(icon,title,sub)`): centred, 40px muted icon, `600` title, `13px` sub.
- Inventory: `Package`, "No inventory here", "Nothing matches this tab and the active filters."
- Allocations: `BadgeCheck`, "No allocations here", "Nothing matches this tab and the active filters."
- Types: `Hexagon`, "No types", "Nothing matches the active filters."

**Pagination:** server-side, retained for inventory & allocations (existing `links` arrays; Types is not paginated). Keep the prototype's per-tab table swap; only inventory/allocations carry pagination controls.

---

## 4. ROW RIGHT-CLICK MENUS (`ShiftContextMenu`)

Menu chrome: `width 288px` (clamps to viewport), white, radius 12px, padding 6px, drop-shadow, `animation pop .1s`, `role="menu"`. Header row: a `tag` chip (uppercase `10px/700`, `bg-accent text-primary`) + `meta` (muted, ellipsis), border-bottom. A full-screen overlay (`z-55`) closes on click / right-click. Each item: 26px icon tile + label (`12.5px/500`) + optional `sub` (`10.5px muted`). Critical items render `text-status-critical` (icon tile `critical-bg`); primary items `text-primary` (tile `accent`). Separators = 1px border line.

All items gated on `can.manage` (mutating ones) per HANDOFF §4. View/Copy-link are ungated.

### 4.1 Inventory row menu
`tag = catLabel`, `meta = "{type.name} · {site}"`.
| Icon | Label | sub | Tone | Gate | Opens |
|---|---|---|---|---|---|
| `Eye` | View item | `serial` | primary | — | detail modal (Overview) |
| `Pencil` | Edit item | | — | manage | **Edit inventory** wizard |
| `User` | Allocate to worker | | — | manage + status not condemned/retired | **Allocate** wizard (item pre-filled) |
| `ClipboardCheck` | Record inspection | | — | manage | **Record inspection** modal |
| — | *(separator)* | | | | |
| `Ban` | Condemn | | **critical** | manage + status not already condemned | **Condemn** modal |
| `Trash` | Dispose | | **critical** | manage + status condemned | **Dispose** action (prototype: toast "{serial} marked for disposal" — wire to `dispose` endpoint) |
| — | *(separator)* | | | | |
| `Copy` | Copy link | | — | — | copy deep-link (`?item={id}`), toast "Link copied" |

### 4.2 Allocation row menu
`tag = 'Issued'`, `meta = "{worker} · {typeName}"`.
| Icon | Label | sub | Tone | Gate | Opens |
|---|---|---|---|---|---|
| `Eye` | View allocation | `worker` | primary | — | detail modal (opens to **Allocation** section — pass `initialAction`) |
| `BadgeCheck` | Mark acknowledged | | — | manage + `!ack` (item only present when unacknowledged) | **Acknowledge** action → `POST .../acknowledge`, toast "{worker} acknowledged" |
| `Reply` | Return PPE | | — | manage | **Return PPE** modal |
| `ClipboardCheck` | Record inspection | | — | manage | **Record inspection** modal (on the linked item) |
| — | *(separator)* | | | | |
| `Copy` | Copy link | | — | — | copy link, toast |

### 4.3 Type row menu
`tag = CAT_LABEL[category]`, `meta = type.name`.
| Icon | Label | Tone | Gate | Opens |
|---|---|---|---|---|
| `Pencil` | Edit type | primary | manage | **Edit type** wizard |
| `Ban`/`Check` | Deactivate / Activate (depends on `active`) | — | manage | toggle `is_active` → `PUT .../types/{type}` (or activate/deactivate), toast "{name} (de)activated" |
| — | *(separator)* | | | |
| `Package` | Add inventory of this type | — | manage | **Add inventory** wizard (type pre-filled) |

Each **mutating** item opens the relevant modal — never a bare navigation.

---

## 5. DETAIL-AS-MODAL (item record)

Opens on row left-click and on context-menu "View". Uses the **Add-Client modal shell** (full-height split dialog). Server-side detail load: `router.get(..., { only:['detail'], preserveState:true, preserveScroll:true })` adds `?item={id}` (or `?allocation={id}`); closing drops the param → `detail` returns null. Support `initialAction`/`initialSection` so a context-menu action opens straight onto a section (e.g. "View allocation" → Allocation section).

### 5.1 Shell
`modalShell(content, wide=true)`: backdrop `rgba(15,12,30,.55)`, `animation in .15s`; panel `width min(94vw, 1040px)` (HANDOFF says detail/wizard `min(94vw,1080px)` — use 1080), `radius 16px`, `bg #fff`, big shadow, `animation pop .16s`. Closes on backdrop click + Esc + close button. Inner height `min(86vh, 720px)`, `display:flex`.

### 5.2 Left rail (**248–250px**)
`width 250px; flex-shrink:0; border-right; bg var(--sidebar); padding:16px; overflow-y:auto; display:flex flex-col gap:3px`.
- **Header**: 40×40 rounded-11px category medallion (`bg/fg` = `catTone`, icon 20px) + `type.name` (`13.5px/700`, line-height 1.15) + `serial` (`11px muted tabular-nums`). `margin-bottom:14px`.
- **Section nav** (vertical buttons): each = 26px **round** icon tile (active → `bg-primary text-white`, else `bg-muted text-muted`) + label (`12.5px`, active `700` else `600`) + blurb (`10.5px muted`, ellipsis). Active item bg `--accent`; hover (inactive) `oklch(0.95 0.008 277)` → `bg-muted/60`.
- Section items (icon, label, blurb):
  | key | icon | label | blurb (dynamic) |
  |---|---|---|---|
  | `overview` | `Info` | Overview | "Identity & specification" |
  | `allocation` | `User` | Allocation | active ? "Issued to {firstName}" : "Available to issue" |
  | `inspections` | `ClipboardCheck` | Inspections | insp<0 ? "Overdue" : "Checks & due dates" |
  | `history` | `History` | History | "Full audit trail" |
- **Pinned bottom** (`margin-top:auto; padding-top:14px; flex-wrap gap:5px`): condition chip + status chip (`condTone`/`statusTone`).

### 5.3 Main column
`flex:1; min-width:0; display:flex flex-col`.
- **Header bar** (`padding:13px 20px; border-bottom`): left = "**Item record · {section label}**" (`13px`, "Item record ·" muted, label `fg`); right = close `X` button (32×32, hover `bg-muted`).
- **Body** (`flex:1; overflow-y:auto; padding:20px 24px; animation slidein .22s` keyed on section): a `StepHead` (icon tile 20px in `bg-accent text-primary` + `17px/700` title + `13px muted` blurb) then the section body.
- **Sticky footer** (`padding:13px 20px; border-top; bg oklch(0.98 0.004 277)` → subtle; right-aligned, `gap:10px`): three lifecycle action buttons:
  - **Allocate** (`User`) → open `allocate` wizard for this item.
  - **Inspect** (`ClipboardCheck`) → open `inspection` modal.
  - **Condemn** (`Ban`, **danger** styling: `border critical/40, bg critical-bg, text critical`) → open `condemn` modal.
  - `actionBtn` chrome: `inline-flex gap-7px rounded-9px border px-[13px] py-2 13px/600`; disabled state → `bg-muted text-muted` + check icon.

### 5.4 Section bodies
**Overview** — status chip strip (condition + status + all flags), then a **2-col spec grid** (`detailRows`, hairline-separated cells, each: `10.5px/600 uppercase` label + `13px/500` value):
`Category, Standard, Brand / model, Serial, Quantity, Site, Location, Purchased, Expiry, Next inspection`.

**Allocation** — if no active issue: `detailEmpty(User, 'Not currently allocated', 'This item is available to issue.')`. Else a `detailRows`: `Worker, Allocated, Fit-test (Pass · {date} / Required — not completed / Not required), Training (Completed · {date} / Outstanding), Acknowledged (Yes / Pending worker acknowledgement)`, then two action buttons: **Return PPE** (`Reply`) and **Mark acknowledged** (`BadgeCheck`, disabled+check if already ack).

**Inspections** — a vertical list of inspection records (`border rounded-10px px-3 py-2.5`): each = calendar icon + `fmtDate` + sub ("Next scheduled" or "by {initials}") on the left, and a result chip on the right (`pass`→success "Pass" Check; `overdue`→critical "Overdue" Clock; else→warning "Scheduled" Clock). Prototype seeds a 3-row history (next-scheduled + two past passes); **build pulls real `PpeInspection` rows** (result, inspected_at, inspected_by) + the upcoming `next_inspection_due`. Add a "Record inspection" affordance (the footer Inspect button covers this).

**History** — audit timeline (vertical, connector line between nodes): each node = 30px round accent icon + bold `13px` title + `12px muted` detail + `11px` date. Prototype seeds `Allocated to {worker}`, `Inspection passed`, `Item received`. **Build assembles a real timeline** from created/updated/allocations/inspections (created_by/updated_by eager-loaded).

---

## 6. WIZARDS (the "Add Client" pattern)

**Two families:** (a) **multi-step wizards** (Add inventory, Allocate, Add PPE type — and their Edit variants) on the split-rail shell; (b) **single-step action modals** (Return, Record inspection, Condemn — plus Acknowledge/Dispose/Deactivate actions). All compose `@/components/wizard/primitives` (`Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker, Ring`) — **do not hand-roll inputs**. Reference impl: `resources/js/components/clients/add-client-dialog.tsx`.

### 6.0 Wizard shell (matches Add-Client exactly)
- `Dialog`: `maxWidth: min(94vw, 1080px)`, `[&>button]:hidden`. Inner height `min(88vh, 820px)`.
- **Stepper rail** (250px, `bg-sidebar`): header = 36×36 rounded-9px `bg-primary text-white` icon + title (`13.5px/700`; edit variant uses `editTitle`) + sub (`11px muted`). Then numbered/checked steps: 26px round badge — active = `bg-primary text-white` + step icon; complete = `bg-success-bg text-success` + `Check`; upcoming = `bg-muted text-muted` + step icon. Label (`12.5px`, active `700`) + blurb (`10.5px muted`), both ellipsis. Steps are **clickable** (`wizGoto(i)` — jump freely). Pinned bottom: "Progress" label + `{pct}%` (primary) + a 6px track/bar.
- **Main column**: header "**Step {i} of {N} · {step label}**" + close `X`; a **3px top progress bar** (`width {pct}%`, primary); scroll-contained body (`padding:22px 24px; animation slidein .25s` keyed on step); sticky footer.
- **Footer** (`wizFooter`): left = **Back** (ghost, `ChevronLeft`, only when step>0); right = **Cancel** (outline) + then either:
  - non-review step: **Continue** (primary, `ChevronRight` right icon) → `wizNext()` (validates current step, blocks on errors).
  - review step: **Save & add another** (secondary, `Plus`; only when `addAnother && !edit`) + **Create {sub}** / **Save changes** (primary, `Check`).
- **Validation**: per-step `validateStep` (mirrors server request) blocks Continue. On submit, re-validate **every** step; if any fail, jump to the **first failing step** (`stepForError`) and show errors. Inertia `useForm` with `forceFormData, preserveScroll, preserveState`.
- **Success pane** (`renderWizardSuccess`): centred 72px round `success-bg/success` check medallion (`animation pop .3s`), title ("{Sub} created" / "Changes saved"), body "**{title}** has been added to the register / updated.", then **Add another** (outline, `Plus`; create + non-edit only — resets form to step 0) + **Done** (primary, closes).

**Button variants** (`btn`): primary `bg-primary text-white`; secondary `bg-accent text-primary`; outline `bg-white border`; ghost transparent muted. All `radius:9px; 13px/600; padding:9px 15px`. (On-dark not relevant here — these are light-surface; standard shadcn `Button` is fine. Only the hero white buttons need the eslint-disable.)

**Field primitives** (map prototype helpers → `wizard/primitives`):
- `fField(label,req,err,child,span?)` → `Field` (label + required `*` in `text-status-critical` + child + `FieldErr`). `span` = full-width (`grid-column:1/-1`).
- `fInput` → primitives text/number/date `<input>` (38px min-height, radius 8px, focus ring `primary/20`).
- `fSelect` → `SelectInput` (chevron-down, placeholder disabled option).
- `fSeg` → `Segmented` (inline pill group, active = white card + shadow).
- `fTiles` → `TilePicker` (2-col grid; each tile = icon tile + label + desc; active = `border-primary bg-accent ring`).
- `fToggle` → a switch row (40×23 track, knob; active = `border-primary bg-accent`).
- `fTextarea` → textarea (3 rows, resize-y).
- `fInfo(tone)` → `InfoCard` (`info`/`warn`/`crit`; full-width; icon + body). info = accent/primary, warn = warning-bg/warning, crit = critical-bg/critical.
- `fGrid` → 2-col `grid gap-[14px]`.
- `StepHead` (`fStepHead`): icon tile (`bg-accent text-primary` rounded-11px, 20px) + `17px/700` title + `13px muted` blurb, `margin-bottom:18px`.

### 6.1 Wizard — **Add inventory item** (`POST /health-safety/ppe/inventory`)
Config: icon `Package`, sub "Physical stock", `addAnother: true`, edit title "Edit inventory item". 4 steps.

**Step 1 — Type & site** (icon `Hexagon`, blurb "Pick the catalogue type and where this item is stored."):
| Field | Type | Options | Required | Notes |
|---|---|---|---|---|
| PPE type | `SelectInput` (`ppe_type_id`) | active types only (`name`) | **yes** | full-width (span) |
| Site / home | `SelectInput` (`site_id`) | sites | **yes** | |
| Location | text | — | no | placeholder "e.g. PPE store A" |
| *(InfoCard)* | info | — | — | shown when a type is chosen: "**{type name}** is governed by **{standard}**. Default inspection cadence: {frequency}." |

Validation (step `type`): `typeId` required ("Choose a PPE type"), `site` required ("Choose a site").

**Step 2 — Identification** (icon `Package`, "Brand, model and the unique asset identifier."):
| Field | Type | Required | Placeholder |
|---|---|---|---|
| Brand | text | no | "e.g. 3M" |
| Model | text | no | "e.g. 6200" |
| Serial / asset ID | text (`serial_number`) | **yes** | "e.g. RSP-6200-014" |
| Quantity | number (`quantity`, default "1") | no | "1" |

Validation (step `id`): `serial` required ("Serial / asset ID is required"). ⚠️ Server currently allows `serial_number` nullable — the wizard makes it required client-side; keep that UX (and optionally tighten the request).

**Step 3 — Condition & dates** (icon `Calendar`, "Current state, expiry and the next inspection due date."):
| Field | Type | Options | Required |
|---|---|---|---|
| Condition | `Segmented` (`condition`, default "new") | new / good / fair / poor | no |
| Purchase date | date | — | no |
| Expiry date | date | — | no |
| Next inspection due | date (full-width) | — | no |

**Step 4 — Review & create**: review card (icon medallion + title `{type name}` + sub `{serial}` + a `{pct}% complete` ring based on filled rows) + a 2-col `Type, Category, Site, Location, Brand / model, Serial, Quantity, Condition, Expiry, Next inspection` grid. Footer: **Save & add another** + **Create stock**.

Server map: `ppe_type_id, site_id, location, brand, model, serial_number, quantity, condition, purchase_date, expiry_date, next_inspection_due` → `storeInventory` (already exists; sets `status:'available'`).

### 6.2 Wizard — **Allocate PPE to worker** (`POST /health-safety/ppe/inventory/{inventory}/allocate`)
Config: icon `User`, sub "Issue an item", `addAnother: false`. 4 steps. **`isRpe()`** = the chosen inventory item's type category is `respiratory` (drives conditional fit-test logic).

**Step 1 — Worker & item** (icon `User`, "Who receives this item, and which physical unit."):
| Field | Type | Options | Required |
|---|---|---|---|
| Worker | `SelectInput` (`user_id`) | staff | **yes** |
| Inventory item | `SelectInput` (`inventory`/`invId`) | items `status === 'available'` (+ the current one if editing); label `"{type name} · {serial}"` | **yes** (full-width) |
| *(InfoCard, warn)* | — | shown when **RPE**: "This is respiratory protective equipment. Under **AS/NZS 1715** a current quantitative fit-test is required before issue." |

Validation (step `worker`): `worker` required, `invId` required.

**Step 2 — Fit-test** (icon `Wind`):
- **If NOT RPE**: blurb "Not required for this equipment type." + InfoCard(info) "Fit-testing applies to tight-fitting respiratory protection only. You can continue." (**non-blocking; skip**.)
- **If RPE**: blurb "Quantitative fit-test per AS/NZS 1715." Fields:
  | Field | Type | Required | Notes |
  |---|---|---|---|
  | Fit-test completed | `fToggle` (`fit_test_completed`) | **yes (when RPE)** | "Worker passed a quantitative fit-test for this make/model" |
  | Fit-test date | date (`fit_test_date`) | **yes** (shown only when toggle on) | |
  | Result | `Segmented` (`fit_test_result`) | no | pass / fail |
- Validation (step `fit`, **only when `isRpe()`**): `fitDone` required ("RPE requires a current fit-test (AS/NZS 1715)"); then `fitDate` required ("Record the fit-test date").

**Step 3 — Training & acknowledgement** (icon `ClipboardCheck`, "Donning/doffing training and the worker sign-off."):
| Field | Type | Required | Notes |
|---|---|---|---|
| Training completed | `fToggle` (`training_completed`) | no | "Worker trained on correct use, storage & limits" |
| Training date | date (`training_date`) | no | shown only when toggle on |
| Worker acknowledgement | `fToggle` (`ack`/`acknowledged`) | no | "Worker confirms they received and understand the PPE" |

⚠️ **Backend gap**: current `allocate` validates `fit_test_*` / `training_*` but **never persists `acknowledged`/`acknowledged_at`**. The wizard sets `ack` — wire it (columns already exist per migration `2026_03_28_200005_create_ppe_tables.php`).

**Step 4 — Review & issue**: review rows `Worker, Item, Fit-test (Pass · {date} / Required / N/A), Training (Done · {date} / Outstanding), Acknowledged (Yes / Pending)`. Footer: **Create issue an item** (no "Save & add another"). (Polish the button label — "Issue PPE" reads better than "Create issue an item".)

### 6.3 Wizard — **Add PPE type** (`POST /health-safety/ppe/types`)
Config: icon `Hexagon`, sub "Catalogue entry", `addAnother: true`, edit title "Edit PPE type". 4 steps.

**Step 1 — Identity** (icon `Hexagon`, "Name the type and pick its protection category."):
| Field | Type | Required |
|---|---|---|
| Name | text | **yes** ("e.g. Half-face respirator (P2)") |
| Category | `TilePicker` (`category`) | **yes** |

`TilePicker` options (key, label, desc, icon) — **2-col grid, 8 tiles**:
| key | label | desc | icon |
|---|---|---|---|
| head | Head | Helmets, bump caps | `HardHat` |
| eye | Eye | Glasses, goggles | `Eye` |
| ear | Hearing | Plugs, muffs | `Ear` |
| respiratory | Respiratory | Masks, RPE | `Wind` |
| hand | Hand | Gloves | `Hand` |
| foot | Foot | Boots | `Footprints` |
| high_visibility | Hi-vis | Vests, jackets | `Shirt` |
| fall_protection | Fall protection | Harnesses, lanyards | `Anchor` |

Validation (step `identity`): `name` required, `category` required ("Choose a category").

**Step 2 — Standards & lifecycle** (icon `ShieldCheck`, "The AS/NZS reference, inspection cadence and expected life."):
| Field | Type | Options | Required |
|---|---|---|---|
| Standard reference | text (`standards_reference`) | — | **yes** ("e.g. AS/NZS 1715 & 1716") |
| Inspection frequency | `Segmented` (`inspection_frequency`, default "monthly") | daily / weekly / monthly / quarterly / annually | no |
| Typical lifespan (months) | number (`typical_lifespan_months`) | — | no |

Validation (step `standards`): `standard` required ("Standard reference is required").
⚠️ The current Add-Type dialog uses frequency enum `before_each_use, weekly, monthly, quarterly, six_monthly, annually`; the **server `storeType` validates** `daily,weekly,monthly,quarterly,annually`. The prototype uses `daily…annually`. **Align all three** — recommend the server enum `daily,weekly,monthly,quarterly,annually` (matches prototype + controller). If `six_monthly`/`before_each_use` are needed, add to both server enum and the Segmented options.

**Step 3 — Guidance** (icon `Info`, "Hazards this protects against and any handling notes."):
| Field | Type | Required |
|---|---|---|
| Hazards addressed | text (`hazards_addressed`) | no ("e.g. Airborne particulates, chemical vapours") |
| Description | textarea (`description`) | no ("Notes on correct use, fit-testing or storage…") |

**Step 4 — Review & create**: rows `Name, Category, Standard, Inspection, Lifespan ({n} months), Hazards`. Footer: **Save & add another** + **Create catalogue entry**.

Server map → `storeType` (exists). For **Edit** → new `PUT /health-safety/ppe/types/{type}` (`updateType`, HANDOFF Backend §).

### 6.4 Action modal — **Return PPE** (`POST /health-safety/ppe/allocations/{allocation}/return`)
Single-step (not split-rail). Header: `Reply` icon (40px `bg-accent text-primary`), title "Return PPE", sub `{worker}`. Body:
- InfoCard(info): "Returning **{typeName}** ({serial}) from **{worker}**."
- **Returned condition** — `Segmented` (`condition`, default "good"): good / fair / poor / condemned.
- **Notes** — textarea ("Anything noted on return…").
Footer: **Cancel** + **Confirm** (`Check`). Server `returnPpe` (exists; sets `returned_at`, condition→status). No validation gates.

### 6.5 Action modal — **Record inspection** (`POST /health-safety/ppe/inventory/{inventory}/inspections`)
Single-step. Header: `ClipboardCheck`, title "Record inspection", sub `"{type name} · {serial}"`. Body:
- **Result** — `Segmented` (`result`, default "pass"): pass / needs_repair ("Needs repair") / fail / condemned ("Condemn").
- **Condition after** — `Segmented` (`condition_after`, default "good"): new / good / fair / poor.
- **Findings** — textarea ("What was checked and observed…").
- **Next inspection due** — date.
Footer: **Cancel** + **Confirm**. Server `storeInspection` (exists). ⚠️ Server `condition_after` enum is `good,fair,poor,condemned` (no "new") while the modal offers "new" — **add `new` to the server enum** or drop it from the Segmented. Also server `result === 'condemned'` already flips status+condition to condemned (good).

### 6.6 Action modal — **Condemn** (`POST .../inventory/{inventory}/condemn` — NEW)
Single-step. Header: `Ban` icon (40px **`bg-critical-bg text-critical`**), title "Condemn item", sub `"{type name} · {serial}"`. Body:
- InfoCard(**crit**): "Condemning removes this item from service. It will move to "awaiting disposal"."
- **Reason** — textarea (`reason`), **required** ("Why is this item being condemned?").
- **Next step** — `Segmented` (`disposal`, default "quarantine"): quarantine / dispose ("Dispose now").
Footer: **Cancel** + **Confirm** (validates: reason required, "A reason is required"). NEW endpoint `condemn` writes `status:'condemned', condition:'condemned'` + the reason/audit. If "Dispose now" → chain to `dispose`.

### 6.7 Action — **Edit inventory** (`PUT /health-safety/ppe/inventory/{inventory}`, exists)
Reuse the Add-inventory wizard fields, pre-filled from the row, `edit:true` (title "Edit inventory item", no "Save & add another", success "Changes saved"). Server `updateInventory` (exists; note it allows `status` + `maintenance` enum value).

### 6.8 Actions without a wizard body
- **Acknowledge** → `POST .../allocations/{allocation}/acknowledge` (NEW): set `acknowledged=true, acknowledged_at=now()` (+ optional `acknowledged_by`). Toast "{worker} acknowledged". Surfaced from row menu (when `!ack`) + detail Allocation section.
- **Dispose** → `POST .../inventory/{inventory}/dispose` (NEW): set `status:'disposed'` + audit. Toast "{serial} marked for disposal".
- **Activate / Deactivate type** → `PUT .../types/{type}` toggling `is_active` (part of `updateType`). Toast "{name} (de)activated".

---

## 7. Toast
Bottom-centre, `bg var(--foreground) text-white`, `radius 11px; padding 11px 16px; 13px/500`, icon (default `CheckCircle` in `success` green), `animation in .2s`, auto-dismiss **2600ms**. Use the app's existing flash/toast mechanism on Inertia success (controllers `redirect()->back()->with('success', …)`); see MEMORY `reference_inertia_flash_error` — gate success UI on `!flash.error`.

---

## 8. Tone logic (single source — semantic tokens)
- **Condition** (`condTone`): new → success · good → info(primary) · fair → warning · poor → warning · condemned → critical · else neutral.
- **Status** (`statusTone`): available → success · allocated → info(primary) · in_repair → warning · condemned → critical · retired/disposed → neutral.
- **Date cell**: `days===null` → neutral (no sub) · `days < 0` → critical ("{|days|}d overdue") · `days ≤ warnWin` → warning ("in {days}d") · else neutral. Inspection `warnWin=30`, Expiry `warnWin=60`.
- **Fire-style red-overdue-vs-amber-due** (compliance badges 1 & 2): overdue/expired → critical (outranks) · due-soon → warning · else success.
- **Category tone**: per `CAT_TONE` map (§3.3).
- `info` everywhere → **primary/accent** in the token system (the prototype's `TONE.info = {fg:primary, bg:accent}`).

---

## 9. Microcopy index (verbatim)
- Status pill: "PPE register · synced just now"
- H1: "PPE & Equipment"
- Description: "Catalogue, issue, inspect and retire personal protective equipment — fit-tested, in-date and acknowledged across every site."
- Add button: "Add to register"
- Add-menu blurbs: "New catalogue entry" / "Physical stock at a site" / "Issue an item to a worker"
- Filter label "Filter"; search placeholder "Search PPE…"; "Clear"
- Cluster titles: "Live · register", "Needs attention"
- Tile captions: "in register", "issued out", "ready to issue", "next 30 days", "past cadence", "≤60 days / expired", "awaiting disposal", "allocations"
- Compliance: "RPE fit-test · {n} due", "Inspections · {n} overdue" / "Inspections · current", "Expiry · {n} item(s) expiring" / "Expiry · all in date", "Condemned · {n} awaiting disposal", "Hi-vis & footwear · Covered"
- Table hints: "Right-click a row for the full lifecycle" / "Right-click to edit or retire"
- Table titles/subs: "Inventory · {n} item(s)", "Allocations · {n} active issue(s)", "PPE catalogue · {n} type(s)"
- Empty: "No inventory here" / "No allocations here" / "No types" (+ "Nothing matches this tab and the active filters." / "Nothing matches the active filters.")
- Fit-test cells: "Pass · {date}", "Required", "N/A", "Required — not completed", "Not required"
- Training: "Done" / "Outstanding" / "Completed · {date}"
- Ack: "Acknowledged" / "Pending" / "Pending worker acknowledgement"
- Detail header: "Item record · {section}"
- Detail empty (allocation): "Not currently allocated" / "This item is available to issue."
- Wizard headers: "Step {i} of {N} · {label}"
- RPE warnings: "This is respiratory protective equipment. Under AS/NZS 1715 a current quantitative fit-test is required before issue." · "RPE requires a current fit-test (AS/NZS 1715)" · "Record the fit-test date" · "Fit-testing applies to tight-fitting respiratory protection only. You can continue."
- Type Step-1 InfoCard: "{name} is governed by {standard}. Default inspection cadence: {frequency}."
- Condemn InfoCard: "Condemning removes this item from service. It will move to "awaiting disposal"." · reason error "A reason is required"
- Success pane: "{Sub} created" / "Changes saved" / "{title} has been added to the register / updated." · buttons "Add another" / "Done" / "Save & add another"
- Toasts: "Saved — add another", "{worker} acknowledged", "{serial} marked for disposal", "Link copied", "Export queued", "{name} (de)activated"

---

## 10. NZ standards referenced (must appear; NZ-only)
From the prototype seed + handoff (en-NZ dates, NZD, never GBP/US — **note current page uses `en-GB`; switch to `en-NZ`**):
- **AS/NZS 1715** — RPE selection/use/maintenance + fit-testing (drives the respiratory conditional logic).
- **AS/NZS 1716** — respiratory protective devices.
- **AS/NZS 1801** — industrial safety helmets (head).
- **AS/NZS 1337(.1)** — eye protectors.
- **AS/NZS 4602(.1)** — hi-vis garments.
- **AS/NZS 2210(.3)** — occupational footwear.
- **AS/NZS 1891(.1)** — fall-arrest harness / industrial fall-arrest.
- **AS/NZS 2161(.3)** — protective gloves (cut). *(in seed; not in HANDOFF list but present.)*
- **AS/NZS 1270** — hearing protectors (earmuffs, seed).
- **HSWA 2015** (Health and Safety at Work Act) + **WorkSafe NZ** as the regulator.
- **Ngā Paerewa NZS 8134:2021** (Health and Disability Services Standard).

---

## 11. Interaction / motion / a11y rules
- **Never navigate to a full-page form** — every create/edit/action is a modal POSTing to the existing/new endpoint, refreshing in place (`preserveScroll`, partial reload; controllers `redirect()->back()`).
- Filters, tab changes, detail open/close → `router.get` partial reloads (`preserveState`, `preserveScroll`; filters add `replace`).
- Row + hero right-click via `onContextMenu` → build `ShiftCtxItem[]` contextually → `setCtx({x,y,tag,meta,items})`.
- Wizard validation: per-step `validateStep` blocks Continue; on submit re-validate all steps, jump to first failure.
- Keyboard: tabs `role=tablist/tab` + arrow nav (TabStrip handles); rows `tabIndex={0}` + Enter/Space open detail; modals trap focus, close on Esc / backdrop.
- Motion: modal fade/scale-in ~150ms (`ppe-in`/`ppe-pop`); wizard/detail step slide-in ~220–250ms (`ppe-slidein`); tab/hover colour transitions ~120ms. **Respect `prefers-reduced-motion`** (kit already uses `motion-reduce:animate-none` on the ping dot — apply equivalents).

---

## 12. Pixel / spacing / typography reference
- Font: **Instrument Sans** (400/500/600/700).
- Type scale: H1 `28px/700` (-.02em); section/card titles `14px/700`; StepHead title `17px/700`; body `13–13.5px`; table cells `13.5px`; sub-text `11.5–12.5px`; labels/eyebrows `10.5–11px uppercase` (.03–.07em).
- Radius: hero `18px`; cards `14px` (`rounded-2xl`); modals `16px`; controls `8–9px`; chips `6–7px`; pills/badges `999px`.
- Shadows: hero `0 24px 60px -28px primary/55`; cards/tab-strip `shadow-sm`; modals `0 40px 80px -20px black/50`; popovers `0 18px 44px -12px black/35`.
- Hero gradient: `linear-gradient(135deg, oklch(0.47 0.235 279), oklch(0.512 0.262 277) 46%, oklch(0.44 0.215 284))` — in tokens, `bg-gradient-to-br from-primary/90 via-primary to-primary/80` (the kit's `HeroShell` already encodes this). **App-primary gradient only on the hero** — no per-site brand tint.
- Tile value `25px/700 tabular-nums`; detail spec value `13px/500`; menu item label `12.5px/500`.
- Modal panel: detail `min(94vw,1080px)` × `min(86vh,720px)`; wizard `min(94vw,1080px)` × `min(88vh,820px)`; action modals `min(94vw,560px)`.
- Left rail width **250px** (HANDOFF says 248px — both used; 248–250px). Rail icon tiles 26px round; medallion 40px rounded-11px.

---

## 13. Token mapping (prototype raw value → semantic token / class)
| Prototype raw | Token / Tailwind |
|---|---|
| `--bg` `oklch(0.98 0.006 277)` | `bg-background` |
| `--fg` `oklch(0.15 0.015 277)` | `text-foreground` |
| `--card` `#fff` | `bg-card` |
| `--primary` `oklch(51.1% 0.262 277)` | `bg-primary` / `text-primary` |
| `--primary-fg` `#fff` | `text-primary-foreground` |
| `--muted` `oklch(0.965 …)` | `bg-muted` |
| `--muted-fg` `oklch(0.43 …)` | `text-muted-foreground` |
| `--border` | `border-border` |
| `--sidebar` | `bg-sidebar` |
| `--accent` `oklch(0.94 0.030 277)` | `bg-accent` (≈ `bg-primary/10`) |
| `--success` / `-bg` | `text-status-success` / `bg-status-success-bg` |
| `--warning` / `-bg` | `text-status-warning` / `bg-status-warning-bg` |
| `--critical` / `-bg` | `text-status-critical` / `bg-status-critical-bg` |
| `--info` / `-bg` (= primary/accent) | `text-status-info` / `bg-status-info-bg` (or primary/accent) |
| on-dark `rgba(255,255,255,.X)` | `bg-primary-foreground/X` · `text-primary-foreground/X` · `border-primary-foreground/X` |
| `oklch(0.85 0.01 277)` (scrollbar/toggle-off) | `bg-muted-foreground/30` or token equivalent |
| `oklch(0.7 0.01 277)` (em-dash placeholder) | `text-muted-foreground/70` |
| `oklch(0.98 0.004 277)` (footer band) | `bg-muted/40` |
TONE_BG/TONE_DOT/FlagBadge from `register-row-kit` already encode the status pairs — use them, don't re-derive.

---

## 14. GAP ANALYSIS — prototype/HANDOFF vs current build

**Current state** (`resources/js/pages/health-safety/ppe/index.tsx`, `PpeController`): generic `PageHero`, plain `Tabs` (3 tabs: Inventory / PPE Types / Allocations), plain `Dialog`s (Add item, Add type, Allocate, Inspect), inline Allocate/Inspect/Return buttons, **no** right-click, **no** detail modal, **no** workflow ribbon, **no** compliance badges, **no** stat clusters, `en-GB` dates, types shown as a card grid (not a table).

**Backend gaps to close (HANDOFF Backend §):**
1. `tabCounts` (9 counts) — **missing**; controller only returns 4 `stats`.
2. `hero` block (two clusters + NZ compliance counts/booleans) — **missing**.
3. `detail` prop (`?item=`/`?allocation=` → eager-load type, site, allocations, inspections, createdBy/updatedBy) — **missing**.
4. `can: { manage }` alongside `can_manage` — currently only `can_manage` (keep both).
5. NEW endpoints/methods + routes (same `hazards.manage` middleware): `updateType` (`PUT .../types/{type}` + activate/deactivate `is_active`); `acknowledge` (`POST .../allocations/{allocation}/acknowledge` — columns exist, just set them); `condemn` + `dispose` (`POST .../inventory/{inventory}/condemn` · `…/dispose` — write status + reason/audit).
6. `allocate` currently **drops `acknowledged`** — persist it from the wizard's ack toggle.
7. Search (`q`) + `condition` filter — controller filters site/category/status/ppe_type but **not** free-text search or condition; add for the hero footer search.
8. Enum mismatches to reconcile (pick one consistent set across Segmented options + server validation):
   - Inspection frequency: prototype `daily,weekly,monthly,quarterly,annually` vs current dialog `before_each_use,weekly,monthly,quarterly,six_monthly,annually` vs server `daily,weekly,monthly,quarterly,annually`.
   - Inspection `condition_after`: prototype offers `new` but server enum is `good,fair,poor,condemned` (no `new`).
   - Status enum: server `updateInventory` uses `maintenance` while list filter/UI uses `in_repair` — align (`in_repair` in UI maps to which DB value? confirm migration). `retired`/`disposed` also exist.

**Frontend additions HANDOFF requires beyond the prototype:** keyboard rows (`tabIndex` + Enter/Space), hero "Go to analytics" quick action, `initialAction`/`initialSection` for context-menu-to-section deep-open, real audit timeline + real inspection history (prototype seeds these), real CSV export, full-width layout (no max-w cap), `en-NZ` dates.

**Touch-point parity (HANDOFF §):** confirm during audit — HANDOFF says PPE isn't surfaced anywhere outside this register; verify (HR "my PPE", Site page, H&S dashboard tiles, analytics) and either adopt the same chrome or deep-link here as the single source of truth. (Out of scope for this spec; flag for the build phase.)

---

## 15. Acceptance checklist (from HANDOFF)
- [ ] Hero = `HeroShell` + hs-hero-kit, with NZ `HeroComplianceBadges` (items override), two clusters, `WorkflowRibbon`, hero right-click quick actions.
- [ ] `TabStrip` (9 tabs) with live server counts; filters in hero footer drive `router.get`; server-side pagination retained (inventory + allocations).
- [ ] Every row: left-click → detail modal, right-click → `ShiftContextMenu`; keyboard accessible; semantic tokens only (zero raw hex/oklch/`border-l-*`).
- [ ] Every workflow is a modal following the Add-Client wizard — none navigates away. Detail modal uses the same split-rail shell.
- [ ] PPE types editable + retireable; acknowledgement persists; condemn/dispose first-class; inspections-due actionable.
- [ ] Wherever else PPE appears uses the same chrome or deep-links here.
- [ ] `npm run lint` + typecheck clean; no `no-restricted-syntax` except the sanctioned on-dark hero buttons (copy the eslint-disable comments from the kit / Add-Client header).
