# Finance design audit — Banking + Tax & compliance + Reports hubs + Donor funds findings

Inventory agent output (Sonnet, 2026-09-16), saved verbatim by the lead auditor; lead verification notes are in `audit.md`.

## Area summary
- Files audited (35): bank-accounts/{Index,Show}.tsx, bank-transactions/Index.tsx, bank-feeds/{Index,Logs}.tsx, bank-reconciliation/{Index,Create,Reconcile}.tsx, payment-matching/Index.tsx, match-rules/Index.tsx, eftpos/{Terminals,Batches,BatchDetail}.tsx, petty-cash/{Index,Show}.tsx, gst-returns/{Index,Prepare,Show}.tsx, IrdFilings/{Index,Show}.tsx, audit-exports/Index.tsx, donor-funds/{Index,Show}.tsx, reports/{ProfitAndLoss,BalanceSheet,TrialBalance,CashFlow,AgedPayables,AgedReceivables,FundingStreamSummary,BudgetVsActuals}.tsx, CashFlowForecast/{Index,Show}.tsx, Consolidation/{Index,Show,RunResults}.tsx, Intercompany/Index.tsx, and 6 `components/finance/*-dialog.tsx`.
- Hub → views observed: **Banking & Cash** (`banking-hub.tsx`, 8 tabs) = accounts, transactions, reconciliation, matching, feeds, eftpos, petty-cash, match-rules. **Tax & Compliance** (`tax-hub.tsx`, 4 defined/3 visible) = gst-returns, ird-filings, audit-exports, (consolidation hidden, `requires: () => false`). **Reports & Planning** (`reports-hub.tsx`, 9 tabs) = profit-loss, balance-sheet, trial-balance, cash-flow, aged-receivables, aged-payables, funding-summary, budget-vs-actuals, cash-flow-forecast. **Donor Funds has no hub** — it's a standalone `app-sidebar.tsx:2081` "other" nav item; `donor-funds/Index.tsx` and `Show.tsx` carry no `footer=` at all (grep across `components/finance/*hub*.tsx` and `finance-hubs-bar.tsx` returns zero hits for "donor").
- Dead/duplicate files: `Consolidation/{Index,Show,RunResults}.tsx` and `Intercompany/Index.tsx` are **fully unreachable** — `RejectUnsupportedConsolidation` middleware (`app/Domain/Finance/Http/Middleware/RejectUnsupportedConsolidation.php`, wired at `routes/finance.php:653-671`) unconditionally `abort(404)`s every request to `/finance/consolidation*` and `/finance/intercompany*`. `tax-hub.tsx:58-63` keeps a `consolidation` tab definition but `requires: () => false` hides it from the rendered rail, so the dead pages aren't even linkable from the live UI. `AgedReceivables.tsx` is a near-verbatim structural clone of `AgedPayables.tsx` (same KPI cards, same two charts, same aging-column table; only vendor↔client naming differs).
- Cross-page patterns worth one shared fix:
  1. **Every report page's date-range filter is a bespoke `<Card>` below the header** (Start/End Date + "Generate" button, or "As of Date" + Generate) — none reuse a shared component. AgedPayables/AgedReceivables have no filter at all (today-only). All should become `PageHeaderFilterButton`/date-range pills.
  2. **Report breadcrumbs are broken/inconsistent everywhere**: none are Home-rooted; the middle "Reports" crumb has no `href` in ProfitAndLoss/BalanceSheet/TrialBalance/CashFlow (dead label) while AgedPayables/AgedReceivables/FundingStreamSummary point the "Reports" crumb's `href` at the *report's own URL* instead of the real `/finance/reports` index (route exists at `routes/finance.php:617`). BudgetVsActuals drops the "Reports" crumb entirely (2 levels only).
  3. **KPI/summary cards are duplicated as both `PageHero` `stats` props and a second row of `<Card>`s in the body** on nearly every page in this area (bank-accounts/Index, bank-reconciliation/Index, payment-matching/Index, gst-returns/Index, IrdFilings/Index, all 8 reports, Consolidation/Index).
  4. **Hand-rolled "Balanced/Unbalanced" status** appears three times: BalanceSheet.tsx:301-314, TrialBalance.tsx:161-174 and 360-376 — none use `<StatusBadge>`.
  5. **`animate-spin` used instead of `<LoadingState>`**: bank-feeds/Index.tsx:214,535 (Sync All, per-row Sync), reports/BudgetVsActuals.tsx:349 (Sync Actuals).
  6. Several pages accept props that are never rendered, signalling half-built workflows: `eftpos/Batches.tsx` `unmatchedBankTransactions`, `petty-cash/Show.tsx` `receipt_path` (in form state, no file input).
- Counts: P0 22 · P1 34 · P2 14

## Pages

