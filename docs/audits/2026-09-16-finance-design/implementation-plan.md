# Finance module — implementation plan (2026-09-16)

For the implementer. You have not seen the audit conversation; everything you need is in this folder plus the design guides. Work through the packages **in order** — WP0 unblocks all the others, WP1 is self-contained, WP2–WP8 are one hub each, WP9 closes out. Each package ends with its own verification and a commit.

Owner decisions D1–D14 (`audit.md` §6) are **assumed as recommended** unless the owner has said otherwise in your session.

## 0. Rules of engagement

- Read first: `DESIGN.md` (all of it), `design_styles/PAGE_HEADER_STYLE_GUIDE.md`, `LIST_STYLE_GUIDE.md`, `NAVIGATION_STYLE_GUIDE.md`, `POPUP_STYLE_GUIDE.md`, `CALENDAR_STYLE_GUIDE.md`; then this folder's four `findings-*.md` for the hub you are on.
- Reference implementations (copy their composition, don't fork their components): `pages/sites/index.tsx` (index page: header + meters + filters + rail + `EntityTable`/`EntityCardGrid`), `pages/Governance/Actions/Index.tsx` (a hub register page with `GovernanceSectionRail` + `LaravelPagination`), `pages/operations/clients/show.tsx` (profile-variant header + tier-2 strip), `pages/Governance/Calendar/Index.tsx` + `lib/governance-calendar-adapter.ts` (module calendar), `components/finance/bank-account-dialog.tsx` (a clean finance `WizardShell` with create+edit).
- Tokens only; `<StatusBadge>` for every status; typography helpers; `gap-5`; no outer padding; lucide only; sentence case, NZ English; every icon-only control has an `aria-label`. Check DESIGN.md's anti-pattern list before finishing each page.
- Machine limits (from project memory): at most 3 agents, one heavy command at a time, no background builds, `vitest --maxWorkers=2`, run Pest **scoped** (`php artisan test tests/Feature/Finance` — never `--parallel`), use `~/.config/herd/bin/php84/php.exe` if Node-spawned php misbehaves.
- Keep Inertia page component names unchanged where feature tests assert them (`finance/Dashboard`, `finance/Calendar`, `finance/accounts/Index`, `finance/journals/Index`, `finance/invoices/Index`, `finance/bills/Index`, `finance/bank-feeds/Index`, `finance/fixed-assets/Show`, `finance/cash-position/Index`, `finance/sites-overview/Show`). Deleting a page file that a test names requires updating the test.
- No new RBAC keys (a new permission would need a grant migration). `can.finance.*` (`HandleInertiaRequests.php:1081-1115`) already covers every visibility predicate needed.
- Commit after each package with a message naming the package (`feat(finance): WP3 General ledger hub on the Event Horizon contract`). Don't push unless told.

## 1. The per-page migration recipe (apply to every page)

1. **Breadcrumbs** — `AppLayout breadcrumbs={[{title:'Home',href:'/dashboard'},{title:'Finance',href:'/finance'},{title:'<Hub>',href:'<hub landing>'},{title:'<Page>',href:'<page url>'}(,{title:'<Record>'})]}`. Verify the strip renders (a single crumb is hidden by the shell).
2. **Header** — replace `PageHero` with `PageHeader` (`@/components/page`):
   - `variant="index"` for lists/dashboards/reports; `variant="profile"` + `backHref` for records (Show pages).
   - `icon` = the module icon; `title` sentence case; `titleChip` = one `<PageHeaderStatusChip>` (record status, or "N active"); `subline` = one middle-dot fact line (never a greeting or a "Live" eyebrow).
   - `actions` = `<PageHeaderSearch>` (scoped placeholder) + glass secondaries (`PageHeaderGlassButton`: Export CSV, Print…) + exactly one `<PageHeaderPrimaryButton>` (New …). Remove all per-page `className` tinting on buttons.
   - `meters` = 4–6 `<PageHeaderMeterBlock href|onClick tone label>` composed from `PageHeaderMeterBig/Bar/Donut/Spark/Delta/Caption`, using the block table in the page's findings entry. Every block links somewhere real; a page-local count is either replaced by a server total or dropped (never labelled "(this page)"). Money-against-budget → bar meter.
   - `filters` = every filter/search/date/period/view control the page has today, as `PageHeaderFilterSelect/Check/Button` + `PageHeaderViewToggle`, all 23px. Report date ranges are two `PageHeaderFilterButton`s (From/To, `type="date"`) + apply on change. Delete the filter `Card` below.
   - `rail` = `<FinanceSectionRail counts={…} />` (WP0). Record pages keep the rail with their list tab active and add `<TierTwoTabs>` for in-record sections (state in `?tab=`).
3. **Delete the duplicates** — the KPI/summary card row that repeats the hero numbers (`FinanceSummaryCard`, `OpsStatCard`, `KpiCard`, hand-rolled `Card` grids), `FinanceHubsBar`, any "Back to …" button that the profile back chip now covers.
4. **List** — `ListCaption` ("N of N shown") + `EntityTable` (identity cell first; columns from the cell library; `actionsFor(row)` returns the ONE `MenuItem[]` used by kebab and right-click via `useEntityContextMenu` + `<EntityContextMenu>`; `hrefFor`/`onOpen` for row open) or `EntityCardGrid` for card lists (`meridian` from worst alert, fact chips, ≤1 metric, alert chips, footer). Wide tables set `minWidth`. Replace hand-rolled pagers with `<LaravelPagination links={…} lastPage={…}/>`. Empty states via `EmptyList`/`EmptySearch`. Preserve every existing row action (the findings list each page's `RowCtxItem[]`).
5. **Status & tokens** — every status through `<StatusBadge>`/`EntityStatusChip` (add missing keys to `lib/status-colors.ts` once); delete local colour maps and `dark:` pairs; headings via `.text-section-title`; section stacks/card grids `gap-5`; no root padding; loaders via `<LoadingState>`; charts via `chartColor()` from `components/finance/chart-palette.ts`.
6. **Dialogs** — entity add/edit = the existing `WizardShell` dialog (extend to field parity where the findings say so); Edit reuses Add prefilled; confirmations via the shared `components/confirm-dialog.tsx`; simple forms = shell/body split with `DialogDescription`.
7. **Copy** — sentence case titles/labels, enum values through a label map, no "(this page)", no "Legacy …" options, NZ spelling.
8. **Verify** — `npx tsc --noEmit` (whole project, one run), `npx vitest run resources/js/components/finance --maxWorkers=2` when touching components, scoped Pest for the controller if props changed, then open the page as Demo Admin on `oblivionfindings.test`, and check: crumbs Home-rooted, rail active tab flush with the page ground, Find chip present, filters in the header, no horizontal page scroll, kebab AND right-click open the same menu, dark mode.

Header skeleton (index page):

```tsx
const header = (
  <PageHeader
    variant="index"
    icon={Receipt}
    title="Bills"
    titleChip={<PageHeaderStatusChip variant="warning">{overdueCount} overdue</PageHeaderStatusChip>}
    subline={`Accounts payable · ${vendorCount} vendors · ${periodLabel}`}
    actions={<>
      <PageHeaderSearch value={search} onChange={…} placeholder="Search bills, vendors…" />
      <PageHeaderGlassButton href={exportUrl} icon={Download}>Export CSV</PageHeaderGlassButton>
      {canManage && <PageHeaderPrimaryButton icon={Plus} onClick={() => setNewBillOpen(true)}>New bill</PageHeaderPrimaryButton>}
    </>}
    meters={<>
      <PageHeaderMeterBlock tone="warning" label="Overdue" href="/finance/bills?status=overdue" ariaLabel="View overdue bills">
        <PageHeaderMeterBig>{money(summary.total_overdue)}</PageHeaderMeterBig>
        <PageHeaderMeterCaption>{summary.overdue_count} bills</PageHeaderMeterCaption>
      </PageHeaderMeterBlock>
      …
    </>}
    filters={<>
      <PageHeaderFilterSelect label="Status" value={status} options={STATUS_OPTIONS} onChange={…} />
      <PageHeaderFilterSelect label="Vendor" … />
      <PageHeaderFilterButton label="From" type="date" … /> <PageHeaderFilterButton label="To" type="date" … />
    </>}
    rail={<FinanceSectionRail counts={hubCounts.payables} />}
  />
);
return (
  <AppLayout breadcrumbs={crumbs}>
    <Head title="Bills" />
    <PageLayout hero={header}>
      <ListCaption title="Bills" shown={bills.data.length} total={bills.total} />
      <EntityTable rows={bills.data} rowKey={b => b.id} identity={b => ({icon: Receipt, name: b.bill_number, subline: b.vendor?.name})} columns={COLUMNS} actionsFor={menuFor} hrefFor={b => `/finance/bills/${b.id}`} onRowContextMenu={ctx.open} />
      <LaravelPagination links={bills.links} lastPage={bills.last_page} />
    </PageLayout>
    <EntityContextMenu {...ctx.props} items={ctx.row ? menuFor(ctx.row) : []} />
  </AppLayout>
);
```

(Exact prop names: read `components/page/page-header.tsx`, `components/lists/entity-table.tsx`, `entity-menu.tsx` — they are the source of truth, not this sketch.)

---

## WP0 — Foundations (do first; nothing else lands cleanly without it)

**0.1 Section config + rail.** Create `resources/js/lib/finance-sections.ts` mirroring `lib/governance-sections.ts` (`FinanceSection{key,label,icon,tabs[{key,label,href,icon,prefixes,visible(can)}]}`, `FINANCE_SECTIONS`, `financeSectionForUrl(url)`, `visibleSectionTabs(section, can)`) and `resources/js/components/finance/finance-section-rail.tsx` (`FinanceSectionRail({counts?})`, a copy of `components/governance/GovernanceSectionRail.tsx` bound to the finance config). Sections and views (labels sentence case; permission predicates from `can.finance.*`):

| Section key · label · landing | Views (key · label · href · visible) |
|---|---|
| `overview` · Overview · `/finance` | summary `/finance` · executive `/finance/executive-dashboard` · by-site `/finance/sites` (prefixes also `/finance/sites/`) · cash-position `/finance/cash-position` · calendar `/finance/calendar` — all `dashboard` |
| `ledger` · General ledger · `/finance/ledger` | accounts `/finance/accounts` (ledger.view) · journals `/finance/journals` (ledger.view) · fixed-assets `/finance/fixed-assets` (assets.view) · cost-centres (admin) · fiscal-periods (admin) · currencies (admin) · fx-revaluations (ledger.manage) |
| `payables` · Payables · `/finance/payables` | bills · purchase-orders · vendors · credit-notes · payment-runs (ap.view) |
| `receivables` · Receivables · `/finance/receivables` | invoices `/finance/invoices` · quotes · recurring-charges · billing `/finance/billing` · aged-ar `/finance/receivables` (tier-2: Ageing `/finance/receivables/aging`, Statements `/finance/receivables/statements`) · price-books · allocations `/finance/payment-allocations` (ar.view) |
| `banking` · Banking · `/finance/banking` | accounts `/finance/bank-accounts` · transactions · reconciliation · matching `/finance/payment-matching` · feeds `/finance/bank-feeds` · eftpos `/finance/eftpos/terminals` (prefix `/finance/eftpos`; tier-2 Terminals/Batches) · petty-cash (petty_cash.view) — bank.view/manage otherwise |
| `tax` · Tax & compliance · `/finance/tax` | gst-returns (tax.view) · ird-filings (tax.manage) · audit-exports (reports.view) · donor-funds (reports.view) |
| `reports` · Reports · `/finance/reports` | statements `/finance/reports/profit-loss` (prefixes: profit-loss, balance-sheet, trial-balance, cash-flow; tier-2 P&L/Balance sheet/Trial balance/Cash flow) · ageing `/finance/reports/aged-receivables` (prefixes aged-receivables, aged-payables; tier-2) · funding-summary · budget-vs-actuals · cash-flow-forecast `/finance/cash-flow-forecast` (prefix incl. `/finance/cash-flow-forecast/`) — reports.view |
| `settings` · Settings · `/finance/settings` | integrations · funding-streams · match-rules `/finance/match-rules` (D2) — admin (match-rules: bank.manage) |

Tab keys must equal the keys in `app/Domain/Finance/Services/FinanceHubCountsService.php` so `financeHubCounts[section][tab]` feeds `counts` unchanged (`ledger.accounts` and `banking.accounts` collide by name only — counts are keyed per hub). Redirect controllers (`LedgerController`, `PayablesController`, `BankingController`, `TaxController`, `ReportsController`, `SettingsController`) must land on the first view in this order; `ReceivablesController` doesn't exist — add a redirect route `/finance/receivables-hub`? No: keep `/finance/receivables` as the Aged AR view and make the sidebar entry point at `/finance/invoices`. Match rules: move the tab from banking to settings (`FinanceHubCountsService` key moves too); tax hub drops `consolidation`.

Tests: `resources/js/lib/finance-sections.test.ts` (URL → section/tab resolution incl. prefixes and the tier-2 groups; visibility filtering) — mirror the governance test if one exists (`grep -rl governance-sections resources/js --include=*.test.*`).

**0.2 Sidebar.** In `resources/js/components/app-sidebar.tsx`: rebuild `buildFinanceSubPanelGroups` to emit ONE `NavItem` per section (visible if any tab is visible; keep group builders for ordering only; labels sentence case; distinct icons: Overview `LayoutDashboard`, General ledger `BookOpen`, Payables `Receipt`, Receivables `Banknote`, Banking `Landmark`, Tax & compliance `Percent`, Reports `BarChart3`, Settings `Settings`). Add `financeHubContainsUrl(itemPath, currentPath)` beside `governanceHubContainsUrl` in `matchScore` (~L312) so the section entry stays lit on every tab (incl. record pages under a tab's prefix). Widen the Finance module gate (~L791) to "any section visible" (today it ignores bank/tax/reports/assets/petty-cash/admin). Remove the Calendar and Donor funds loose links.

**0.3 Shared primitives.** (a) Add `processing?: boolean` to `components/confirm-dialog.tsx` (disable both buttons; ring-only loader on confirm), migrate every finance caller from `components/finance/confirm-dialog.tsx` (pass `variant` explicitly — the defaults differ), delete the finance copy, and switch `Integrations/Index.tsx`'s raw `AlertDialog` to it. (b) `components/finance/index.ts`: re-export `StatusBadge` from `@/components/ui/status-badge` (not HR's); remove the `finance-hero` export and delete `finance-hero.tsx` (0 consumers). (c) `lib/status-colors.ts`: add `due` (INFO), `processed` (SUCCESS), `scheduled` (INFO), plus any finance keys you meet that are missing (`awaiting_approval` → WARNING, `awaiting_bank` → INFO, `settled` → SUCCESS, `balanced` → SUCCESS, `unbalanced` → CRITICAL, `generating` → live/INFO, `restricted` → NEUTRAL). One place only.

**0.4 Calendar source tokens.** In `resources/css/app.css` add, next to the Governance set (~L345–360) and in `.dark`: `--src-invoice-due` (hue ≈165, green family), `--src-bill-due` (≈40, amber), `--src-payment-run` (≈280, violet), `--src-gst-due` (≈225, blue) — each with `-bg` and `-ln` companions at the same lightness/chroma pattern as `--src-meetings`. Document in `design_styles/DESIGN_TOKENS.md` and add them to DESIGN.md's colour-token table "Calendar sources" row.

**0.5 Guardrails.** ESLint (`eslint.config.js`): add a `no-restricted-imports` entry for `@/components/page/page-hero` and `@/components/finance/finance-hero` scoped to `resources/js/pages/finance/**` with a message pointing at DESIGN.md "Page headers" (extend app-wide in WP9 once finance is clean and other modules are assessed). DESIGN.md anti-patterns (add, one line each, "corrected 2026-09-16, Finance"): **browser `prompt()`/`confirm()` as a form** (evidence and references go through a dialog with fields and validation); **hero numbers repeated as body KPI cards** (a number lives once — in the meter row — never again as a card below); **page-local counts labelled as totals** ("Posted (this page)" — either a server total or no block).

**Verify WP0:** `tsc` clean; `vitest run resources/js/lib resources/js/components/finance --maxWorkers=2`; sidebar shows 8 Finance entries, the right one lit on `/finance/accounts`, `/finance/bills`, `/finance/reports/balance-sheet`; a treasurer-like user with only `finance.reports.view` sees the Finance module. Nothing else changes visually yet (pages still on `PageHero` until their WP).

## WP1 — Calendar (self-contained; the owner asked for it explicitly)

Follow `findings-calendar-shared-sidebar.md` § "Migration spec" exactly:
1. Feed: `FinanceCalendarAggregator` (or the controller) maps obligations to `CalendarItem` (`id, source, group:'auto', title, start, end:null, allDay:true, status, owner:null, room:null, ref, site:null, link, editable:false, desc` — amount/direction/counterparty/period in `desc`), returns `{events, totals}`; keep route name `finance.calendar.events`; validate `start/end/sources` like `GovernanceCalendarController@items`. Emit `status:'overdue'` for missed dates (the shared parts already render it critical).
2. Adapter: `resources/js/lib/finance-calendar-adapter.ts` (`FINANCE_CALENDAR_SOURCES`, `createFinanceCalendarAdapter`) modelled on `governance-calendar-adapter.ts`; no `onCreate`; `backLink` to `/finance`; `mineLink` to `/finance`; `exportFilename 'finance-calendar.ics'`.
3. Page: rewrite `pages/finance/Calendar.tsx` to the 64-line Governance shape (`SiteCalendar context="page" scope="global" canCreate={false} dataAdapter=…`, Home-rooted crumbs `[Home, Finance, Calendar]`). Controller `index()` passes `sources` (server-filtered: hide `gst_due` unless `tax.view`) and `initialSources`.
4. Delete `components/calendar/calendar-view.tsx` (last consumer). Keep `@fullcalendar/*` packages (HR/client/portal still use them).
5. Tests: update `tests/Feature/Finance/FinanceCalendarTest.php` + `FinanceCalendarProvidersTest.php` feed-shape assertions; add `resources/js/lib/finance-calendar-adapter.test.ts` (mapping + totals) mirroring the governance adapter test.
6. Verify per CALENDAR_STYLE_GUIDE "Interaction verification": date anchor shows day/month/year and updates on Previous/Next/Today/Jump; Month/Week/Day/Agenda/Timeline all show finance entries; source pills filter; overdue reads critical; clicking an entry opens the record; no create affordances; desktop + narrow viewport.

## WP2 — Overview hub (`Dashboard.tsx`, `executive-dashboard/Index.tsx`, `sites-overview/Show.tsx`, `site-dashboard/Show.tsx`, `cash-position/Index.tsx`)

- **Dashboard.tsx (the module home, 1425 lines).** Header: title "Finance", chip = open period, subline "{org} · {n} sites · {n} funding streams"; actions: scoped search + New journal / New bill / New invoice glass buttons (dialogs) + primary "Record receipt" (or make "New journal" the primary — one primary only); meters (6, all real props): Revenue (delta `revenueTrend` → P&L), Net profit (delta, tone by sign → P&L), Cash (caption runway days → Cash position), Receivables (caption over-60 → Aged receivables), Bills due ≤7 days (critical → Bills filtered), Funding utilisation (bar vs the 90% target → Funding summary). Filters row: period segmented toggle (This month / Quarter / Financial year) + Site + Funding `PageHeaderFilterSelect`s (replace `MultiEntityFilter onDark`). Rail: Overview. Remove: "Live ledger" eyebrow + `PulseDot`, `meta` facts (fold into subline), `FinanceHubsBar` (delete the component), the 8 `KpiCard`s (delete `KpiCard`), `NeedsAttentionStrip` (its items become toned meter blocks or, if the body section is kept, restyle per LIST §1 with `gap-5` and `StatusBadge` tags), `width="wide"`, `gap-[18px]`. Body keeps: revenue/expense chart (via `chartColor()`), funding utilisation, AR ageing, the three lists (Upcoming bills / Funding claims / Recent journals) as `EntityTable`s with `ListCaption`, empty states via `EmptyList`, journal type via `StatusBadge`. Copy: drop the "supported living" badge.
- **Executive**: meters Total cost / Underfunded clients (critical) / Over-budget sites (warning) / Staffing cost; filters: period; remove `dark:` ×3; tables → `EntityTable`.
- **By site** (`sites-overview/Show.tsx`): meters Total cost / Sites over budget / Avg cost per site / Sites; filter row = the existing period Apply form; site rows → `EntityTable` whose identity links to the site drill-down.
- **Site drill-down** (`site-dashboard/Show.tsx`, D13): `variant="profile"` header, `backHref="/finance/sites"`, crumbs `[Home, Finance, By site, {site}]`, meters Total cost / Cost per resident / Staffing cost / Budget status (bar vs budget); add the same period filter row as By site; replace the `p-6` root, the 4 hand-rolled staffing tiles (→ meter blocks or `OpsStatCard`), `varianceBadge()` (→ `StatusBadge`), Title Case headings; rail = Overview with By site active.
- **Cash position**: meters = its 4 money stats; tables → `EntityTable`; empty states already OK.
- Verify: all four Overview views + drill-down in the browser; Dashboard period/site/funding filters still re-query; every meter block navigates.

## WP3 — General ledger hub (`accounts/*`, `journals/*`, `cost-centres`, `fiscal-periods`, `currencies`, `fx-revaluations/*`, `fixed-assets/*`)

- Index pages per the recipe; meters from `findings-overview-ledger.md`; journals' "(this page)" stats → add real counts to `JournalController@index` (posted/draft totals for the current filter) or drop; fx-revaluations raw `<table>` → `EntityTable`; funding-streams-style inline icon buttons → kebab + context menu; three hand-rolled pagers → `LaravelPagination`.
- **Dead pages (D6):** delete `accounts/Create.tsx`, `accounts/Edit.tsx`, `journals/Create.tsx`; change their routes to `Route::redirect(...)` to the index (the `fixed-assets` precedent, `routes/finance.php:556,566`); keep controller `store/update` methods. Wire **Edit account** into `accounts/Index` row menu and `accounts/Show` header action using `NewAccountDialog` prefilled (W9). Update any Pest test that asserts the removed component names.
- **Records:** `accounts/Show` (profile header, meters Current/Opening/Closing/Movement, the From/To filter into the header row, opening/closing rows → table footer), `journals/Show`, `fixed-assets/Show` (keep the Post-acquisition confirm; meters cost/depreciation/book value/months remaining).
- `fx-revaluations/Create.tsx` stays routed (D8) but on the Event Horizon header with Home crumbs.
- Inline CRUD dialogs on cost-centres/fiscal-periods/currencies: already simple dialogs — check `DialogDescription`, inline width style, sentence-case verbs; co-locate in `_dialogs.tsx` if you touch them substantially.
- Verify: the 7 ledger views + 3 records; `php artisan test tests/Feature/Finance --filter=Account` etc. for the redirect routes.

## WP4 — Payables hub (`bills/*`, `purchase-orders/*`, `vendors/*`, `credit-notes/*`, `payment-runs/*`)

- **Dialog parity first** (so nothing is lost): `new-vendor-dialog.tsx` + step 3 "Address, bank & contacts" (trading name, 5 address fields, bank account number, `is_active`, repeatable contacts with primary flag) — compare `vendors/Edit.tsx:60-141,347-504`; `new-po-dialog.tsx` + Cost centre & Funding stream header fields (`purchase-orders/Edit.tsx:99-104,258-309`); `new-bill-dialog.tsx` + Purchase order link + per-line cost centre/funding stream (`bills/Edit.tsx:303-320,553-601`). Each dialog's Edit mode prefills and PUTs.
- **Retire routed pages:** delete `vendors/Create+Edit`, `purchase-orders/Create+Edit`, `bills/Create+Edit`; routes → redirect to the index (keep `store/update`). Show pages open the dialog for Edit (remove the routed links at `bills/Show.tsx:171`, `purchase-orders/Show.tsx:128`, `vendors/Show.tsx:178`).
- **Payment runs (D7):** new `components/finance/payment-run-dialog.tsx` (`WizardShell`: 1 Bank account + payment date + notes → 2 Select bills (an `EntityTable` with `selection` and a running total) → 3 Review); index primary button opens it; delete `payment-runs/Create.tsx`, route → redirect. **Show page (W1):** replace the five `window.prompt()` calls with one `SettlementEvidenceDialog` (`_dialogs.tsx`: reference, reason/evidence fields with validation, `ConfirmDialog` semantics, `Loader2`); Accept/Reject/Reconcile/Settle all go through it; keep the existing routes and payloads. Remove "Legacy processing/completed" from the status filter unless real rows carry those statuses (then label them "Processed (legacy)").
- **Confirmations (D14, W2):** `bills/Show` Approve and `credit-notes/Show` Approve through the shared `ConfirmDialog` stating "This posts a journal to the ledger".
- Index pages per the recipe: bills (meters Unpaid / Overdue / Due this week / Awaiting approval — add `awaiting_count` to the summary), POs (add draft/approved counts to the controller summary or keep 2 blocks), vendors (server-side active count and type split → donut), credit notes (server totals for AP/AR, W14), payment runs (settled/awaiting bank/draft server counts); overdue rows → alert chip + status, not tinted rows; sentence-case status options.
- Records: profile headers with meters per findings; PO Show crumb `href:'#'` fixed; vendor Show gets `TierTwoTabs` (Details · Contacts · Bills · Purchase orders) with bounded "recent" lists linking to the filtered index.
- Verify: create+edit a vendor/PO/bill through the dialogs incl. the new fields; approve a bill → confirm dialog → journal posted; create a payment run through the wizard; settlement dialog validation.

## WP5 — Receivables hub (`invoices/*`, `quotes/*`, `recurring-charges`, `billing/*`, `receivables/*`, `price-books/*`, `payment-allocations`)

- **Dialog parity:** `new-invoice-dialog.tsx` + terms, email subject/body, per-line account (`invoices/Edit.tsx:123-125,587-647`); `quote-dialog.tsx` edit mode persists line changes (D12/W4) — same line-editor pattern as invoices.
- **Retire routed pages:** delete `invoices/Create+Edit`; routes → redirect; `invoices/Show` Edit opens the dialog. Fix `quotes/Show` Edit (W3) to open `QuoteDialog` prefilled.
- **Rail shape (D1):** Aged AR view = the content of today's `receivables/Aging.tsx` (rename file to `receivables/Index.tsx` content or route `finance.receivables.index` to it); Statements becomes its tier-2 sibling; today's `receivables/Index.tsx` outstanding-invoice table is the Invoices view filtered `status=unpaid` — link there instead of duplicating. Delete the hand-rolled `PaymentDialog` in `receivables/Index.tsx` (W12) → `RecordReceiptDialog`; invoice "Mark paid" → opens `RecordReceiptDialog` for the balance (or is removed).
- `billing/Entries.tsx` (dead): either link it from Billing as a tier-2 "Entries" view with Home crumbs, or delete page + route — recommend **delete** (Billing index already lists entries with status filters).
- `payment-allocations`: render `LaravelPagination` (W5); friendly label for `allocatable_type`.
- The 7 `PageHero`+`PageShell` pages (billing ×2, quotes ×2, price-books ×2, recurring-charges) move to `PageLayout` + `PageHeader` and get breadcrumbs. Card lists (quotes, price books, recurring charges, billing) → `EntityCardGrid`; add kebab + context menus where missing; `price-books/Show` "Add item" inline form → a simple dialog, add edit/deactivate for items (routes exist? check `PriceBookController`; if not, add `updateItem` — small, tested).
- Verify: invoice create/edit incl. email fields; quote edit changes lines; Aged AR/Statements tier-2; allocations page 2 reachable.

## WP6 — Banking hub (`bank-accounts/*`, `bank-transactions`, `bank-feeds/*`, `bank-reconciliation/*`, `payment-matching`, `eftpos/*`, `petty-cash/*`, `match-rules` → Settings)

- `bank-reconciliation/Create.tsx` → `StartReconciliationDialog` (3 fields) opened from the index primary button and from `bank-accounts/Show` "Start reconciliation" (pass `bankAccountId` prop); delete the page, route → redirect (keep `store`).
- `Reconcile.tsx` (tool): Event Horizon header (profile variant, back chip), Home crumbs, the 5 summary cards → meter blocks (Starting / Statement / Calculated / Difference (tone by zero) / Matched (bar)), fix the dead ternary (`513-515`), raw `<select>` → `Select`, `StatusBadge` in the match tables; the two-pane workbench stays bespoke.
- `payment-matching` (tool+index): filters to the header; table rows get kebab/context (Open, Confirm, Reject); matched entity name links; keep the confidence badge.
- `bank-transactions`: filters to the header; `LaravelPagination` (replace the `dangerouslySetInnerHTML` pager); rows get Open + (if unreconciled) Match actions via kebab/context; `statusStyles` → `StatusBadge`.
- `bank-feeds`: cards → `EntityCardGrid` with kebab/context (Logs · Sync · Disconnect); `animate-spin` → ring loader; `Logs.tsx` raw `<table>` → `EntityTable` + `LaravelPagination`.
- **EFTPOS (D2 tier-2):** Terminals: inline add form → `TerminalDialog` (`WizardShell` or simple dialog, create+edit; wire the existing `PUT eftpos/terminals/{id}` — W8); Batches: joins the rail (tier-2 under EFTPOS), filters to header, Reconcile opens a small dialog that picks from `unmatchedBankTransactions` (W6); BatchDetail status → `StatusBadge`.
- **Petty cash:** Show "Record transaction" gets a receipt upload via `components/ui/file-dropzone.tsx` (D11/W7) — check `PettyCashController@transaction` accepts a file; store on the private disk via the existing `ServesPrivateAttachments` pattern; `typeConfig` → `StatusBadge`.
- `match-rules` moves to Settings (WP0 config); its page gets the Settings rail; Create/Edit dialogs share one form body.
- Verify: start a reconciliation from both entry points; EFTPOS terminal edit; batch reconcile picks a bank transaction; petty cash receipt upload + view.

## WP7 — Tax & compliance hub + Donor funds (`gst-returns/*`, `IrdFilings/*`, `audit-exports`, `donor-funds/*`)

- GST: index raw `<table>` → `EntityTable` + `LaravelPagination`, filters (status, year) to header; Show raw tables → `EntityTable`; `Prepare.tsx` stays routed (D8) on the Event Horizon header with Home crumbs.
- IRD filings: the two inline "Create filing" cards → two small dialogs (`_dialogs.tsx`: from GST return / from payroll run); raw `<table>` → `EntityTable`; crumb label "IRD filings" everywhere; soften the simulated-submission copy.
- Audit exports: inline row buttons → kebab fed by the same `MenuItem[]` as the context menu; `LaravelPagination`.
- **Donor funds (D5, W8, W13):** join the Tax & compliance rail; index rows get kebab/context (Open · Edit · Receipt · Expenditure); Edit uses `DonorFundDialog` in edit mode (lift its create-only restriction; route exists); Show tabs → `TierTwoTabs` with `?tab=`; transaction rows get "Reverse" (confirm dialog → `donor-funds.transactions.reverse`); `txnTypeConfig` → `StatusBadge`; restricted/unrestricted donut meter.
- Remove the hidden `consolidation` tab (D9); leave the four quarantined pages untouched and list them in the WP9 exclusions.
- Verify: GST/IRD/audit/donor views; donor fund edit + reverse.

## WP8 — Reports hub (8 `reports/*.tsx`, `CashFlowForecast/*`)

- Build ONE `components/finance/report-page.tsx` composition: `PageHeader` (icon, title, chip = period label, subline, actions = Print glass + Export where a route exists, meters = the report's 3–4 totals, filters = From/To or As-of date pills that re-query on change, rail = Reports section) + `TierTwoTabs` for the Statements/Ageing groups (D3) + body slot. Every report uses it; delete each page's KPI card row and filter `Card`; Balanced/Unbalanced → `StatusBadge`; `dark:` pairs out; `LoadingState` ring on Sync actuals; breadcrumbs `[Home, Finance, Reports, <Group>, <Report>]` (M7).
- Merge `AgedPayables.tsx` and `AgedReceivables.tsx` into one `AgedReport` body parameterised by entity label (keep both routes/pages as thin wrappers so component names asserted anywhere stay valid).
- Report tables: `components/ui/table` inside a white card with `overflow-x-auto` is acceptable for statement layouts (not entity lists); use `EntityTable` only for row-per-record ageing tables.
- Cash-flow forecast: index → recipe (kebab/context Open · Delete draft); Show scenario selector → `PageHeaderViewToggle`.
- Verify: all 9 report views via the rail + tier-2; date pills re-query; print still works.

## WP9 — Close-out, guardrails, docs

1. `grep -rl "page-hero\|PageHero\|FinanceHero\|TabsFooter\|FinanceTabs\|FinanceHubsBar\|FinanceSummaryCard\|NeedsAttentionStrip\|useRowContextMenu" resources/js/pages/finance resources/js/components/finance` → must be empty (except any deliberately kept `NeedsAttentionStrip` restyle). Then delete `finance-tabs.tsx`, all `*-hub.tsx`, `finance-hubs-bar.tsx`, `summary-card.tsx`, `needs-attention-strip.tsx` (if unused), `hub-counts.test.tsx`, the `useFinanceTab` block in `finance-primitives.test.tsx`; drop the HR `useRowContextMenu` re-export from `components/finance/index.ts`.
2. Sweep the finance folder for the remaining probes: `dark:`, `text-2xl|text-xl font`, `gap-4|gap-6|space-y-[46]` on section stacks, `animate-spin`, `rounded-full` pills in chrome, Title Case labels, `(this page)`. Target: zero.
3. Update `DESIGN.md` conformance notes (finance is migrated; add finance to the "reference migrations" if a page is exemplary), the WP0 anti-patterns (if not already), and `FinanceHubCountsService` keys/comments.
4. Tests: `npx vitest run resources/js --maxWorkers=2`; `php artisan test tests/Feature/Finance` (scoped, not parallel); fix anything red.
5. Browser acceptance as Demo Admin on `oblivionfindings.test`, per hub: sidebar entry lit; rail flush + Find chip; filters in header; breadcrumbs; kebab AND right-click on every list; dark mode; narrow viewport no horizontal scroll. Record a checklist in `docs/audits/2026-09-16-finance-design/acceptance-checklist.md` (one row per page, ticked with the commit hash).
6. Exclusions to record as decisions, not oversights: Consolidation/Intercompany (quarantined), `pages/clients/Financials.tsx` (Clients module), HR/client/portal FullCalendar pages.

## Order, size and sequencing

| WP | Files touched (approx.) | Depends on |
|---|---|---|
| WP0 Foundations | 8 new/changed | — |
| WP1 Calendar | 6 | WP0.4 |
| WP2 Overview | 5 pages + 3 component deletions | WP0 |
| WP3 Ledger | 15 pages/dialogs, 3 deletions | WP0 |
| WP4 Payables | 17 pages/dialogs, 7 deletions, 2 new dialogs | WP0 |
| WP5 Receivables | 16 pages/dialogs, 3 deletions | WP0 |
| WP6 Banking | 16 pages/dialogs, 1 deletion, 3 new dialogs | WP0 |
| WP7 Tax + Donor | 9 pages/dialogs | WP0 |
| WP8 Reports | 10 pages + 1 shared composition | WP0 |
| WP9 Close-out | sweeps, deletions, docs, tests | all |

WP2–WP8 are independent of each other and can be split across sessions or parallel agents (max 3, one heavy command each). Suggested order for a single implementer: WP0 → WP1 → WP4 → WP5 → WP2 → WP3 → WP6 → WP7 → WP8 → WP9 (Payables/Receivables first because they carry the live routed-Edit duplication and the settlement-prompt bug).
