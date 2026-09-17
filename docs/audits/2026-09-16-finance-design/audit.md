# Finance module — design & workflow audit (2026-09-16)

**Scope:** every surface under `/finance` — 92 pages (`resources/js/pages/finance/**`, 44,971 lines), the Finance calendar, 43 shared finance components (`resources/js/components/finance/`), the Finance sidebar sub-panel, and the routes in `routes/finance.php` (296). Audited against Rory's design rules: `DESIGN.md` and `design_styles/*.md` (page header, list, navigation, popup, button, loader, calendar, tokens, app shell).
**Method:** lead auditor (Fable 5.1) read the rules and reference implementations, ran a mechanical sweep over all 92 pages, audited the calendar/shared components/sidebar directly, and delegated the per-page inventory to three agents whose claims were spot-verified against source (8/8 verified). Read-only: no code was changed.
**Baseline (May 2026):** `FINANCE_READINESS_AUDIT.md` covered GL wiring and data integrity; this audit does not repeat it. Where the two overlap (e.g. payroll → GL) the May findings stand.

**Companion files (read in this order):**
1. `findings-calendar-shared-sidebar.md` — the module-wide shape problem, hub → rail proposal, calendar migration spec, shared components, sidebar.
2. `findings-overview-ledger.md` — Overview, General ledger, Settings hubs (21 pages, 5 dialogs).
3. `findings-payables-receivables.md` — Payables, Receivables hubs (30 pages, 9 dialogs).
4. `findings-banking-tax-reports.md` — Banking, Tax & compliance, Reports hubs, Donor funds, quarantined Consolidation (35 pages, 6 dialogs).
5. `implementation-plan.md` — the ordered work packages for the implementer.
6. `opus-handoff-prompt.md` — the copy-paste prompt for the implementation session.

---

## 1. Verdict

The Finance module is functionally rich but was built entirely on the **pre-September design spine** and has not been touched by any of the approved-2026-09-04→09-16 rules. It is not a matter of a few pages drifting: **0 of 92 pages** conform to the Event Horizon header, **0** use the entity list contracts, **0** have Home-rooted breadcrumbs, and the calendar is a separate FullCalendar skin. Every page is a migration target; the good news is that the migration is highly repetitive (the same five changes on each page) and the hard parts — the shared `PageHeader`, `PageHeaderRail`, `EntityTable`/`EntityCard`, `WizardShell`, `SiteCalendar` — already exist and are proven on Sites, Clients and Governance.

## 2. Mechanical baseline (all 92 pages)

| Probe | Result |
|---|---|
| Page top is legacy `PageHero` | 92 / 92 (7 of them also bypass `PageLayout` via `PageShell`; 1 has no shell at all) |
| Uses `PageHeader` (Event Horizon) | 0 |
| Breadcrumbs rooted at Home (`/dashboard`) | 0 (85 pass a Finance-rooted trail, 7 pass none) |
| Hub tabs as the Rule 1 connected rail | 0 — all hubs use a Rule 2 `TabStrip` in the hero footer (`*TabsFooter`) |
| Lists on `EntityTable` / `EntityCard` | 0 (65 on `ui/table`, 9 on raw `<table>`, the rest card/div rows) |
| Server pagination via `laravel-pagination.tsx` | 0 (every paginated list hand-rolls a button loop; one fetches pagination and never renders it) |
| Routed full-page Create/Edit wizards | 14 files (7 dead, 7 live) |
| `WizardShell` dialogs already built | 20 (anatomy-clean; several lack field parity with the routed Edit pages) |
| `StatusBadge` from `ui/status-badge` | 36 pages (good); ~25 pages still hand-roll status spans/`Badge` maps |
| Ad-hoc `text-2xl`/`text-xl font-*` headings | 35 pages |
| `dark:` colour pairs on token-styled elements | 22 files (reports worst: CashFlow 20, BudgetVsActuals 9, P&L 9) |
| Off-scale section/card gaps (`gap-4`/`gap-6`/`space-y-*`) | 81 pages |
| Raw palette classes / hex | 0 (ESLint holds) |
| Recharts without `chart-palette.ts` | Dashboard, FundingStreamSummary, ProfitAndLoss |

## 3. Module-wide findings (fix once, apply everywhere)