### `/finance/bank-accounts` — `resources/js/pages/finance/bank-accounts/Index.tsx` (index, 461 lines)
**Current hero:** title "Bank Accounts", description "Manage your organisation's bank accounts and balances", stats: Accounts (`bankAccounts.length`) · Active (`activeCount`) · Unreconciled (`unreconciledTotal`); actions: "Add Bank Account" (canManage, opens `BankAccountDialog`); footer `<BankingTabsFooter active="accounts">`.
**Breadcrumbs:** `[Finance /finance, Bank Accounts /finance/bank-accounts]` → needs `Home /dashboard` prefix.
**Meter blocks:**
| Block | Source | Form | Tone | Links to |
|---|---|---|---|---|
| Total cash | sum `current_balance` | Stat | success/critical by sign | this page |
| Accounts | `bankAccounts.length` | Stat | neutral | this page |
| Active | `activeCount` | Stat | success | this page (filter) |
| Unreconciled | `unreconciledTotal` | Delta/Stat | warning | `/finance/bank-transactions?status=unreconciled` |
Only 4 honest numbers; "Primary account" (lines 229-251) has no natural link target beyond its own Show page.
**Header filters:** none today.
**In-page tabs:** none (rail only, via `BankingTabsFooter`).
**List surface:** custom `<Card>` grid (lines 313-434), each card click+keydown-navigable, `onContextMenu={rowMenu.open(...)}` with `RowCtxItem[]` = `[Open, Edit(canManage)]` (lines 90-109). No pagination (all render). Empty state = `<EmptyList>` (good). Propose `EntityCard`: identity = name+bank_name, status meridian = is_active, fact chips = account_type/GL code, metric = current_balance, alert chip = unreconciled_count>0.
**Create/Edit:** `BankAccountDialog` (`components/finance/bank-account-dialog.tsx`) is already a proper 2-step `WizardShell` (Account → Ledger & review) for both create and edit — **reference-quality dialog**.
**Findings:**
- [P1] duplicate KPI — hero `stats` (147-151) repeats the 3-card KPI row at `Index.tsx:192-252`.
- [P1] hand-rolled status spans — `Primary`/`Inactive` badges use raw `<Badge className="border-status-info/30 …">` (347-352) instead of `<StatusBadge>`.
- [P2] `unreconciled_count` pluralisation inline string-built (416-429) — move into a shared cell helper (`CounterPill`) once on `EntityTable`.
**Workflow/wiring:** none dead.

### `/finance/bank-accounts/{id}` — `resources/js/pages/finance/bank-accounts/Show.tsx` (record, 536 lines)
**Current hero:** compact variant, `backHref="/finance/bank-accounts"`, title = account name, description = bank_name, actions: Edit (canManage). No stats/footer (correct for compact record hero).
**Breadcrumbs:** `[Finance, Bank Accounts, {account.name}]` → needs Home root.
**Meter blocks:** N/A today (card-based balance summary, lines 207-242) — flag as **open decision** whether compact record heroes should also carry meter blocks.
**Header filters:** none (record page, correct).
**In-page tabs:** none; two sections (Transactions, Reconciliations) stacked — candidate for `TierTwoTabs` instead.
**List surface:** two raw `<Table>`s (390-434, 452-507), no pagination, no context menu, no kebab, no `ListCaption`. Reconciliations table has an inline "View" button per row (493-502) instead of kebab.
**Create/Edit:** "Import Transactions" is a simple `<Dialog>` but **missing `DialogDescription`** (309-314), file input doesn't reuse `FileDropzone`. "Start Reconciliation" (366-373) links to the routed `bank-reconciliation/create?bank_account_id=` page (verified: `BankReconciliationController::create` reads `$request->bank_account_id`).
**Findings:**
- [P1] Import dialog missing `DialogDescription` — `Show.tsx:309-314`.
- [P1] two raw `<Table>`s, no `EntityTable`, no pagination, no `ListCaption`, no kebab — `390-507`.
- [P2] duplicate hand-rolled `statusBadge()`/`reconStatusBadge()` helpers (97-138) instead of `<StatusBadge>`.

### `/finance/bank-transactions` — `resources/js/pages/finance/bank-transactions/Index.tsx` (index, 904 lines)
**Current hero:** title "Bank Transactions", stats On this page/Unreconciled/Accounts; actions Export CSV, Import CSV (dialog), Add Transaction (dialog); footer `<BankingTabsFooter active="transactions">`.
**Breadcrumbs:** `[Finance, Bank Transactions]` → needs Home root.
**Meter blocks:** Transactions (page) · Unreconciled · Total value · Accounts — all page-scoped; framing must survive into meter-block copy.
**Header filters:** bank account Select, status Select, start/end date Inputs, all in a `<Card><CardTitle>Filters</CardTitle>` (655-750) → header pills.
**List surface:** raw `<Table>` (767-858), **no row actions, no kebab, no context menu at all** — rows aren't even clickable, unlike every other Banking index. Hand-rolled pagination via `dangerouslySetInnerHTML` link loop (876-895). `<EmptyList>` used correctly.
**Create/Edit:** "Add Transaction"/"Import CSV" dialogs (226-360, 362-605) are compliant simple `Dialog`s with `DialogDescription`.
**Findings:**
- [P0] filters below header — `655-750`.
- [P0] hand-rolled pagination — `861-898`.
- [P1] no row action/context menu/kebab anywhere — `791-853`.
- [P1] status colours hard-coded in local `statusStyles` map (109-115) instead of `<StatusBadge>`.
- [P2] header buttons hand-tint with `border-primary-foreground/30 bg-primary-foreground/10 …` soup (216, 233) — repeats across nearly every hero in this area.

### `/finance/bank-feeds` — `resources/js/pages/finance/bank-feeds/Index.tsx` (index, 588 lines)
**Current hero:** title "Bank Feeds", stats Feeds/Active/Failed; actions Sync All (conditional), Add Bank Feed dialog; footer `<BankingTabsFooter active="feeds">`.
**Breadcrumbs:** `[Finance, Bank Feeds]` → needs Home root.
**Meter blocks:** Feeds · Active · Failed (tone critical) — 3 solid numbers.
**List surface:** card list (452-562), row actions = 3 inline buttons (Logs, Sync, Disconnect), **no kebab/context menu**. `ConfirmDialog` used correctly for Disconnect. Empty state hand-rolled (418-449) instead of `<EmptyList>`.
**Findings:**
- [P1] no kebab/context menu on feed cards — `510-549`.
- [P1] empty state hand-rolled, not `<EmptyList>` — `418-449`.
- [P1] `animate-spin` for Sync All and per-row Sync instead of `<LoadingState>` — `214, 535`.
- [P2] provider label map duplicated verbatim in `Logs.tsx`.
**Workflow/wiring:** `providerSetupEnabled`/`csvImportSupported` gate is real, server-driven — correctly hides bank-feed setup and offers CSV import fallback; not a stub.

