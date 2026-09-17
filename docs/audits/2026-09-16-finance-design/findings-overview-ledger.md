# Finance design audit — Overview + General ledger + Settings findings

## Area summary
- Files audited (21 pages + 5 dialogs): Dashboard.tsx, executive-dashboard/Index.tsx, sites-overview/Show.tsx, site-dashboard/Show.tsx, cash-position/Index.tsx, accounts/{Index,Show,Create,Edit}.tsx, journals/{Index,Show,Create}.tsx, cost-centres/Index.tsx, fiscal-periods/Index.tsx, currencies/Index.tsx, fx-revaluations/{Index,Create}.tsx, fixed-assets/{Index,Show}.tsx, funding-streams/Index.tsx, Integrations/{Index,Mapping}.tsx; dialogs new-account-dialog.tsx, new-journal-dialog.tsx, fixed-asset-dialog.tsx, fixed-asset-dispose-dialog.tsx, funding-stream-dialog.tsx.
- /finance/clients/{client}/financials (ClientFinancialsController::show, app/Domain/Finance/Http/Controllers/ClientFinancialsController.php:35) renders resources/js/pages/clients/Financials.tsx -- not under pages/finance/, out of this area's tree per brief scope. Not inventoried.
- Hub -> views observed:
  - Overview hub (components/finance/overview-hub.tsx, OVERVIEW_TABS): summary -> Dashboard.tsx (/finance), executive -> executive-dashboard/Index.tsx (/finance/executive-dashboard), by-site -> sites-overview/Show.tsx (/finance/sites), cash-position -> cash-position/Index.tsx (/finance/cash-position).
  - Ledger hub (components/finance/ledger-hub.tsx, LEDGER_TABS): accounts, journals, cost-centres, fiscal-periods, currencies, fx-revaluations, fixed-assets.
  - Settings hub (components/finance/settings-hub.tsx, SETTINGS_TABS): integrations, funding-streams.
  - site-dashboard/Show.tsx (/finance/sites/{site}/financial-dashboard) is an orphan relative to every hub -- not a tab in any *_TABS list, reached only via a row link from sites-overview/Show.tsx:246,347,484. It roots its breadcrumbs at Sites (site-dashboard/Show.tsx:137-141), not Finance -- it doesn't even know it's a Finance page.
- Dead/duplicate files:
  - accounts/Create.tsx and accounts/Edit.tsx are fully dead routed pages. Nothing links to /finance/accounts/create or /finance/accounts/{id}/edit anywhere in resources/js (grep confirmed) -- accounts/Show.tsx doesn't even link to its own Edit page. Both are feature-complete duplicates of components/finance/new-account-dialog.tsx (NewAccountDialog), which the live accounts/Index.tsx:424-432 already opens as a modal with identical fields. The codebase has already solved this exact problem for fixed assets: routes/finance.php:556,566 redirect /fixed-assets/create and /fixed-assets/{id}/edit straight to the index, because FixedAssetDialog replaced them. Accounts and journals need the same redirect treatment.
  - journals/Create.tsx is likewise dead -- nothing links to /finance/journals/create (grep confirmed); journals/Index.tsx:656-663 already opens NewJournalDialog, which has full line-item/cost-centre/funding-stream/balance-check parity with the routed page (compare journals/Create.tsx:330-556 to new-journal-dialog.tsx:322-453).
  - fx-revaluations/Create.tsx is routed and IS linked (fx-revaluations/Index.tsx:126) -- legitimately routed (multi-step preview against live FX data, not a simple form), not a duplicate.
- Cross-page patterns worth one shared fix:
  - Every index page here uses ui/table + inline Badge/ad-hoc status spans instead of StatusBadge consistently -- some pages (fiscal-periods, fx-revaluations, fixed-assets) already use StatusBadge; others (accounts, journals, currencies, cost-centres, funding-streams) hand-roll Badge+colour-map. One shared migration to StatusBadge + EntityStatusChip fixes ~8 files at once.
  - useRowContextMenu/RowCtxItem is already wired on 6 of 9 index tables (accounts, journals, cost-centres, fiscal-periods, currencies, fx-revaluations, fixed-assets) but missing on funding-streams/Index.tsx (inline icon buttons only, no onContextMenu) -- trivial to bring in line.
  - Three pages hand-roll pagination controls instead of laravel-pagination.tsx: journals/Index.tsx:621-654, fixed-assets/Index.tsx:662-693, fx-revaluations/Index.tsx:303-322.
  - fx-revaluations/Index.tsx:181 and fx-revaluations/Create.tsx:166 both use raw <table> instead of components/ui/table -- the only two files in this area that do.
  - Every dialog built on WizardShell (new-account-dialog, new-journal-dialog, fixed-asset-dialog, fixed-asset-dispose-dialog, funding-stream-dialog) is already anatomy-clean (Field/StepHead/ReviewCard, sentence-case, no raw tokens) -- these need no rework, just wiring into EntityTable row actions once index pages migrate. The inline Dialog-based CRUD (cost-centres, fiscal-periods, currencies, Integrations "Connect Provider") is simple single-section forms, consistent with the "simple Dialog" contract already -- lower priority.
  - site-dashboard/Show.tsx is the one page in this batch that skips PageHero/PageLayout entirely (<div className="flex flex-col gap-6 p-6">, line 161) -- outer page padding (p-6) violates the 20px-rhythm/no-outer-padding rule on its own, on top of missing the hero contract.
- Counts: P0 20 · P1 25 · P2 10

## Pages