| # | Finding | Rule | Fix (see plan) |
|---|---|---|---|
| M1 | Every page opens with `PageHero`/`FinanceHero`; hero stats are then **duplicated** as `FinanceSummaryCard`/`OpsStatCard`/`KpiCard` rows below the hero (and on Aging a third time in the table footer) | PAGE_HEADER; anti-patterns "Page tops that bypass…", "Dead or decorative meter blocks" | `PageHeader` with the numbers ONCE, as linked meter blocks; delete the KPI card rows |
| M2 | Hub tabs are a Rule 2 toned `TabStrip` in the hero footer, with hand-picked tones; Receivables rail = 8 views, Reports = 9 | NAVIGATION Rule 1; DESIGN.md "Index view tabs…", "Wrapping rail" | `lib/finance-sections.ts` + `FinanceSectionRail` (copy of the Governance pattern); reshape Receivables/Reports rails (decisions D1–D3) |
| M3 | Sidebar lists 6 Receivables registers as separate links, Calendar and Donor funds float as loose links, and no hub entry stays lit on its tab pages | anti-pattern "One sidebar link per register" | One entry per section; `financeHubContainsUrl` in `matchScore`; widen the module gate |
| M4 | Filters/search/date-range/period controls sit in a `Card` (or the hero footer) below the header on every index and report | PAGE_HEADER §6; anti-pattern "Filterless rail tabs" | Header filter row pills per view |
| M5 | Lists: hand-rolled pagination on every paginated page; kebab OR right-click but rarely both; 7 lists have no row actions at all; bare-text empty states | LIST_STYLE_GUIDE; probes 15/18 | `EntityTable`/`EntityCard` + `ListCaption` + `LaravelPagination` + one `MenuItem[]` for kebab and context menu |
| M6 | 14 routed Create/Edit pages: 7 are **dead** (nothing links to them; the index opens the dialog), 7 are **live** (Show pages link to routed Edit while the index edits via the dialog — two UIs for one job). Dialogs lack fields the routed pages have (vendor address/contacts; PO cost centre/funding stream; bill PO link + per-line allocation; invoice terms/email/per-line account) | POPUP "Entity wizard dialogs"; anti-pattern "Full-page create/edit wizards" | Extend the dialogs to parity, open them from Show, redirect the routed URLs to the index (the fixed-assets precedent at `routes/finance.php:556,566`) |
| M7 | Breadcrumbs: none Home-rooted; 7 pages pass none; reports link the "Reports" crumb to themselves or nowhere; PO Show/Edit crumbs `href: '#'`; site-dashboard roots at Sites | anti-pattern "Missing or non-Home-rooted breadcrumbs" | `[Home, Finance, <Hub>, <Page>, (<Record>)]` on every page |
| M8 | Hand-rolled status: local colour maps + `Badge` on ~25 pages; three independent "Balanced/Unbalanced" badges; sibling pages render the same status differently (EFTPOS Batches vs BatchDetail) | "Hand-rolled status pills" | `<StatusBadge>` / `EntityStatusChip`; add missing keys to `status-colors.ts` once |
| M9 | Two confirm dialogs (`components/finance/confirm-dialog.tsx` vs shared `components/confirm-dialog.tsx`), inconsistent use: PO/invoice actions confirm, bill/credit-note **Approve posts a GL journal with no confirmation**; payment-run bank settlement uses **`window.prompt()`** ×5 | "Rebuilding an existing primitive"; POPUP confirmations | One shared confirm dialog with `processing`; real dialogs for settlement evidence |
| M10 | Copy: Title Case everywhere ("Chart of Accounts", "Purchase Orders"), raw enum values (`awaiting_approval`, `allocatable_type`), "Legacy processing" filter options, "(this page)" stat labels admitting they aren't totals | Governance plain-language rule (now app-wide) | Sentence case, label maps, real server-side counts or drop the block |
| M11 | Calendar is a FullCalendar skin with its own toolbar/legend/colours, no date anchor, month+list only | CALENDAR_STYLE_GUIDE; "Calendars — always the Site Calendar style" | `SiteCalendar` + `finance-calendar-adapter.ts` + four `--src-*` token triples (full spec in findings-calendar-shared-sidebar.md) |

## 4. Workflow and wiring bugs found on the way (verified)

These are not design findings but would confuse or block a finance officer; they are folded into the plan's work packages.