### `/finance/bank-feeds/{feed}/logs` — `resources/js/pages/finance/bank-feeds/Logs.tsx` (record, 207 lines)
**Current hero:** compact, `backHref`, title "Sync Logs", description = account/provider/bank. No stats/footer (correct).
**Breadcrumbs:** `[Finance, Bank Feeds, "{account} Logs"]` → needs Home root.
**List surface:** **raw `<table>`** (95-167) — not even the `components/ui/table` primitive; `<StatusBadge>` correctly used for status (132-137). Hand-rolled pagination (172-201).
**Findings:**
- [P0] raw `<table>` element — `95-167`.
- [P0] hand-rolled pagination — `172-201`.

### `/finance/bank-reconciliation` — `resources/js/pages/finance/bank-reconciliation/Index.tsx` (index, 396 lines)
**Current hero:** title "Bank Reconciliation", stats Total/Completed/In progress (page-scoped, mislabelled); actions "New Reconciliation" (Link to routed Create page); footer `<BankingTabsFooter active="reconciliation">`.
**Breadcrumbs:** `[Finance, Bank Reconciliation]` → needs Home root.
**Meter blocks:** Total (real paginator total) · Completed (page-only, header doesn't disclose scope while body copy at line 181 does) · In progress (same caveat).
**Header filters:** bank account + status Selects in a `<Card>` (211-265) → header pills.
**List surface:** raw `<Table>` (291-352), no kebab/context menu, just a "View"/"Continue" text button (334-347). Hand-rolled pagination (357-392).
**Create/Edit path:** links to routed `bank-reconciliation/create` (142, 282) and from `bank-accounts/Show.tsx:367` with `?bank_account_id=`. **No dialog exists.** `Create.tsx` is a trivial 3-field form — clearest "convert to dialog" candidate in the area.
**Findings:**
- [P0] filters below header — `211-265`.
- [P0] routed `Create.tsx` full page instead of a dialog.
- [P0] hand-rolled pagination — `357-392`.
- [P1] header stat doesn't disclose "this page" scope while body copy does.
- [P1] no kebab/context menu — `334-347`.
**Workflow/wiring:** verified `bank-reconciliation.create` is a real GET (not a redirect, unlike `petty-cash/create`/`cash-flow-forecast/create`), confirming it's simply unmigrated.

### `/finance/bank-reconciliation/create` — `resources/js/pages/finance/bank-reconciliation/Create.tsx` (routed-wizard→should-be-dialog, 188 lines)
**Current hero:** compact, `backHref`, title "New Bank Reconciliation".
**Breadcrumbs:** `[Finance, Bank Reconciliation, New Reconciliation]` → needs Home root.
**Create/Edit:** single-card form — Bank Account select (shows current balance), Statement Date, Statement Closing Balance. Maps 1:1 onto a 1-step simple `Dialog`.
**Findings:**
- [P0] entity-create-as-routed-page — should be a `Dialog` launched from `bank-reconciliation/Index.tsx` and `bank-accounts/Show.tsx`'s "Start Reconciliation" button, passing `bank_account_id` as a prop instead of a query string.
**Workflow/wiring:** linked from `bank-reconciliation/Index.tsx:142,282` and `bank-accounts/Show.tsx:367` (verified).

### `/finance/bank-reconciliation/{id}` — `resources/js/pages/finance/bank-reconciliation/Reconcile.tsx` (**tool**, 936 lines)
Genuine **workspace tool** (two-pane matching workbench, running-balance math, suggested-match acceptance, adjustment-account posting, post-completion amendment flow) — most of it is legitimate bespoke work surface. Header-contract parts that DO apply:
**Current hero:** icon `Banknote`, `backHref`, title "Bank Reconciliation", description = account+date; actions (completed) = badge + "Start correction" dialog. No stats/footer today.
**Breadcrumbs:** `[Finance, Bank Reconciliation, "{account} - {date}"]` → needs Home root.
**Meter blocks (contract applies):** the 5-card summary row (408-471: Starting/Statement/Calculated Balance, Difference, Matched) should migrate to header meter blocks; the two-pane match workbench stays bespoke.
**List contract:** the two side-by-side tables (640-802) and Matched Items table (805-932) are **legitimately bespoke** (row selection, not record navigation) — do not force `EntityTable`, but should still adopt `<StatusBadge>`.
**Findings:**
- [P0] breadcrumbs not Home-rooted — `267-274`.
- [P1] hero stats/meter-block row not adopted — `408-471`.
- [P1] "Match as Adjustment" button label is identical in both branches of a ternary (`adjustmentAccountId ? 'Match as Adjustment' : 'Match as Adjustment'`, 513-515) — dead ternary, likely copy-paste bug; will read as a bug to a finance officer.
- [P2] raw `<select>` HTML element for adjustment-account picker (483-498) instead of the shared `Select` component used everywhere else on this page.
**Workflow/wiring:** post-completion "Start correction" flow (297-392) is real and audit-safe (verified route `bank-reconciliation.amend`), not a stub.

### `/finance/payment-matching` — `resources/js/pages/finance/payment-matching/Index.tsx` (**tool**+index hybrid, 599 lines)
**Current hero:** title "Payment Matching", stats Total/Suggested/Confirmed/Rejected; actions "Match Rules" link, "Run Auto-Match"; footer `<BankingTabsFooter active="matching">`.
**Breadcrumbs:** `[Finance, Payment Matching]` → needs Home root.
**Meter blocks:** all 4 already in hero, needs page-scope disclosure fixed to match body copy.
**Header filters:** status + min-confidence Selects in a `<Card>` (301-353) → header pills.
**List surface:** raw `<Table>` (374-559), inline Confirm/Reject buttons only (522-553) — no kebab/context menu, matched bill/invoice name isn't a link. Hand-rolled pagination (564-595).
**Findings:**
- [P0] filters below header — `301-353`.
- [P0] hand-rolled pagination — `564-595`.
- [P1] no kebab/context menu; matched entity name isn't a link — `442-483`.
- [P1] `confidenceBadge()`/`statusBadge()` hand-rolled (85-136) instead of `<StatusBadge>` for status (confidence-% badge is legitimately bespoke, keep it).

### `/finance/match-rules` — `resources/js/pages/finance/match-rules/Index.tsx` (index, 580 lines)
**Current hero:** title "Match Rules", stats Total rules/Active; actions `CreateRuleDialog`; footer `<BankingTabsFooter active="match-rules">`.
**Breadcrumbs:** `[Finance, Payment Matching, Match Rules]` → needs Home root (correctly nests under Payment Matching).
**List surface:** raw `<Table>` (463-556), inline Edit+Delete icon buttons, no kebab. `ConfirmDialog` correctly used for delete.
**Create/Edit path:** `CreateRuleDialog`/`EditRuleDialog` (79-406) are compliant simple `Dialog`s but ~150 lines of duplicated form JSX between create/edit.
**Findings:**
- [P1] no kebab/context menu; two separate icon buttons — `531-548`.
- [P2] duplicated form JSX between Create/Edit dialogs — extract shared form body.

### `/finance/eftpos/terminals` — `resources/js/pages/finance/eftpos/Terminals.tsx` (index, 407 lines)
**Current hero:** title "EFTPOS Terminals", stats Total/Active/Batches; actions "View Batches" link + "Add Terminal" (toggles inline form); footer `<BankingTabsFooter active="eftpos">`.
**Breadcrumbs:** `[Finance, EFTPOS /finance/eftpos/batches, Terminals]` — "EFTPOS" crumb points at Batches, not a real landing page; needs Home root regardless.
**List surface:** raw `<Table>` (338-403), **no row actions at all** despite `PUT eftpos/terminals/{id}` existing server-side (`routes/finance.php:734`) — the update endpoint has zero UI entry point. Hand-rolled empty state (324-336).
**Create/Edit path:** "Add Terminal" is an **inline `<Card>` form toggled open in the page body** (142-322), not a dialog — violates the create-as-dialog rule; 7 fields, should become a `Dialog`/`WizardShell`.
**Findings:**
- [P0] create-in-page-body instead of `Dialog`/`WizardShell` — `142-322`.
- [P0] no Edit UI despite a working `PUT` update route — `eftpos.terminals.update` is dead from the frontend's perspective.
- [P1] hand-rolled empty state, not `<EmptyList>` — `324-336`.

### `/finance/eftpos/batches` — `resources/js/pages/finance/eftpos/Batches.tsx` (index, 426 lines)
**Current hero:** title "EFTPOS Batches", stats Settlement/Fees/Transactions/Unreconciled; actions "Manage Terminals" link. **No footer/rail** — orphaned from the Banking hub's EFTPOS tab strip (Terminals has one, this doesn't).
**Breadcrumbs:** `[Finance, EFTPOS Batches]` → needs Home root; inconsistent with Terminals.tsx's crumb trail.
**Header filters:** status, terminal Selects + date-from/to Inputs in a `<Card>` (166-276) → header pills.
**List surface:** raw `<Table>` (289-398), inline "Reconcile" button per row (376-390) **calls `handleReconcile(batch.id)` with no second argument**, always posting `bank_transaction_id: null` even though `unmatchedBankTransactions` is fetched (declared line 74, destructured 98, **never referenced again**). The intended "pick which bank transaction this batch settled to" UI was never built.
**Findings:**
- [P0] filters below header — `166-276`.
- [P0] missing `footer=` — orphaned from Banking → EFTPOS tab rail.
- [P0] dead prop `unmatchedBankTransactions` — reconcile action never lets the user pick a bank transaction — `74, 98` (prop), `380-390` (button).

