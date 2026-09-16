# Finance design audit — lean inventory brief (2026-09-16)

You are producing a **per-page inventory** for the Finance module so an implementer can migrate each page to the app's design contract. READ-ONLY: do not edit, build, lint or test anything except writing your one findings file. Do NOT read the design guides — the rules you need are summarised here. Read each page file in ONE `cat`/`sed` call (files are 200–1400 lines), take notes, and write your findings file ONCE at the end.

## The contract in one screen (what every finance page must become)

Every page opens with the **Event Horizon `PageHeader`** (`components/page/page-header.tsx`), NOT the legacy `PageHero`. Inside it, top to bottom:
1. Top row: module icon ring · title (sentence case) + ONE `<PageHeaderStatusChip>` · one fact subline (middle-dot facts, no greeting) · right: scoped `PageHeaderSearch` + glass secondary buttons + exactly ONE `PageHeaderPrimaryButton`.
2. **Meter row**: 4–6 `PageHeaderMeterBlock`s carrying the page's key numbers. Forms: Stat (big number) · Delta stat · Bar meter (progress toward a cap/budget — money against a configured budget is ALWAYS a bar meter) · Donut (share of a whole) · Sparkline (7-day series) · Avatar stack (people). **Every block links to the view where its number lives.** A block whose data has no real backend source is dropped, never faked.
3. **Filter row**: ALL the page's filters/search/date-range/view toggles live here (`PageHeaderFilterSelect/Check/Button`, `PageHeaderViewToggle`) — never in a bar below the header. Every rail view must have its own real filter pills.
4. **Rail**: the hub's sibling views as the connected-tab rail (`PageHeaderRail`). Finance will get one config `lib/finance-sections.ts` + `<FinanceSectionRail/>` (already specified by the lead — you don't design it; just record which hub each page is in and any in-page `?tab=` tabs).

Below the header: index pages render **nothing** else before content (no sub-bars); record/profile pages render one tier-2 sub-tab strip (`TierTwoTabs`) then content. Breadcrumbs are passed to `AppLayout` and MUST be rooted at Home: `[Home /dashboard, Finance /finance, <Hub>, <Page>]`.

Lists: every listable record renders through `components/lists/` — `EntityTable` (identity cell first, kebab last, cells from `entity-cells.tsx`: `EntityChip`, `EntityStatusChip`, `CounterPill`, `PersonCell`, `ProgressValue`, `EmptyValue`) or `EntityCard` (status meridian, identity, fact chips, ≤1 metric, alert chips, footer). Every row/card carries BOTH a kebab AND a right-click context menu fed by ONE `MenuItem[]` (`{label, icon, onClick, danger, separator}`). Server pagination = `components/ui/laravel-pagination.tsx`. Each list is preceded by `ListCaption` ("N of N shown"). Empty state = `EmptyState`, never bare text.

Create/edit of an entity = a `WizardShell` modal dialog opened from the index (never a routed full page). Simple single-section forms = a simple `Dialog` (shell/body split, width via inline `style`, `DialogDescription` present, tile picker for category choices, sentence-case verbs). Confirmations: shared `components/confirm-dialog.tsx`.

Everything else: semantic tokens only (no raw palette classes, no hex, no `dark:` pairs, no ghost tokens like `bg-warning`); `<StatusBadge>` from `components/ui/status-badge.tsx` for every status (never hand-rolled spans / `Badge`); typography helpers (`.text-page-title`, `.text-section-title`, `.text-subtle`, `.text-caption`) not `text-2xl font-semibold`; 20px rhythm — no outer padding on the page root, `gap-5` between sections and cards (not `gap-4`/`gap-6`/`space-y-*`); `<LoadingState>` not `animate-spin`; lucide icons only; icon-only buttons need `aria-label`; charts use `components/finance/chart-palette.ts`; copy in NZ English sentence case, no raw enum values, no developer jargon.

## Already known (don't re-report per page)

All 92 pages use `PageHero`; none has Home-rooted breadcrumbs; hub tabs are `*TabsFooter` strips in the hero footer; none uses `EntityTable`/`EntityCard`/`LaravelPagination`/`ListCaption`. A mechanical sweep line per file is given in your task (hero stats labels, table primitive, context-menu use, dark: count, gap counts, etc.). Your job is the **semantic** inventory those greps can't see.

## Per page, record (tight, factual, with `file:line`):

1. **Surface type**: index · record · dashboard · report · routed-wizard · tool.
2. **Current hero**: title, description, each stat (label → the prop/field it comes from), actions (label → what it does), footer.
3. **Required breadcrumbs**: the Home-rooted trail.
4. **Meter blocks (proposed 4–6)**: table `Block | Source prop/field | Form | Tone | Links to`. Only real data. Say if fewer than 4 honest numbers exist.
5. **Filters/search below the header today** (list each with line) → proposed header filter row pills.
6. **In-page tabs** (`useFinanceTab` / `?tab=`): list them and whether they are views (→ rail) or record sections (→ tier-2 strip).
7. **List surface**: primitive today; columns shown; row actions today (inline buttons / dropdown / `useRowContextMenu` items — list the full `RowCtxItem[]`/action set so nothing is lost); pagination; empty state. Propose the `EntityTable` column spec (identity = ?, other columns → cell library) OR `EntityCard` slots. Note bulk actions / selection.
8. **Create/Edit path**: for routed `Create.tsx`/`Edit.tsx` — who links to it (grep the app), what it contains that the matching `components/finance/*-dialog.tsx` WizardShell lacks (line items, GST, attachments, allocations, approvals…). For dialogs in your scope — anatomy gaps against the dialog rules above.
9. **Token/typography/spacing/loader violations** — only the notable ones with line numbers (the sweep already counts `dark:`/gaps).
10. **Status display**: hand-rolled status spans/`Badge`-as-status with lines.
11. **Copy**: notable developer language / raw enums / Title Case / US spelling.
12. **Workflow & wiring**: dead links (verify against `routes/finance.php`), stub actions (`toast('coming soon')`, `TODO`, empty handlers), duplicate paths to the same job, hidden-but-built actions, anything that would confuse a finance officer.

Severity: **P0** = whole-contract breaks (page top, list contract, routed wizard, filters below header, crumbs). **P1** = pattern violations inside the page (status, tokens, spacing, loaders, dialogs, missing context menu, aria). **P2** = copy/polish.

## Output file format

```
# Finance design audit — <area> findings

## Area summary
- Files audited (N): …
- Hub → views observed in this area (from *-hub.tsx): …
- Dead/duplicate files: …
- Cross-page patterns worth one shared fix: …
- Counts: P0 n · P1 n · P2 n

## Pages
### <route> — `<file>` (<type>, N lines)
**Current hero:** …
**Breadcrumbs:** …
**Meter blocks:** | Block | Source | Form | Tone | Links to |
**Header filters:** …
**In-page tabs:** …
**List contract:** primitive → spec; actions to preserve; pagination; empty
**Create/Edit:** …
**Findings:** - [P0|P1|P2] rule — `file:line` — what — fix
**Workflow/wiring:** …

## Dialogs
### `<file>` — anatomy gaps with lines

## Open decisions for the owner
- …
```

Constraints: read-only; no sub-agents; no `npm`/`tsc`/`vitest`/`php`; verify routes before calling a link dead; no generic advice — every line names a file.