| # | Bug | Where |
|---|---|---|
| W1 | Bank settlement evidence captured through `window.prompt()` (5 prompts, no validation, silent abort mid-sequence) | `payment-runs/Show.tsx:112-178` |
| W2 | Bill Approve and Credit-note Approve post a journal with no confirmation | `bills/Show.tsx:177`, `credit-notes/Show.tsx:135` |
| W3 | Quote "Edit" button links to the quotes list instead of opening the quote for editing | `quotes/Show.tsx:99-104` |
| W4 | Quote edit can never change line items (dialog documents it) | `quote-dialog.tsx:87` |
| W5 | Payment allocations fetch pagination but never render it — only page 1 reachable | `payment-allocations/Index.tsx:37-44` |
| W6 | EFTPOS batch Reconcile always posts `bank_transaction_id: null`; the `unmatchedBankTransactions` prop is never used | `eftpos/Batches.tsx:74,98,380-390` |
| W7 | Petty cash transaction form carries `receipt_path` but renders no upload; the Receipt column can never be filled | `petty-cash/Show.tsx:104,341,392` |
| W8 | Backend routes with no UI entry: `eftpos.terminals.update`, `donor-funds.update`, `donor-funds.transactions.reverse` | `routes/finance.php:734,754,763` |
| W9 | Account edit has no discoverable entry point (Show never links to Edit; Edit is unreachable) | `accounts/Show.tsx`, `accounts/Edit.tsx` |
| W10 | Dead pages/routes: `billing/Entries.tsx`, `accounts/Create+Edit`, `journals/Create`, `vendors/Create`, `purchase-orders/Create`, `bills/Create`, `invoices/Create` | see M6 |
| W11 | Dead ternary: "Match as Adjustment" label identical in both branches | `Reconcile.tsx:513-515` |
| W12 | `receivables/Index.tsx` reimplements `RecordReceiptDialog` as a plain dialog (extra client idempotency key); invoice "Mark paid" bypasses receipt allocation | `receivables/Index.tsx:69-158`, `invoices/Show.tsx` |
| W13 | Donor-fund record tabs use `useState`, lost on refresh | `donor-funds/Show.tsx:194` |
| W14 | Page-local counts labelled as totals in hero stats (credit notes AP/AR, journals Posted/Drafts, vendors Active, reconciliation Completed) | several Index pages |
| W15 | `site-dashboard/Show.tsx` is outside every hub, roots crumbs at Sites, has no shell and `p-6` outer padding | `site-dashboard/Show.tsx:137-161` |
| W16 | Consolidation/Intercompany (4 pages) are quarantined by middleware (404 for everyone) but Tax hub still defines the tab | `routes/finance.php:653-671`, `tax-hub.tsx:58-63` |

## 5. Counts

| Area | P0 | P1 | P2 |
|---|---|---|---|
| Calendar, shared components, sidebar | 6 | 9 | 4 |
| Overview + Ledger + Settings | 20 | 25 | 10 |
| Payables + Receivables | 24 | 41 | 15 |
| Banking + Tax + Reports + Donor funds | 22 | 34 | 14 |
| **Total** | **72** | **109** | **43** |

(P0 = whole-contract breaks; P1 = pattern violations inside a page; P2 = copy/polish.)

## 6. Decisions for the owner (recommended defaults in bold — the plan proceeds on these unless overridden)

| ID | Question | Recommendation |
|---|---|---|
| D1 | Receivables rail is at the 8-view cap | **Fold Statements under Aged AR as a tier-2 strip; Aged AR view = today's `Aging.tsx` content; land the hub on Invoices** (7 views) |
| D2 | Banking rail is at the 8-view cap | **Move Match rules to Settings**; EFTPOS Terminals/Batches become a tier-2 strip inside one EFTPOS view (7 views) |
| D3 | Reports rail has 9 views | **5 views with tier-2 strips: Statements (P&L / Balance sheet / Trial balance / Cash flow), Ageing (Receivables / Payables), Funding summary, Budget vs actuals, Cash-flow forecast** |
| D4 | Where does the Calendar live | **Overview rail view; drop the separate sidebar link** (as Governance does) |
| D5 | Where do Donor funds live | **Tax & compliance rail** (they are trust/compliance accounting) |
| D6 | Dead routed Create/Edit pages | **Delete the 7 dead files; redirect the 14 routes to the index (fixed-assets precedent); extend dialogs to parity first for the 7 live Edit pages** |
| D7 | Payment run creation (multi-select bill picker, no dialog today) | **Build a `WizardShell` with an `EntityTable` selection step** (the table already supports `selection`); keep the route as a redirect |
| D8 | GST Prepare and FX revaluation Create (routed, multi-step against live data) | **Keep routed as "tool" pages** on the Event Horizon header with Home crumbs — not entity add forms |
| D9 | Consolidation/Intercompany | **Leave quarantined, exclude from the migration, remove the hidden Tax tab** |
| D10 | Backend routes with no UI (W8) | **Build the UI** (terminal edit dialog, donor-fund edit via the existing dialog, reverse-transaction confirm) — the routes are tested and intended |
| D11 | Petty-cash receipts (W7) | **Add the upload via `components/ui/file-dropzone.tsx`** |
| D12 | Quote line-item editing (W4) | **Make `QuoteDialog` edit mode persist lines** (same pattern as `NewInvoiceDialog`) |
| D13 | `site-dashboard/Show.tsx` | **Becomes the By-site drill-down under Overview** (profile variant header, crumbs Home → Finance → By site → {site}) |
| D14 | Confirmation policy | **Every action that posts a journal or changes lifecycle state confirms** with the shared dialog stating the effect |

## 7. What this audit did not do

- No browser walkthrough (read-only static audit; the implementer verifies each hub in the browser as Demo Admin per the plan).
- No re-audit of GL correctness (May audit stands).
- `pages/clients/Financials.tsx` (rendered by `/finance/clients/{client}/financials`) lives outside the finance folder and was not inventoried; it should be checked when the Clients profile is next touched.
- The four HR/client/portal FullCalendar pages are outside scope but are also calendar migration targets.