### /finance -- Dashboard.tsx (dashboard, 1425 lines)
**Current hero:** PageHero title "Finance Dashboard" with a live-pulse eyebrow line (Dashboard.tsx:658-666); description names org/site count/funding streams (668-687); meta facts: open period, sites x regions, residents funded (689-704); stats: Revenue, Expenses, Net profit (tone success/critical), Cash (705-723); actions: New Journal / New Bill / New Invoice / Record Receipt buttons opening dialogs (724-760); footer: OverviewTabsFooter + period segmented control (This month/Quarter/FY) + Site/Funding MultiEntityFilters (762-812).
**Breadcrumbs:** today [Finance /finance, Overview] (188-192) -- not Home-rooted. Required: [Home /dashboard, Finance /finance, Overview].
**Meter blocks (proposed 4-6):**
| Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Revenue | totalRevenue | Stat + delta (revenueTrend) | success | /finance/reports/profit-loss |
| Net profit | netProfit | Stat + delta (profitTrend) | success/critical | /finance/reports/profit-loss |
| Cash | cashBalance | Stat, sub cashRunwayDays | info | /finance/cash-position |
| AR outstanding | accountsReceivable | Stat, sub arAging.over60 | warning | /finance/reports/aged-receivables |
| AP due <=7d | apDueWithin7.total | Stat | critical | /finance/bills |
| Funding utilisation | fundingUtilisation.utilisation_pct | Bar (vs 90% target, already implied at Dashboard.tsx:914) | primary | /finance/funding-streams |
The page already computes 8 real KPI numbers (Dashboard.tsx:829-928) -- no fabrication needed, just pick 6.
**Header filters:** period segmented control (762-789) + Site/Funding MultiEntityFilter (791-808) live in the hero footer today -- correct destination is the header filter row, not the footer tab strip (footer should carry only the rail).
**In-page tabs:** OverviewTabsFooter active="summary" (764) -- hub view, -> rail (Overview: Summary/Executive/By site/Cash position).
**List contract:** 3 raw ui/tables, no EntityTable, no context menu, no pagination (bounded lists), no ListCaption.
- Upcoming bills due (1213-1252): columns Bill #/Vendor/Due/Amount, each row links to /finance/bills/{id}; empty state is a bare <p> (1208-1211), not EmptyState. -> EntityTable: identity = Bill # (EntityChip), Vendor (plain), Due (date), Amount.
- Funding claims (1277-1325): columns Ref/Funder-period/Status (StatusBadge via CLAIM_TONE, 1305-1314)/Amount; bare-text empty state (1273-1275).
- Recent journals (1347-1392): columns Journal #/Date/Description/Type (hand-rolled pill span, 1379-1381, not StatusBadge)/Amount; bare-text empty state (1342-1345).
**Create/Edit:** N/A -- this page only opens the 4 quick-action dialogs (NewJournalDialog, NewBillDialog, NewInvoiceDialog, RecordReceiptDialog, all already WizardShell), all correctly implemented as modals.
**Findings:**
- [P0] Header contract -- whole page built on legacy PageHero/PageLayout (648-816), not PageHeader -- full rebuild needed like every page in this batch.
- [P0] Filters below header -- period + site/funding filters live in the hero footer (762-812) instead of a header filter row.
- [P0] List contract -- 3 tables (1213, 1277, 1347) use raw ui/table, no context menu, no ListCaption, bare-text empty states instead of EmptyState.
- [P1] Hand-rolled KpiCard component (Dashboard.tsx:246-306) duplicates what PageHeaderMeterBlock/FleetStatCard already do -- 8 cards at 828-929, all raw text-2xl font-bold (276) not .text-page-title/.text-section-title helpers.
- [P1] journal.type rendered as a hand-rolled pill (Dashboard.tsx:1379-1381, rounded-full bg-accent ... capitalize) instead of <StatusBadge>.
- [P1] Recharts used directly with var(--chart-N) CSS vars (407-413, 1038-1044) instead of components/finance/chart-palette.ts -- inconsistent with chart-palette-using pages (sites-overview, site-dashboard).
- [P2] Copy: "supported living" badge on Funding claims card is dev-facing jargon (Dashboard.tsx:1260-1262) exposed to end users.
- [P2] PulseDot/"Live ledger" eyebrow (237-244, 660-664) is a bespoke pattern not in the design contract; drop when migrating to PageHeaderStatusChip.
**Workflow/wiring:** All links verified live against routes/finance.php (bills, journals, funding-streams, cash-position, reports/aged-receivables, reports/profit-loss all exist). No stub actions or TODOs found. NeedsAttentionStrip items (521-592) are conditionally built from real data only -- no fabricated alerts.

### /finance/executive-dashboard -- executive-dashboard/Index.tsx (dashboard, 343 lines)
**Current hero:** Title "Executive Financial Dashboard" (133), description names site/client counts (134); stats: Total cost, Underfunded clients (tone critical if >0), Over-budget sites (tone warning if >0), Staffing cost (136-159); footer OverviewTabsFooter active="executive" (160).
**Breadcrumbs:** today [Finance /finance, Executive] (108-111) -- not Home-rooted.
**Meter blocks:** Total cost | site_kpis.total_cost | Stat | neutral | /finance/reports/profit-loss
Cost trend | site_kpis.cost_trend_pct | Delta stat | warning if up | (no drill-down today -- needs one)
Underfunded clients | client_kpis.underfunded_count | Stat | critical | /finance/clients?filter=underfunded (route TBC)
Over-budget sites | overBudgetCount (derived, 120-122) | Stat | warning | /finance/sites
Staffing cost | staffing_kpis.total_staffing_cost | Stat, sub oncost % | info | /hr/compensation (TBC)
Only 5 honest numbers exist; "Cost trend" and "Underfunded clients" currently have no click-through target -- note as fewer-than-6 case, don't fabricate a 6th.
**Header filters:** none present today (no date range control on this page at all, unlike sites-overview/cash-position which share the same period concept) -- should still gain a period filter in the header row for consistency with siblings.
**In-page tabs:** OverviewTabsFooter active="executive" (160) -> rail (Overview hub).
**List contract:** 2 ui/tables, no context menu, no pagination (both capped .slice(0, 10)), EmptyState used correctly (compact variant, 264-271, 329-336).
- Sites by Cost (219-273): Site (link)/Total/Per Resident.
- Client Cost Outliers (276-338): Client (link + Badge variant="destructive" "Underfunded" inline, 308-315 -- should be StatusBadge)/Total Cost/Weekly Gap (red text if >0, 320-323).
-> EntityTable: identity = Site/Client name (EntityChip), Underfunded -> EntityStatusChip, Total/Gap -> plain money cells.
**Create/Edit:** N/A (read-only dashboard).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (128-163).
- [P0] List contract -- both tables raw ui/table, no ListCaption, no row context menu.
- [P1] "Underfunded" inline Badge variant="destructive" (309-314) instead of <StatusBadge> -- the only status marker on the page not using the shared component.
- [P1] Insights list (183-214) hand-rolls severity colour classes with a dark: duplicate baked into every value (severityColor map, 100-106) -- 3 dark: pairs that are redundant since the light values already resolve via CSS vars; ghost-token risk if bg-status-critical-bg etc. ever change independently per mode.
- [P1] Card-level numbers still hand-set typography via Tailwind, not .text-section-title.
- [P2] "Sites by Cost" / "Client Cost Outliers" / "Top Risks & Issues" are Title Case headings (221, 278, 185) -- should be sentence case.
**Workflow/wiring:** Links to /finance/sites/{id}/financial-dashboard (246) and /finance/clients/{id}/financials (303) both verified live in routes/finance.php:99-100,105. No stubs found.

### /finance/sites -- sites-overview/Show.tsx (dashboard, 571 lines)
**Current hero:** Title "All-Sites Comparison" (208), description with site count (209); stats Total cost/Sites over budget (tone warning)/Avg cost per site/Sites (210-228); footer OverviewTabsFooter active="by-site" (229); actions = inline From/To date inputs + Apply button (230-261) -- a full mini date-range form living in the hero actions slot.
**Breadcrumbs:** today [Finance /finance, By site] (137-140) -- not Home-rooted.
**Meter blocks:** Total cost | kpis.total_cost | Stat | neutral | this page (already home)
Sites over budget | kpis.sites_over_budget | Stat | warning | filtered view of table below
Avg cost/site | kpis.avg_cost_per_site | Stat | neutral | --
Sites | kpis.site_count | Stat | neutral | --
4 honest numbers; a 5th (top spender name) could be a text stat from kpis.top_spenders[0].
**Header filters:** From/To date inputs + Apply, currently in hero actions (231-260) -> belongs in the header filter row (date range), not actions.
**In-page tabs:** OverviewTabsFooter active="by-site" (229) -> rail (Overview hub).
**List contract:** stacked bar chart (265-326, uses chart-palette correctly via chartColor), a "Top Spenders" ranked list (<ol>, 328-361, not a table), and a sortable ui/table "Site Comparison" (363-501) with client-side sort (updateSort, 188-196) and inline SVG Sparkline (539-571) per row. No context menu, no pagination (page loads the full site set). EmptyState used for the chart only (319-323); the table's own empty row is plain text (407-415).
-> EntityTable: identity = Site name+region (EntityChip), Total cost, vs Budget -> StatusBadge, Top category, Trend -> keep the inline sparkline (no cell-library equivalent), Dashboard -> kebab "Open".
**Create/Edit:** N/A.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (202-263); date filters live in actions instead of the filter row.
- [P0] List contract -- sortable table has no context menu, no ListCaption; "Top Spenders" <ol> (336-358) is a bespoke list primitive that should fold into the same EntityTable/EntityCard set instead of a third pattern on one page.
- [P1] Client-side column sort via Button+ArrowUp/ArrowDown (507-537) duplicates EntityTable's built-in sort -- drop custom SortableHead once migrated.
- [P1] budgetVariant/statusBadge helper (100-122) is already using StatusBadge correctly -- good precedent to reuse in Dashboard.tsx and accounts/journals.
- [P2] "All-Sites Comparison" / "Cost by Site and Category" / "Site Comparison" Title Case headings (208, 268, 365).
**Workflow/wiring:** row.dashboard_url (346, 484) is server-supplied and points at /finance/sites/{id}/financial-dashboard, verified live (routes/finance.php:99). No stubs.