### `/finance/eftpos/batches/{id}` — `resources/js/pages/finance/eftpos/BatchDetail.tsx` (record, 445 lines)
**Current hero:** compact, `backHref`, title "Batch {number}", description = terminal name; actions = status badge (hand-rolled). No footer (correct for a record page, though Batches/BatchDetail vs Terminals rail-presence is inconsistent).
**Breadcrumbs:** `[Finance, EFTPOS Batches, "Batch {number}"]` → needs Home root.
**List surface:** raw `<Table>` for transactions (345-438), read-only, no findings beyond breadcrumbs.
**Findings:**
- [P1] hand-rolled status badge map (103-120) instead of `<StatusBadge status={batch.status}>` — Batches.tsx already gets the same 4-state styling for free via `<StatusBadge>` (line 362), so two sibling pages render the SAME status differently.
- [P2] `txnTypeConfig`/`cardTypeLabels` local maps (80-101) — fine as domain labels, but duplicate badge-className pattern.

### `/finance/petty-cash` — `resources/js/pages/finance/petty-cash/Index.tsx` (index, 241 lines)
**Current hero:** title "Petty Cash Funds", stats Funds/Active/Total float/Total balance; actions Export CSV + New Fund dialog; footer `<BankingTabsFooter active="petty-cash">`.
**Breadcrumbs:** `[Finance, Petty Cash]` → needs Home root.
**Meter blocks:** all 4 hero stats already correct — good template for meter-block conversion.
**List surface:** card grid, whole card is a `<Link>`, `onContextMenu` gives only `[Open]` (56-63) — adds nothing beyond left-click. No edit exists for petty cash funds at all — confirmed no `PUT /finance/petty-cash/{fund}` route exists, so this is an intentional server-side constraint, not a missing UI.
**Create/Edit:** `PettyCashFundDialog` — compliant 2-step `WizardShell`.
**Findings:**
- [P2] context menu with only "Open" on an already-clickable card is low-value chrome.

### `/finance/petty-cash/{id}` — `resources/js/pages/finance/petty-cash/Show.tsx` (record, 414 lines)
**Current hero:** compact, title = fund name + `<StatusBadge>` (correct usage, 127-137 — good example). No stats/footer.
**Breadcrumbs:** `[Finance, Petty Cash, {fund.name}]` → needs Home root.
**Findings:**
- [P0] "Record Transaction" form (194-315) includes `receipt_path` in `useForm` state (104) with **no corresponding input rendered anywhere** — fields shown are date/type/amount/description/expense account only. The transactions table has a "Receipt" column (341, 391-398) reading a field that can never be set. Receipts can never actually be attached through the product today.
- [P1] `typeConfig` hand-rolled badge map (74-87) instead of `<StatusBadge>`, even though the header two lines above uses it correctly.
**Workflow/wiring:** the receipt-path gap is the most concrete "would confuse a finance officer" item in this area.

### `/finance/gst-returns` — `resources/js/pages/finance/gst-returns/Index.tsx` (index, 512 lines)
**Current hero:** title "GST Returns", stats GST collected/paid/Net payable/Drafts; actions Export CSV + "Prepare Return" link; footer `<TaxTabsFooter active="gst-returns">`.
**Breadcrumbs:** `[Finance, GST Returns]` → needs Home root.
**Header filters:** status + year Selects inline in the table's `CardHeader` (277-330) — still below-header.
**List surface:** **raw `<table>`** (335-482), single `[Open]` context menu (130-140) on an already-clickable row. `<EmptySearch>`/`<EmptyList>` branching correctly used (365-391). Hand-rolled pagination (485-504).
**Findings:**
- [P0] raw `<table>` element — `335-482`.
- [P0] filters below header — `277-330`.
- [P0] hand-rolled pagination — `485-504`.
- [P1] low-value context menu (Open-only on a clickable row).

### `/finance/gst-returns/prepare` — `resources/js/pages/finance/gst-returns/Prepare.tsx` (routed-wizard, 327 lines)
**Current hero:** compact, `backHref`, title "Prepare GST Return"; `PageLayout width="narrow"`.
**Breadcrumbs:** `[Finance, GST Returns, Prepare Return]` → needs Home root.
**Create/Edit:** period-picker with a filing-calendar lookup table (233-315) — genuinely more involved than a trivial dialog; reasonable to keep routed or fold into a `WizardShell` step, owner's call.
**Findings:** [P1] breadcrumbs not Home-rooted; otherwise clean.

### `/finance/gst-returns/{id}` — `resources/js/pages/finance/gst-returns/Show.tsx` (record, 584 lines)
**Current hero:** compact, title = "GST Return" + `<StatusBadge>` (correct), description = period/frequency/basis/IRD period/revision+filed-by; actions Print/Prepare amendment/Mark as Filed.
**Breadcrumbs:** `[Finance, GST Returns, "Period ending {date}"]` → needs Home root.
**List surface:** two raw `<table>` elements (391-448, 467-548), neither paginated (detail lines could be long).
**Findings:** [P0] raw `<table>` ×2 — `391-448, 467-548`.
**Workflow/wiring:** `ConfirmDialog`s wired to real routes; "Mark as filed" correctly warns it's irreversible.

### `/finance/ird-filings` — `resources/js/pages/finance/IrdFilings/Index.tsx` (index, 714 lines)
**Current hero:** title "IRD Filings", stats Filed/Pending/Total filed; actions Export CSV + "New Filing" (toggles inline forms); footer `<TaxTabsFooter active="ird-filings">`.
**Breadcrumbs:** `[Finance, IRD Filings]` → needs Home root.
**Header filters:** filing_type + status Selects inline in `CardHeader` (491-550) → header pills.
**List surface:** raw `<table>` (555-684), same "row clickable + Open-only menu" pattern (179-187). Hand-rolled pagination (687-706).
**Create/Edit path:** two inline "Create Filing" cards (283-390, 392-481) are page-body forms, not dialogs — each simple enough (source-record select + IRD number) to become two small dialogs.
**Findings:**
- [P0] raw `<table>` — `555-684`.
- [P0] filters below header — `491-550`.
- [P0] create-in-page-body instead of dialogs — `283-481`.
- [P0] hand-rolled pagination — `687-706`.
**Workflow/wiring:** both create flows post to real routes (`ird-filings.from-gst`, `ird-filings.from-payroll`).