### /finance/sites/{site}/financial-dashboard -- site-dashboard/Show.tsx (dashboard, 524 lines)
**Current hero:** PageHero (not PageLayout-wrapped -- see below) title "Financial Dashboard" (165), description names site (166), backHref/backLabel to the site profile (168-169); single stat "Period" (170-175) -- the only page in this batch with just 1 stat.
**Breadcrumbs:** today [Sites /sites, {site.name}, Financial Dashboard] (137-141) -- rooted at Sites, not Finance, and not Home. Required: [Home /dashboard, Finance /finance, By site, {site.name}].
**Meter blocks:** Total cost | dashboard.hero_cards.total_cost | Stat | neutral | this page
Cost/resident | dashboard.hero_cards.cost_per_resident | Stat | neutral | --
Staffing cost | dashboard.staffing.total_staffing_cost | Stat, sub oncost % | info | --
Budget status | variance.totals.status | Bar (vs budget, variance.totals.variance_pct) | critical/warning/success | --
These 4 numbers already exist as FleetStatCards (180-221) -- direct port.
**Header filters:** none -- filters.from/to (99, 173) is read-only display, no way to change the period from this page today (unlike its sibling sites-overview, which has an Apply form). Should gain the same date-range filter row.
**In-page tabs:** none -- single scrolling page.
**List contract:** two chart cards (Pie cost-breakdown 249-314, Area trend 317-381, both correctly via chartColor), a 4-tile "Staffing Costs" block (385-436, hand-rolled coloured tiles, not PageHeaderMeterBlock), and one ui/table "Budget vs Actual" (440-519) with a totals footer row. No context menu, no pagination, no empty state component (plain text at 286-288, 376-378).
-> EntityTable for Budget vs Actual: identity = Category, Planned/Actual/Variance/%, Status -> StatusBadge (currently varianceBadge() at 110-124 hand-rolls <Badge> per status, including a raw bg-status-warning text-white inline colour at 116).
**Create/Edit:** N/A.
**Findings:**
- [P0] No PageHeader/PageHero+PageLayout shell at all -- raw <div className="flex flex-col gap-6 p-6"> (161) wrapping a bare PageHero with no PageLayout. Outer page padding (p-6) also breaks the no-outer-padding rule independently of the header migration.
- [P0] Breadcrumbs not Home- or Finance-rooted (137-141) -- this page doesn't identify as a Finance page at all in its own navigation chrome.
- [P0] Not a member of any hub's tab rail -- reachable only by a table-row link from sites-overview/Show.tsx; needs to join the rail (as a site-scoped drill-down) or get an explicit "part of Finance > By site" breadcrumb.
- [P1] varianceBadge() (110-124) hand-rolls status colouring with one raw inline colour (bg-status-warning text-white, 116) instead of <StatusBadge>.
- [P1] 4 hand-rolled coloured tiles in "Staffing Costs" (392-433) each carry a dark: pair (e.g. dark:bg-primary/30, 393; dark:text-primary/70, 397) -- part of the file's 9 dark: occurrences (sweep count) -- ghost-token risk since bg-primary/10+dark:bg-primary/30 double-modifies rather than using a single token that already adapts.
- [P1] Raw text-xl font-bold (397, 405, 419, 427) instead of typography helpers.
- [P2] "Cost Breakdown" / "Cost Trend (6 months)" / "Budget vs Actual" Title Case headings (251, 319, 442).
**Workflow/wiring:** backHref/backLabel (168-169) correctly points at the site profile. No stubs. This page's isolation from the Finance hub is itself a wiring concern worth flagging to the finance-sections.ts owner.