### `/finance/ird-filings/{id}` — `resources/js/pages/finance/IrdFilings/Show.tsx` (record, 455 lines)
**Current hero:** compact, title = filing type + `<StatusBadge>`; actions Validate/Submit to IRD.
**Breadcrumbs:** `[Finance, "IRD E-Filing", "{type} - {date}"]` → needs Home root; crumb label "IRD E-Filing" doesn't match Index's "IRD Filings" title.
**Findings:**
- [P2] breadcrumb copy drift ("IRD E-Filing" vs "IRD Filings").
- [P2] "Only simulated submissions are made unless a live IRD gateway is configured" (448) is developer-honest but jargon-y — consider "This submits a test filing until a live IRD connection is set up."
**Workflow/wiring:** confirms no live IRD gateway integration yet — correctly surfaced, not pretending it's live.

### `/finance/audit-exports` — `resources/js/pages/finance/audit-exports/Index.tsx` (index, 404 lines)
**Current hero:** title "Audit Exports", stats Total/Completed/Generating/Failed; actions "New Export" dialog; footer `<TaxTabsFooter active="audit-exports">`.
**Breadcrumbs:** `[Finance, Audit Exports]` → needs Home root.
**List surface:** proper `Table` (cleanest file here), row `onContextMenu` with a real `RowCtxItem[]` (Download/Delete, 124-148) **plus** the same actions duplicated as inline buttons (305-336) — closest to the contract already, just needs the inline buttons to become a kebab reading the same array. Hand-rolled pagination (346-370).
**Create/Edit:** `AuditExportDialog` — compliant 2-step `WizardShell`, good reference.
**Findings:**
- [P1] hand-rolled pagination — `346-370`.
- [P1] row actions duplicated between context menu and inline buttons — `305-336` vs `124-148`.

## Donor Funds (standalone, no hub)

### `/finance/donor-funds` — `resources/js/pages/finance/donor-funds/Index.tsx` (index, 635 lines)
**Current hero:** title "Donor Funds", stats Total funds/Received/Available/Expiring soon; actions Export CSV + "New Fund" dialog. **No `footer=` at all** — confirmed no hub references donor-funds; it's a flat "other" nav item (`app-sidebar.tsx:2081`).
**Breadcrumbs:** `[Finance, Donor Funds]` → needs Home root.
**Meter blocks:** Total funds · Received · Available · Expiring soon — 4 real numbers; Restricted/Unrestricted split (currently a body-only pie chart, 289-346) could be a 5th Donut block.
**Header filters:** search Input + status/restricted Selects + Filter/Clear buttons in a `<Card>` (350-424) → header pills; note this is apply-on-click, inconsistent with instant-apply Selects elsewhere (e.g. bank-transactions/Index.tsx).
**List surface:** proper `Table` (458-591), **no row actions, no kebab, no context menu at all** — only code/name are plain `<Link>`s. Hand-rolled pagination (593-618).
**Create/Edit:** `DonorFundDialog` is explicitly **create-only** by its own doc comment — but `Show.tsx` has **no edit action either**, despite a working `PUT /finance/donor-funds/{fund}` route (`routes/finance.php:754`, `donor-funds.update`). Confirmed backend-exists/UI-missing gap.
**Findings:**
- [P0] missing `footer=`/hub membership — needs owner decision.
- [P0] hand-rolled pagination — `593-618`.
- [P1] no row kebab/context menu at all.
- [P1] filter pattern inconsistent with rest of area.
- [P1] **`donor-funds.update` route has zero UI callers** — fund editing is unreachable.

### `/finance/donor-funds/{id}` — `resources/js/pages/finance/donor-funds/Show.tsx` (record, 779 lines)
**Current hero:** compact, title = fund name; actions: `StatusBadge` + hand-rolled `<Badge>` for "Restricted" (245-252) — mixed patterns in one actions slot.
**Breadcrumbs:** `[Finance, Donor Funds, {fund.fund_name}]` → needs Home root.
**In-page tabs:** `FinanceTabs` with 2 items (Transactions, Reports, 448-467) — exactly the "record sections → `TierTwoTabs`" case, but implemented via local `useState` (194) not `?tab=`, so tab selection is lost on refresh/share-link.
**List surface:** Transactions tab = raw `Table` (506-580); Reports tab = raw `Table` (652-748) with working PDF download.
**Findings:**
- [P0] in-page tabs use `useState`, not `?tab=` — state lost on refresh — `194, 448-467`.
- [P1] mixed badge patterns in one actions slot — `241-253`.
- [P1] **no "reverse transaction" action anywhere** despite a working route `donor-funds.transactions.reverse` (`routes/finance.php:763`) — every transaction row is read-only; wrong entries can never be reversed through the UI.
- [P2] `txnTypeConfig` hand-rolled colour map (135-169) instead of `<StatusBadge>`.
**Workflow/wiring:** two confirmed backend-exists/UI-missing gaps on Donor Funds (edit + reverse) — worth flagging together.

## Reports & Planning hub

The 8 `reports/*.tsx` pages plus `CashFlowForecast/{Index,Show}.tsx` share one skeleton: Hero (icon/title/stats/Print/`ReportsTabsFooter`) → KPI card row (duplicates hero stats) → date-range filter `<Card>` (absent only on Aged reports) → chart `<Card>`s (correctly using `chart-palette.ts`'s `chartColor()`) → one big report `<Table>`. Below-header filter controls per report:

| Report | Filter controls (all in a body `<Card>`, none in header) |
|---|---|
| ProfitAndLoss | Start Date, End Date, "Generate" (`ProfitAndLoss.tsx:212-239`) |
| BalanceSheet | As of Date, "Generate" (`BalanceSheet.tsx:212-228`) |
| TrialBalance | As of Date, "Generate" (`TrialBalance.tsx:206-222`) |
| CashFlow | Start Date, End Date, "Generate" (`CashFlow.tsx:337-364`) |
| AgedPayables | **none** — always "as of today" |
| AgedReceivables | **none** — always "as of today" |
| FundingStreamSummary | Start Date, End Date, "Generate" (`FundingStreamSummary.tsx:156-186`) |
| BudgetVsActuals | Budget picker `Select` **already in header actions** + "Sync Actuals" button — the one report doing this right |

### `/finance/reports/profit-loss` — `ProfitAndLoss.tsx` (report, 432 lines)
**Breadcrumbs:** `[Finance, Reports (no href), Profit & Loss (no href)]` — both trailing crumbs dead labels; needs Home root and real hrefs (`/finance/reports` exists, `routes/finance.php:617`, nothing links to it).
**Meter blocks:** Revenue · Expenses · Net Profit/Loss (tone flips) — could add a 4th (top expense category).
**Findings:**
- [P0] dead/missing breadcrumb hrefs — `60-64`.
- [P0] filter card below header — `212-239`.
- [P1] duplicate KPI cards vs hero stats — `144-210`.
- [P2] `dark:text-status-*` pairs throughout.

### `/finance/reports/balance-sheet` — `BalanceSheet.tsx` (report, 364 lines)
**Findings:**
- [P0] dead breadcrumb hrefs — `54-58`.
- [P0] filter card below header — `212-228`.
- [P1] hand-rolled "Balanced"/"Out of Balance" `<Badge>` (301-314) instead of `<StatusBadge>` — reimplemented independently in TrialBalance.tsx too.
- [P1] duplicate KPI cards vs hero stats — `163-210`.

### `/finance/reports/trial-balance` — `TrialBalance.tsx` (report, 386 lines)
**Findings:**
- [P0] dead breadcrumb hrefs — `63-67`.
- [P0] filter card below header — `206-222`.
- [P1] hand-rolled Balanced/Unbalanced badges appear **twice** in this file (161-174, plus a plain-text version at 360-376).
- [P1] duplicate KPI cards vs hero stats — `150-204`.

### `/finance/reports/cash-flow` — `CashFlow.tsx` (report, 538 lines)
**Findings:**
- [P0] dead breadcrumb hrefs — `70-74`.
- [P0] filter card below header — `337-364`.
- [P1] duplicate KPI cards vs hero stats (largest duplication: 4 full cards re-deriving hero stats) — `201-335`.
- [P2] heaviest `dark:text-status-*` count of the 8 reports.

### `/finance/reports/aged-payables` — `AgedPayables.tsx` (report, 403 lines)
**Breadcrumbs:** "Reports"/"Aged Payables" crumbs both `href` to the page's own URL (95-99) — self-referential, not the real `/finance/reports` index.
**Header filters:** none — correct as-is.
**Findings:**
- [P0] self-referential/non-Home breadcrumb hrefs — `95-99`.
- [P1] duplicate KPI cards vs hero stats — `179-235`.

### `/finance/reports/aged-receivables` — `AgedReceivables.tsx` (report, 403 lines)
Structural duplicate of AgedPayables (client vs vendor naming only). Same findings apply 1:1 at equivalent lines (breadcrumb self-reference `95-99`, duplicate KPI `179-235`). Recommend one shared `<AgedAgingReport entityLabel="Vendor"|"Client">` component instead of maintaining two files in parallel.

### `/finance/reports/funding-stream-summary` — `FundingStreamSummary.tsx` (report, 400 lines)
**Breadcrumbs:** self-referential (both crumbs point at own URL, 66-72).
**Findings:**
- [P0] self-referential breadcrumb hrefs — `66-72`.
- [P0] filter card below header — `156-186`.
- [P1] duplicate KPI cards vs hero stats — `188-247`.

### `/finance/reports/budget-vs-actuals` — `BudgetVsActuals.tsx` (report, 680 lines)
**Current hero:** icon `BarChart3`, stats (gated on `hasBudget`) Budget/Actual/Variance/Utilisation; actions: **budget picker `Select` already in header** (good template) + "Sync Actuals" (`animate-spin`); footer `<ReportsTabsFooter active="budget-vs-actuals">`.
**Breadcrumbs:** `[Finance, Budget vs Actuals]` — only 2 levels, **missing "Reports" crumb entirely**, unlike every sibling report.
**Findings:**
- [P0] breadcrumb missing the "Reports" level — `218-224`.
- [P1] `animate-spin` instead of `<LoadingState>` — `349`.
- [P1] duplicate KPI cards vs hero stats — `369-401`.
- [P2] 5 different ad-hoc status-colour systems in one file (variance color, variance badge, explained/review badge, category header, progress-bar color) — could consolidate.
**Workflow/wiring:** "Sync Actuals" posts to a real route (`reports.budget-vs-actuals.sync`) — not a stub.

### `/finance/cash-flow-forecast` — `CashFlowForecast/Index.tsx` (index, 328 lines)
**Current hero:** title "Cash Flow Forecast", stats Total/Final/Draft; actions "New Forecast" dialog; footer `<ReportsTabsFooter active="cash-flow-forecast">`.
**Breadcrumbs:** `[Finance, Cash Flow Forecast]` → needs Home root; also missing the "Reports" parent crumb despite being a Reports-hub tab.
**List surface:** proper `Table`, row-click navigation, inline Delete button when draft (247-263) — no kebab. `StatusBadge` used correctly (215-228). `ConfirmDialog` correct.
**Create/Edit:** `CashFlowForecastDialog` — compliant 2-step `WizardShell`, includes a nice honest-scope `InfoCard`.
**Findings:**
- [P1] missing "Reports" breadcrumb level despite hub membership — `64-67`.
- [P1] no kebab/context menu on rows — `247-263`.

### `/finance/cash-flow-forecast/{id}` — `CashFlowForecast/Show.tsx` (record, 710 lines)
**Current hero:** compact, title = forecast name + `StatusBadge`; actions Print + Delete (draft, via `ConfirmDialog`).
**Breadcrumbs:** `[Finance, Cash Flow Forecast, {name}]` → needs Home root.
**Findings:**
- [P2] scenario selector (325-370) is plain `<Button>`s rather than `PageHeaderViewToggle`/`Segmented`.

## Consolidation & Intercompany (quarantined — recommend leave, do not migrate)

`Consolidation/{Index,Show,RunResults}.tsx` and `Intercompany/Index.tsx` are **not reachable in the live product**: `RejectUnsupportedConsolidation::handle()` (`app/Domain/Finance/Http/Middleware/RejectUnsupportedConsolidation.php:14-17`) unconditionally `abort(404)`s every route under `routes/finance.php:653-671`. The middleware's doc comment confirms this is deliberate ("unsupported multi-entity accounting boundary remains quarantined in this single-tenant application"). `tax-hub.tsx:58-63` keeps a `consolidation` tab with `requires: () => false`, invisible in the rendered rail.

**Recommendation: leave quarantined.** All 4 files are fully-built, reasonably clean multi-entity accounting UI that would need real migration effort (breadcrumbs, filters, list contract — same violations as everywhere else) for zero current user-facing benefit. Do not delete either — the middleware comment indicates route names are deliberately preserved for a possible future re-enable.

**Findings (recorded for completeness, no action recommended):**
- `Consolidation/Index.tsx` (316 lines): index, `TaxTabsFooter active="consolidation"` pointing at a hidden tab; inline `CreateGroupDialog` is a single-step raw `Dialog`, not `WizardShell`.
- `Consolidation/Show.tsx` (686 lines): record, two raw `Table`s, two inline dialogs, `ConfirmDialog` for entity removal — functionally complete.
- `Consolidation/RunResults.tsx` (495 lines): correctly uses `chart-palette.ts` and `<StatusBadge>` already — one of the more contract-compliant files in this batch, just unreachable.
- `Intercompany/Index.tsx` (437 lines): index, inline raw `Dialog`, `StatusBadge` used correctly.

## Dialogs

### `components/finance/bank-account-dialog.tsx` — no anatomy gaps
2-step `WizardShell` (Account → Ledger & review), `ReviewCard`/`ReviewRow`, `WizardSuccessPane` with "Add another", correct create/edit dual-mode (edit-mode correctly omits `opening_balance` from the PUT payload). **Reference-quality — use as the template for converting Terminals/bank-reconciliation-Create/IRD-filing-create into dialogs.**

### `components/finance/petty-cash-fund-dialog.tsx` — no anatomy gaps
2-step `WizardShell` (Details → Review), sentinel value (`NO_CUSTODIAN`) correctly handles the Radix empty-string-Select constraint.

### `components/finance/donor-fund-dialog.tsx` — no anatomy gaps, but create-only by design
3-step `WizardShell` (Fund → Accounting & dates → Review). Intentionally create-only per its own doc comment, but no edit path exists anywhere despite a working `PUT` route — a donor-funds page-level gap, not a defect in this dialog.

### `components/finance/donor-fund-transaction-dialog.tsx` — no anatomy gaps
2-step `WizardShell` with a genuine `PostingPreview` trust-journal preview, idempotency key, restricted-fund over-balance guard, accounting-readiness guard. One of the more sophisticated dialogs in the codebase.

### `components/finance/audit-export-dialog.tsx` — no anatomy gaps
2-step `WizardShell` with a 6-checkbox section picker styled as `<label>` cards (279-299) rather than a true tile-picker component — confirm against DESIGN.md's tile-picker rule for category choices.

### `components/finance/cash-flow-forecast-dialog.tsx` — no anatomy gaps
2-step `WizardShell`, `Segmented` control, `InfoCard` disclosing forecast scope before generation — good honest-scope pattern.

## Open decisions for the owner
- **Donor Funds hub membership**: no hub currently claims it. Does it join an existing hub's rail, get its own single-tab rail, or stay hub-less by design? Blocks its `lib/finance-sections.ts` entry.
- **`bank-reconciliation/create` and the EFTPOS-Terminals/IRD-Filings inline-form pages**: all three are clear "convert to dialog" candidates. Recommend prioritising `bank-reconciliation/Create.tsx` first (smallest, already linked from two places).
- **Confirmed dead backend endpoints**: `donor-funds.update`, `donor-funds.transactions.reverse`, `eftpos.terminals.update` — intentionally unbuilt UI (roadmap) or should the routes be removed? Triage before building new UI on either assumption.
- **`eftpos/Batches.tsx`'s unused `unmatchedBankTransactions` prop**: confirm whether a bank-transaction picker for reconciliation is in scope.
- **`petty-cash/Show.tsx`'s unreachable `receipt_path`**: confirm scope; if yes, reuse `components/ui/file-dropzone.tsx`; if no, drop the field and the "Receipt" column.
- **Report breadcrumbs**: should all 8 reports + Cash Flow Forecast link their middle crumb to the real `/finance/reports` index (`routes/finance.php:617`), which currently has zero incoming links?
- **Consolidation/Intercompany**: confirmed quarantined by design (404 middleware) — recommend explicitly excluding these 4 files from the migration backlog so the owner can sign off on "not migrating" as a decision, not an oversight.