### /finance/cash-position -- cash-position/Index.tsx (dashboard, 330 lines)
**Current hero:** Title "Cash Position" (87), description names account/fund counts (88); stats Cash on hand/Expected in-30d/Expected out-30d/Projected-30d (89-106); footer OverviewTabsFooter active="cash-position" (107).
**Breadcrumbs:** today [Finance /finance, Cash position] (59-62) -- not Home-rooted.
**Meter blocks:** the 4 existing stats map directly: Cash on hand (Stat, links to Bank accounts card below), Expected in 30d (Stat, success tone), Expected out 30d (Stat, warning tone), Projected 30d (Delta/Stat, tone by sign) -- all real, no fabrication needed.
**Header filters:** none present -- page has no date-range control at all (30-day window is hard-coded server-side); acceptable given no filterable dimension exists, but note for the owner in case a "next N days" toggle is wanted.
**In-page tabs:** OverviewTabsFooter active="cash-position" (107) -> rail (Overview hub).
**List contract:** 3 ui/tables, all with EmptyState correctly used (124-129, 194-199, 244-248) -- the cleanest empty-state handling in this batch. No context menu, no pagination (all bounded).
- Bank accounts (131-178): Account (link + Primary StatusBadge, 154-162)/Type/Balance.
- Petty cash (201-229): Fund (link)/Balance.
- Next 30 days obligations (250-323): Due/Obligation (conditional link, 269-280)/Counterparty/Direction (StatusBadge "Money in"/"Money out", 291-313)/Amount.
-> EntityTable for all three; Direction badge logic (291-313) is a good StatusBadge precedent to reuse elsewhere.
**Create/Edit:** N/A (read-only).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (81-109).
- [P0] List contract -- 3 tables lack context menu and ListCaption (row counts aren't surfaced anywhere).
- [P2] "Bank accounts" / "Petty cash" / "Next 30 days -- dated obligations" section titles fine (already sentence case) -- no copy issues found on this page.
**Workflow/wiring:** All links verified: /finance/bank-accounts/{id} (146, routes/finance.php:435), /finance/petty-cash/{id} (215 -- not found in routes/finance.php, needs verification against the petty-cash route file if separate; flag to owner as a possible dead link pending confirmation), item.link obligations are server-supplied per-row hrefs.

### /finance/accounts -- accounts/Index.tsx (index, 439 lines)
**Current hero:** Title "Chart of Accounts" (326), description "Manage your organisation's account structure" (327); stats Total accounts/Account types (328-334); actions Export CSV + (if canManage) Add Account -> opens NewAccountDialog (335-353); footer LedgerTabsFooter active="accounts" (354).
**Breadcrumbs:** today [Finance /finance, Chart of Accounts] (272-275) -- not Home-rooted.
**Meter blocks:** Total accounts | totalAccounts (computed 285-290) | Stat | neutral | this page
Account types | accountTypes.length | Stat | neutral | --
Only 2 honest numbers exist server-side today; a 3rd/4th (e.g. total balance by type, or count of inactive accounts) would need a backend addition -- flag as "fewer than 4" rather than fabricate.
**Header filters:** Search input + Active/Inactive/All Select (364-397) sit in a CardHeader above the tree, not the page header -- both are real, client-side filters (filterAccounts, 234-259) -> straight port to PageHeaderFilterSelect/search.
**In-page tabs:** LedgerTabsFooter active="accounts" (354) -> rail (Ledger hub: accounts/journals/cost-centres/fiscal-periods/currencies/fx-revaluations/fixed-assets).
**List contract:** bespoke recursive tree (not ui/table, not EntityTable) -- AccountTypeSection/AccountRow (91-224) render collapsible type groups with indented rows. Row actions: click navigates to Show (108), right-click opens useRowContextMenu with a single "Open" item (306-315). No pagination (whole chart loads). Empty state is plain text (402-404), not EmptyState.
This is a genuine tree, not a flat list -- EntityTable doesn't natively support nesting; the fix should keep tree semantics but adopt EntityChip/EntityStatusChip for the System/Inactive badges (139-148, currently raw <Badge>) and CounterPill for balances.
**Create/Edit:** Create.tsx/Edit.tsx are dead routed pages (see Area summary) -- nobody links to them; verified via grep -rn "accounts/create\|/edit\`" resources/js returning only each file's own breadcrumb self-reference. NewAccountDialog (opened here at 424-432) already covers 100% of Create.tsx's fields (code/name/type/sub_type/parent/tax rate/funding stream/description/GST/active) via a cleaner 2-step wizard -- no functional gap. Recommendation: redirect /finance/accounts/create and /finance/accounts/{id}/edit to the index, mirroring routes/finance.php:556,566 for fixed-assets.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (321-356); search/filter Select live inside a Card (359-398) instead of the header filter row.
- [P0] Dead routed pages accounts/Create.tsx (1-407) and accounts/Edit.tsx (1-467) -- duplicate paths to the job NewAccountDialog already does; no inbound links anywhere.
- [P1] Type/System/Inactive badges (139-148, 198-200) are raw <Badge variant="outline" className={typeColors[type]}> with a hand-built typeColors map (80-89) instead of <StatusBadge>/EntityStatusChip.
- [P1] Empty search state is plain text (402-404), not EmptyState/EmptySearch (contrast with journals/Index.tsx which does this correctly at 531-559).
- [P2] "Add Account"/"Export CSV" button labels are Title Case (338-349) -- should be sentence case per copy rule.
**Workflow/wiring:** Export CSV link (338) -> /finance/accounts/export, verified live (routes/finance.php:123). No stubs found.

### /finance/accounts/{account} -- accounts/Show.tsx (record, 275 lines)
**Current hero:** variant="compact", backHref to index (113), title ${code} - ${name} (114), description = account description (115); actions = Type/System/Inactive badges + Current Balance readout (116-139).
**Breadcrumbs:** today [Finance /finance, Chart of Accounts /finance/accounts, {code} - {name}] (83-90) -- not Home-rooted; otherwise correctly Ledger-scoped.
**Meter blocks:** Current balance | account.balance | Stat | neutral | this page
Opening balance (period) | ledger.opening_balance | Stat | neutral | --
Closing balance (period) | ledger.closing_balance | Stat | neutral | --
Only 3 numbers; a 4th (e.g. period movement = closing minus opening) is a trivial derived stat, no backend change needed.
**Header filters:** From/To date Inputs + Filter button (150-173) sit in a plain Card above the ledger table -- real filter, should move to header filter row.
**In-page tabs:** none -- this is a record page; per contract, filters aside, nothing else belongs below the header except the ledger table itself, no tier-2 tabs needed since there's only one view of an account.
**List contract:** one ui/table "Account Ledger" (184-269) with synthetic Opening/Closing Balance rows spliced into the body (203-210, 259-266) rather than a table footer -- unusual pattern worth normalising to a TableFooter. No context menu, no pagination (full period loads), empty state is plain text (213-220).
-> EntityTable: identity = Journal # (link), Date, Description, Debit/Credit/Balance as numeric cells; opening/closing rows as a footer summary, not body rows.
**Create/Edit:** N/A (this Show page has no edit action at all -- see Workflow below).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (108-141); date filter lives in a bare Card (144-176) instead of the header filter row.
- [P0] List contract -- ledger table has no context menu, no ListCaption; opening/closing balances hard-coded into table body rows (203-210, 259-266) instead of a footer.
- [P1] Type/System/Inactive badges (118-129) raw <Badge> + typeColors map, same pattern as accounts/Index.tsx -- should share one EntityStatusChip treatment.
**Workflow/wiring:** This page never links to /finance/accounts/{id}/edit -- confirmed by grep across resources/js/pages/finance/accounts/. Since Edit.tsx is itself unreachable from anywhere else either, editing an account's tax/funding/description/active flag has no discoverable entry point in the UI at all today (only the create-time NewAccountDialog sets these fields). This is a real gap a finance officer would hit -- either wire an "Edit" action here (opening a reused edit-mode dialog, matching the EditableFixedAsset/FixedAssetDialog pattern) or confirm editing is intentionally unsupported post-creation.

### /finance/journals -- journals/Index.tsx (index, 671 lines)
**Current hero:** Title "Journals" (246), description "General ledger journal entries" (247); stats Total/Posted (this page)/Drafts (this page) (248-252) -- note "this page" stats are per-paginated-page counts, not org-wide, which is misleading as a header stat; actions Export CSV + (if canManage) New Journal -> NewJournalDialog (253-273); footer LedgerTabsFooter active="journals" (244).
**Breadcrumbs:** today [Finance /finance, Journals] (233-236) -- not Home-rooted.
**Meter blocks:** Total journals | journals.total | Stat | neutral | this page
Posted (period) | needs a real org-wide posted count from the backend (today's postedCount/draftCount at 214-217 are page-local and would mislead as header stats) | Stat | success | filtered view
Drafts | same caveat | Stat | neutral | filtered view
Recurring runs failed | recurringOccurrenceHistory failed count (derivable from 82-102) | Stat | warning | scroll to section
Flag to the owner: the two "this page" stats must NOT be promoted to header meter blocks as-is (they'd read as global totals) -- needs a backend aggregate or must stay page-scoped with an honest label.
**Header filters:** Search + Status/Type Select + From/To date (436-494) in a Card above the table -- all real (applyFilters/clearFilters, 193-210) -> direct port to header filter row.
**In-page tabs:** LedgerTabsFooter active="journals" (244) -> rail (Ledger hub).
**List contract:** "Recurring journal run history" card (277-429, conditional) has its own responsive dual-rendering (mobile <ul> 290-357 + desktop ui/table 358-427) -- a bespoke pattern that should collapse to one EntityTable (mobile handled by the primitive, not hand-duplicated markup). Main journals table (512-618): columns Journal Number(click-through row)/Date/Type (Badge+typeBadge() map, 585-594)/Description/Total/Status (Badge+statusBadge() map, 602-612) -- whole row is clickable (onClick, 567-571) AND has onContextMenu (572-574) via useRowContextMenu, but no visible kebab -- inconsistent with the "kebab AND context menu" contract. Manual pagination footer (621-654) instead of laravel-pagination.tsx. Empty state correctly uses EmptySearch/EmptyList (528-561) -- best empty-state handling of the plain-Badge group.
-> EntityTable: identity = Journal # + Type (EntityChip), Status -> StatusBadge, Description, Amount; kebab with "Open" (mirroring the existing context-menu item at 221-228).
**Create/Edit:** journals/Create.tsx is a dead routed page (581 lines); nothing links to /finance/journals/create. NewJournalDialog (opened here at 656-663) has full feature parity: multi-line DR/CR entry, cost centre + funding stream per line, live balance check, Save-as-draft / Save-and-post -- compare journals/Create.tsx:330-556 line-by-line to new-journal-dialog.tsx:322-453. Neither surface exposes tax_rate_id/tax_amount in the UI despite both typing the fields (journals/Create.tsx:63-64, new-journal-dialog.tsx:34-35) -- a shared latent gap, not specific to the dead page. Recommendation: redirect /finance/journals/create to the index, same as fixed-assets.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (240-275); filters live in a Card (433-509) instead of the header filter row.
- [P0] Dead routed page journals/Create.tsx (581 lines) -- duplicate of NewJournalDialog; no inbound links.
- [P0] Header stats "Posted (this page)"/"Drafts (this page)" (250-251) are page-local counts mislabelled as if global -- must not carry over to PageHeaderMeterBlocks without a real backend aggregate.
- [P1] Row is click-to-navigate AND right-click context menu, but has no kebab -- violates "kebab AND context menu" contract (563-574).
- [P1] Type/Status use raw <Badge className={typeBadge(...)}> / statusBadge(...) maps (114-130, 584-612) instead of <StatusBadge>.
- [P1] Recurring-run-history section duplicates markup for mobile (<ul>) vs desktop (<table>) (290-427) instead of one responsive primitive.
- [P1] Manual pagination (621-654) instead of laravel-pagination.tsx.
**Workflow/wiring:** Export link (255-261) builds its own query string and points at /finance/journals/export, verified live (routes/finance.php:122). No stubs.

### /finance/journals/{journal} -- journals/Show.tsx (record, 466 lines)
**Current hero:** variant="compact", backHref to index (182), title = journal number + Status + Type badges inline (183-195), description = journal description; actions = "Post Journal" button (draft only, 199-207) or "Reverse" Dialog (posted + not already reversed, 208-278).
**Breadcrumbs:** today [Finance /finance, Journals /finance/journals, {journal_number}] (166-173) -- not Home-rooted; otherwise correct.
**Meter blocks:** Total debits | computed totalDebits (128-131) | Stat | neutral | this page
Total credits | computed totalCredits (132-135) | Stat | neutral | this page
Status | journal.status | Stat/StatusBadge-as-meter | success/critical/neutral | --
Only really 2-3 honest numbers on a journal record -- acceptable "fewer than 4," don't pad.
**Header filters:** none -- appropriate for a record page.
**In-page tabs:** none -- single-section record; meta cards (285-364: Date/Reference/Posted By/Fiscal Period/Created By) are the record's field summary, appropriate as tier-2-adjacent content, not tabs.
**List contract:** one ui/table "Journal Lines" (387-462) with a TableFooter totals row (443-459, correctly using a real footer here unlike accounts/Show.tsx). No context menu, no pagination (bounded by line count), no empty state needed (a journal always has >=2 lines).
-> EntityTable: identity = Account (code+name), Description, Debit/Credit, Cost Centre, Funding Stream.
**Create/Edit:** N/A -- actions are Post/Reverse workflow transitions, not edit. The Reverse confirmation (210-277) is a full custom Dialog with a reason Textarea -- should be components/confirm-dialog.tsx with an optional-reason variant, not a bespoke Dialog.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (177-282).
- [P1] Reverse confirmation is a hand-built Dialog (210-277) instead of the shared ConfirmDialog -- every other destructive/irreversible action in this batch (fiscal-periods close, currencies/cost-centres/funding-streams delete, fx-revaluations post) correctly uses ConfirmDialog; this is the one outlier.
- [P1] Status/Type badges hand-rolled via statusBadge()/typeBadge() maps (105-121, 186-193) instead of <StatusBadge>.
- [P2] "Journal Lines" / "Reversed" notice copy is fine (already sentence-appropriate); no major copy issues.
**Workflow/wiring:** handlePost/handleReverse (137-161) post to /finance/journals/{id}/post and /finance/journals/{id}/reverse, both verified live (routes/finance.php:172,175). Reversed-by link (371-378) verified live. No stubs.

### /finance/cost-centres -- cost-centres/Index.tsx (index, 430 lines)
**Current hero:** Title "Cost Centres" (308), description (309); stats Total/Active (310-313); actions = CreateCostCentreDialog inline Dialog (314); footer LedgerTabsFooter active="cost-centres" (306).
**Breadcrumbs:** today [Finance /finance, Cost Centres] (267-270) -- not Home-rooted.
**Meter blocks:** Total (Stat), Active (Stat) -- only 2 honest numbers; a 3rd (e.g. count with a linked site) would need a backend addition.
**Header filters:** none present -- this index has no search/filter at all today, unlike its Ledger siblings; worth flagging to the owner as a gap once the header contract lands (a code/name search is the obvious minimum).
**In-page tabs:** LedgerTabsFooter active="cost-centres" (306) -> rail (Ledger hub).
**List contract:** one ui/table (326-403), inline Edit (icon button opening EditCostCentreDialog, 383-385) + Delete (icon button, 386-395) actions, plus useRowContextMenu mirroring Delete only (287-296). No pagination (full list), empty state plain text (339-348, not EmptyState).
-> EntityTable: identity = Code+Name, Type, Status -> StatusBadge (currently raw <Badge>+typeColors-style inline classes, 368-379), kebab = Edit + Delete.
**Create/Edit:** Both CreateCostCentreDialog (50-162) and EditCostCentreDialog (164-264) are plain Dialogs (not WizardShell) -- appropriate per the "simple single-section forms = simple Dialog" rule (3 fields: code/name/type + active checkbox). Anatomy check: has DialogDescription (80-82, 190-192) yes; sentence-case title "Create Cost Centre"/"Edit Cost Centre" is Title Case, should be sentence case per copy rule; no tile picker needed (no category choice) fine.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (302-316).
- [P0] List contract -- table has no ListCaption, plain-text empty state (339-348), inline Edit/Delete duplicate the context-menu's Delete-only item (287-296) instead of one unified MenuItem[] covering both.
- [P1] Status badge (368-379) hand-rolled inline conditional classes, not <StatusBadge>.
- [P2] Dialog titles "Create Cost Centre"/"Edit Cost Centre" (79, 189) Title Case.
**Workflow/wiring:** Delete posts to /finance/cost-centres/{id} (277), verified live (routes/finance.php:192). No stubs.

### /finance/fiscal-periods -- fiscal-periods/Index.tsx (index, 444 lines)
**Current hero:** Title "Fiscal Periods" (299), description (300); stats Total/Open/Closed/Locked (301-306); actions = CreatePeriodDialog (307); footer LedgerTabsFooter active="fiscal-periods" (297).
**Breadcrumbs:** today [Finance /finance, Fiscal Periods] (252-255) -- not Home-rooted.
**Meter blocks:** Total/Open/Closed/Locked (all real, 4 honest numbers already at header-stat quality) -- direct port, e.g. Open as a Donut share of Total.
**Header filters:** none present, same gap as cost-centres.
**In-page tabs:** LedgerTabsFooter active="fiscal-periods" (297) -> rail (Ledger hub).
**List contract:** one ui/table (319-410), StatusBadge used correctly for period status (368-370) -- the best-behaved status display in this batch. Inline Edit (disabled unless status==='open', 377-379) + conditional Close button (380-401), plus useRowContextMenu for Close only, guarded the same way (276-287). No pagination, empty state plain text (334-343).
-> EntityTable: identity = Period name, Start/End date, Status -> StatusBadge (already correct), kebab = Edit (when open) + Close (when open).
**Create/Edit:** CreatePeriodDialog (49-147) / EditPeriodDialog (149-246) are plain Dialogs -- appropriate (3 date/name fields). Same Title Case dialog-title issue ("Create Fiscal Period"/"Edit Fiscal Period", 77, 177).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (293-309).
- [P0] List contract -- no ListCaption, plain-text empty state (334-343).
- [P1] Close confirmation correctly uses shared ConfirmDialog (416-440) with a clear, specific warning about rejected postings -- good precedent, no fix needed here.
- [P2] Dialog titles Title Case (77, 177).
**Workflow/wiring:** Close posts to /finance/fiscal-periods/{id}/close (261), verified live (routes/finance.php:184). No stubs.

### /finance/currencies -- currencies/Index.tsx (index, 664 lines)
**Current hero:** Title "Currencies" (466), description (467); stats Total/Active/Base (currency code) (468-477); actions = CreateCurrencyDialog (478); footer LedgerTabsFooter active="currencies" (464).
**Breadcrumbs:** today [Finance /finance, Currencies] (53-56) -- not Home-rooted.
**Meter blocks:** Total/Active/Base -- 3 honest numbers, already header-stat quality (also duplicated as 2 KPI Cards at 483-516, redundant with the hero stats -- drop the duplicate cards once meter blocks land).
**Header filters:** none present, same gap as cost-centres/fiscal-periods.
**In-page tabs:** LedgerTabsFooter active="currencies" (464) -> rail (Ledger hub).
**List contract:** one ui/table (526-637), Base currency badge (571-578) + Active/Inactive badge (596-609) both raw <Badge> inline classes. Inline Edit (612-614) + conditional Delete (615-628, base currency can't be deleted) + useRowContextMenu mirroring Delete with the same guard (440-454). No pagination, empty state plain text (544-553).
-> EntityTable: identity = Code+Name (EntityChip), Symbol, Exchange rate, Rate updated, Status -> StatusBadge, kebab = Edit + Delete (guarded).
**Create/Edit:** CreateCurrencyDialog (69-245) / EditCurrencyDialog (247-423) are plain Dialogs (max-w-lg, 99, 273 -- explicit inline width, matches the "width via inline style" rule) -- 7 fields (code/name/symbol/decimals/rate/base/active), appropriate for a simple Dialog. Same Title Case issue ("Create Currency"/"Edit Currency", 101, 275).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (460-480); 2 redundant KPI Cards (483-516) duplicate hero stats.
- [P0] List contract -- no ListCaption, plain-text empty state (544-553).
- [P1] Base/Active badges (571-578, 596-609) raw <Badge> instead of <StatusBadge>.
- [P2] Dialog titles Title Case (101, 275); "All Currencies" card title Title Case (522).
**Workflow/wiring:** Delete posts to /finance/currencies/{id} (433), route via Route::resource('currencies', ...) (routes/finance.php:205), confirmed. No stubs.

### /finance/fx-revaluations -- fx-revaluations/Index.tsx (index, 352 lines)
**Current hero:** Title "FX Revaluations" (112), description (113); stats Revaluations/Posted/Net gain-loss (114-124); actions = Link -> /finance/fx-revaluations/create button (125-132); footer LedgerTabsFooter active="fx-revaluations" (110).
**Breadcrumbs:** today [Finance /finance, FX Revaluations] (46-49) -- not Home-rooted.
**Meter blocks:** Revaluations (Stat)/Posted (Stat)/Net gain-loss (Delta stat, tone by sign) -- 3 honest numbers, already good; a 4th (e.g. draft count awaiting posting) is a trivial derive from existing data.
**Header filters:** none present.
**In-page tabs:** LedgerTabsFooter active="fx-revaluations" (110) -> rail (Ledger hub).
**List contract:** raw <table> (181-300, the only genuinely raw HTML table in this whole area alongside its own Create page), StatusBadge used correctly (262-266), conditional "Post to GL" button (280-292) + useRowContextMenu mirroring it (88-100). Manual pagination (303-322) instead of laravel-pagination.tsx.
-> EntityTable: identity = Date, Gain/Loss (signed money), Status -> StatusBadge (already correct), Journal (link), Created By, Notes, kebab = "Post to GL" when draft.
**Create/Edit:** fx-revaluations/Create.tsx is legitimately routed -- it's a live preview against real open foreign-currency items (server round-trip on date change, handleDateChange -> router.get, Create.tsx:56-64) with an editable notes field, not a simple form a WizardShell modal could replace without a redesign of the preview flow. Same raw-<table> issue for the preview grid (166-265). No functional gap since none exists to compare against -- this is the one Create page in the batch that's correctly routed.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (106-134) on both Index and Create.
- [P0] Raw <table> instead of components/ui/table on both Index (181) and Create (166) -- the only files in the whole audited area with this issue.
- [P1] Manual pagination (303-322) instead of laravel-pagination.tsx.
- [P2] "Revaluation History" Title Case (176).
**Workflow/wiring:** Post-to-GL posts to /finance/fx-revaluations/{id}/post (64), verified live (routes/finance.php ~597 area). Create link (126) verified live (routes/finance.php:597). No stubs.

### /finance/fixed-assets -- fixed-assets/Index.tsx (index, 720 lines)
**Current hero:** Title "Fixed Assets" (231), description (232); stats Total assets/Total cost/Depreciation/Book value (233-250); actions = Export CSV + "Run Depreciation" Dialog (266-346) + (if canManage) Add Asset -> FixedAssetDialog (347-355); footer LedgerTabsFooter active="fixed-assets" (229).
**Breadcrumbs:** today [Finance /finance, Fixed Assets] (128-131) -- not Home-rooted.
**Meter blocks:** Total assets/Total cost/Depreciation/Book value -- all 4 already header-stat quality, also duplicated as 4 KPI Cards (362-433, same redundancy pattern as currencies) -- drop the duplicate cards once meter blocks land.
**Header filters:** Search + Category Select + Status Select + Search button (436-521) in a Card -- all real (applyFilters, 168-177) -> direct port.
**In-page tabs:** LedgerTabsFooter active="fixed-assets" (229) -> rail (Ledger hub).
**List contract:** one ui/table (547-657), EmptyList used correctly (529-545), useRowContextMenu with Open + conditional Edit (201-219) alongside an inline Edit-only icon button (633-650) -- same "two paths to the same action" duplication seen in cost-centres. Manual pagination (663-693) instead of laravel-pagination.tsx. Category badge (594-606) raw <Badge>+categoryColors map; Status uses StatusBadge correctly (629-631).
-> EntityTable: identity = Name (link), Tag, Category -> EntityChip, Purchase date, Cost/Accum. Depr./Book value as numeric cells, Status -> StatusBadge (already correct), kebab = Open + Edit + Dispose (Dispose is only on the Show page today -- should be here too for parity).
**Create/Edit:** routes/finance.php:556,566 already redirect /finance/fixed-assets/create and /finance/fixed-assets/{id}/edit straight to the index -- this is the reference pattern accounts/journals should copy. FixedAssetDialog (components/finance/fixed-asset-dialog.tsx) is a clean 3-step WizardShell (Details -> Depreciation & GL -> Review) with a live acquisition-journal preview on create (fixed-asset-dialog.tsx:218-238) -- no anatomy gaps found.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (225-359); filters in a Card (436-523) instead of header row; 4 redundant KPI Cards (362-433) duplicating hero stats.
- [P0] List contract -- no ListCaption; manual pagination (663-693).
- [P1] Category badge (594-606) raw <Badge>+colour map instead of EntityChip/StatusBadge.
- [P1] Edit reachable two ways on the same row (context menu item 211-217, inline icon button 633-650) -- pick one (kebab/context-menu) per contract.
- [P2] "Add Asset"/"Export CSV"/"Run Depreciation" Title Case labels (259-353).
**Workflow/wiring:** Export (259-264) -> /finance/fixed-assets/export, verified (routes/finance.php:124). Run-depreciation posts to /finance/fixed-assets/run-depreciation (194), verified (routes/finance.php:560). No stubs.

### /finance/fixed-assets/{fixedAsset} -- fixed-assets/Show.tsx (record, 655 lines)
**Current hero:** variant="compact", backHref to index (173), title = asset name + Category badge + StatusBadge (174-188), description = tag; actions = conditional "Post acquisition" (needs capitalisation, 197-206) + Edit (207-213) + Dispose (214-220), all gated canManage && status==='active'.
**Breadcrumbs:** today [Finance /finance, Fixed Assets /finance/fixed-assets, {asset_name}] (141-145) -- not Home-rooted; otherwise correct.
**Meter blocks:** Purchase cost | asset.purchase_cost | Stat | neutral | this page
Accumulated depreciation | asset.accumulated_depreciation | Bar (share of depreciable base, already computed at 393-416 as a progress bar) | warning | --
Book value | derived bookValue (138-139) | Stat | success | --
3 honest numbers, matches the existing "Book Value" card (358-419) almost exactly -- direct port including the existing depreciation-progress bar (394-402).
**Header filters:** none -- appropriate for a record page.
**In-page tabs:** none -- Asset Details/Book Value (228-420), AssetFinanceTechnologyProjectionPanel (422-424, cross-module component, out of this area's scope to audit further), Depreciation History table (427-529), Projected Depreciation Schedule table (532-578) are all record sections, not tabs -- could become one tier-2 strip ("Details / History / Schedule") if the page grows further, but a single scroll is acceptable today.
**List contract:** 2 ui/tables (Depreciation History 437-526, Projected Schedule 540-577), both correctly using StatusBadge for reversal/posted/recorded-no-GL states (495-521) -- good precedent. No context menu (append-only history, arguably fine), no pagination.
**Create/Edit:** Edit opens the same FixedAssetDialog in edit mode (581-589) -- correct reuse pattern, no gap. Dispose opens FixedAssetDisposeDialog (626-651) -- a clean 2-step wizard with a live disposal-journal preview (fixed-asset-dispose-dialog.tsx:84-136) that correctly mirrors the backend's FixedAssetService::disposeAsset gain/loss logic in a comment (line 83) -- no anatomy gaps. "Post acquisition" uses the shared ConfirmDialog (591-623) with the exact DR/CR preview spelled out in the description -- good precedent for other one-shot ledger postings (e.g. journals Reverse should match this instead of its bespoke Dialog).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (168-226).
- [P1] Category badge (177-185) raw <Badge>+colour map, same pattern as Index.
- [P2] No copy issues of note -- this page's language is already close to sentence case throughout.
**Workflow/wiring:** Capitalise posts to /finance/fixed-assets/{id}/capitalise (614), verified (routes/finance.php:573). Dispose posts to /finance/fixed-assets/{id}/dispose (149 in dialog), verified (routes/finance.php:570). No stubs.

### /finance/funding-streams -- funding-streams/Index.tsx (index, 293 lines)
**Current hero:** Title "Funding Streams" (112), description (113); stats Total/Active (114-117); actions = (if canManage) Add Funding Stream -> FundingStreamDialog (118-125); footer SettingsTabsFooter active="funding-streams" (126).
**Breadcrumbs:** today [Finance /finance, Funding Streams] (75-78) -- not Home-rooted.
**Meter blocks:** Total/Active -- 2 honest numbers; a NZ-funder breakdown (donut by funder_type, already tabulated per row, 176-181) would be a strong 3rd block since funder types are meaningful business categories here (Whaikaha/ACC/MSD etc.).
**Header filters:** none present -- no search across funder/code/name today, worth flagging as a gap.
**In-page tabs:** SettingsTabsFooter active="funding-streams" (126) -> rail (Settings hub: integrations, funding-streams).
**List contract:** one ui/table (138-251), Active/Inactive badge raw <Badge> inline classes (204-216, same pattern as currencies/cost-centres). Inline Edit (221-230) + Delete (231-240) icon buttons -- no useRowContextMenu at all, the only index in this whole area missing it (sweep-confirmed gap). No pagination, empty state plain text (156-165).
-> EntityTable: identity = Code+Name (EntityChip), Funder type, Default revenue account, Status -> StatusBadge, kebab = Edit + Delete.
**Create/Edit:** FundingStreamDialog (components/finance/funding-stream-dialog.tsx) is a clean 2-step WizardShell (Details -> Review) with a WizardSuccessPane + "Add another" flow (202-229) -- the only dialog in this batch with a success pane, a good pattern worth copying elsewhere (e.g. NewAccountDialog/NewJournalDialog close silently on success today). No anatomy gaps.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (107-128).
- [P0] List contract -- no useRowContextMenu (only index in the area missing it), no ListCaption, plain-text empty state (156-165).
- [P1] Active/Inactive badge (204-216) raw <Badge> instead of <StatusBadge>.
- [P2] "Add Funding Stream" Title Case (122).
**Workflow/wiring:** Delete posts to /finance/funding-streams/{id} (82), verified (routes/finance.php:200). No stubs.

### /finance/integrations -- Integrations/Index.tsx (index, 571 lines)
**Current hero:** Title "Accounting Integrations" (529), description (530); stats Total/Active/Failed (531-535); actions = CreateIntegrationDialog (536); footer SettingsTabsFooter active="integrations" (537).
**Breadcrumbs:** today [Finance /finance, Integrations] (101-104) -- not Home-rooted.
**Meter blocks:** Total/Active/Failed -- 3 honest numbers; a 4th (e.g. total syncs today across all integrations) would need a backend aggregate.
**Header filters:** none -- appropriate, this is a small card-list of connections, not a searchable table.
**In-page tabs:** SettingsTabsFooter active="integrations" (537) -> rail (Settings hub).
**List contract:** not a table at all -- a stacked list of IntegrationCards (558-565), each with its own inline Sync/Test/Account Mapping/Disconnect actions (436-508) and a nested ui/table of recent sync logs (379-431, no pagination, correctly small/bounded). Disconnect uses AlertDialog (470-506) rather than the shared ConfirmDialog -- a second confirm-dialog pattern in the codebase alongside ConfirmDialog, worth reconciling. animate-spin used once for the Sync-Now spinner (443-444, sweep-confirmed) instead of <LoadingState>.
-> Each IntegrationCard maps naturally to an EntityCard (status meridian = connection health, identity = provider name, fact chips = tenant/sync direction/last sync, footer = the action row); the nested sync-log table can stay a compact EntityTable.
**Create/Edit:** CreateIntegrationDialog (123-249) is a plain Dialog -- appropriate (3 fields: provider/tenant/sync direction). Title Case dialog title "Connect Accounting Provider" (151).
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (524-539).
- [P1] animate-spin (443-444) instead of <LoadingState>.
- [P1] Disconnect uses AlertDialog (470-506) instead of the shared ConfirmDialog used everywhere else in this area (cost-centres, currencies, fiscal-periods, funding-streams, fx-revaluations, fixed-assets acquisition) -- a second, inconsistent destructive-confirm pattern.
- [P2] Dialog title Title Case (151); "Recent Activity" Title Case (376).
**Workflow/wiring:** Sync/Test/Disconnect all post to real routes (/finance/integrations/{id}/sync, /test, DELETE /{id}) -- pattern matches the rest of the controller-backed actions in this area; no toast('coming soon') or empty handlers found.

### /finance/integrations/{integration}/mapping -- Integrations/Mapping.tsx (record, 295 lines)
**Current hero:** variant="compact", backHref to Integrations index (149), title ${providerName} Account Mapping (150), description (151); actions = mapped-count Badge + Save button, duplicated again at the bottom of the form (152-166, 282-287) -- two Save buttons on one page.
**Correction to sweep note:** the sweep flagged "Mapping: no Head title" -- this is inaccurate; Mapping.tsx:142 has <Head title={`${providerName} Account Mapping`} />. Worth telling the sweep owner so the fact list doesn't propagate a false negative.
**Breadcrumbs:** today [Finance /finance, Integrations /finance/integrations, {provider} Mapping] (73-80) -- not Home-rooted; otherwise correct.
**Meter blocks:** Mapped / Total | mappedCount/localAccounts.length (124-126) | Bar (share mapped) | primary | this page
Only 1 honest number here (a ratio) -- genuinely a single-stat record page, don't pad to 4.
**Header filters:** none -- appropriate.
**In-page tabs:** none -- grouped-by-type sections (186-280) are content, not tabs.
**List contract:** one ui/table per account type (204-277, repeated per group) -- Code/Name/Sub type/mapped-indicator icon/external-ID Input. This is an editable grid, not a browsable list -- EntityTable doesn't cleanly fit an inline-input-per-row form; likely stays a bespoke table but should still adopt ui/table conventions already in use (no change needed there) plus ListCaption-style "N of N mapped" (already shown as a Badge, could become the meter block itself).
**Create/Edit:** N/A -- this page IS the edit surface for account mapping.
**Findings:**
- [P0] Header contract -- legacy PageHero/PageLayout (144-168).
- [P1] Duplicate Save button (161-165 in hero actions, 283-286 at form bottom) -- redundant now that a header primary button exists; drop one once migrated to PageHeaderPrimaryButton.
- [P2] "Account Mapping" section title fine; type-group Badge labels are raw enum values in lower-case ({type}, 196-199) rather than a friendly label (contrast with Integrations/Index.tsx's providerLabels map) -- e.g. shows "asset" not "Assets".
**Workflow/wiring:** Submits to /finance/integrations/{id}/mapping (119) -- pattern-consistent with the rest of the Integrations controller; no stubs found.

## Dialogs

### components/finance/new-account-dialog.tsx
No anatomy gaps. 2-step WizardShell (Account details -> Options), Field/Segmented/SelectInput used throughout, sentence-case copy, no raw tokens, correct Radix Select empty-value handling via placeholder-only optional fields (comment at line 142-143). Ready to be the sole entry point once accounts/Create.tsx/Edit.tsx are retired -- but note it has no edit mode (no asset/fundingStream-style prefill prop, unlike FixedAssetDialog/FundingStreamDialog) -- needed if accounts/Show.tsx's missing-Edit gap (see above) is fixed by reusing this dialog.

### components/finance/new-journal-dialog.tsx
No anatomy gaps. 3-step WizardShell (Details -> Lines -> Review & post), live balance check via shared journalBalance/PostingPreview (also used by fixed-asset-dialog.tsx and fixed-asset-dispose-dialog.tsx -- good cross-dialog consistency), correct footer balance indicator. Same latent gap as the dead Create.tsx: tax_rate_id/tax_amount are typed (34-35) but never rendered as fields in the Lines step (322-436) -- a real feature gap shared by both surfaces, not a duplication artifact.

### components/finance/fixed-asset-dialog.tsx
No anatomy gaps. 3-step WizardShell (Details -> Depreciation & GL -> Review), handles both create and edit via one component (asset prop, 98-113), correctly locks purchase cost/date once depreciation exists (locked, 115, 356-363, 414, 431), live acquisition-journal preview only on create (218-238) matching the backend's actual posting behaviour (comment 89-97). Best-documented dialog in the batch.

### components/finance/fixed-asset-dispose-dialog.tsx
No anatomy gaps. 2-step WizardShell (Disposal -> Review), live gain/loss + disposal-journal preview mirroring FixedAssetService::disposeAsset (comment 51-57, logic 84-136), correctly warns when no GL account is mapped so disposal won't post a journal (300-308). Good defensive UX.

### components/finance/funding-stream-dialog.tsx
No anatomy gaps -- and the strongest dialog in the batch: 2-step WizardShell with a WizardSuccessPane + "Add another" flow (202-229) that none of the other create dialogs in this area have (NewAccountDialog, NewJournalDialog both close silently on success). Worth copying this success-pane pattern to the others once the header/list migration is underway.

## Open decisions for the owner
- Should accounts/Create.tsx and accounts/Edit.tsx be deleted outright and their routes redirected to the index (mirroring fixed-assets), or does anything outside resources/js (emails, PDFs, external links) still reference them? Grep found nothing, but worth a final confirmation before deletion.
- Same question for journals/Create.tsx -- is there a reason (e.g. a keyboard-shortcut or bookmarklet workflow) it was kept live after NewJournalDialog shipped?
- site-dashboard/Show.tsx is structurally outside the Finance hub today (wrong breadcrumb root, not in OVERVIEW_TABS). Does it belong in the Overview rail as a site-scoped drill-down, or is it intentionally a "Sites module" page that happens to show financial data (in which case its Finance-side design debt is lower priority)?
- cash-position/Index.tsx:215 links to /finance/petty-cash/{id} -- this route was not found in the portion of routes/finance.php grepped for this audit; confirm it exists (possibly under a different prefix/file) before treating it as verified.
- Journals' "Posted (this page)"/"Drafts (this page)" hero stats are page-local, not global -- decide whether to add a real backend aggregate for the header meter row or drop those two blocks entirely.
- Two competing confirm-dialog patterns exist (components/confirm-dialog.tsx used almost everywhere vs. ui/alert-dialog.tsx's AlertDialog used only in Integrations/Index.tsx's Disconnect action) -- worth a single sweep to consolidate.
