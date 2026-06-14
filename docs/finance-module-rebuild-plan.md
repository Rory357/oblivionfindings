# Finance Module — Rebuild Plan (Design Parity + Xero/MYOB Completeness + Payroll→Finance + Cross-module reconciliation)

**Created:** 2026-06-14 · **Author:** Claude (Opus 4.8, autonomous /loop)
**Companion:** `docs/finance-module-gap-analysis.md` (feature-by-feature, file:line evidence).
**Basis:** 9 parallel adversarial code sweeps, all claims re-derived from current code; the 2026-05-01
`FINANCE_READINESS_AUDIT.md` is **stale** and superseded for prioritisation (see gap-analysis headline).

Legend: each item is **Problem → Evidence → Fix → Acceptance**. `[ ]` open · `[x]` done. Items map to the
reprioritised defect list (gap-analysis §G). **The GL core is sound and tested** — this plan is mostly
(1) design collapse to 8 hubs and (2) bounded functional fixes, not a rewrite.

---

## Design-spine reference (the bar — verified from code)

- **Hero:** `resources/js/components/page/page-hero.tsx` — `PageHero` themes via `category` →
  `--hero-base: var(--category-${category})`. `PageHeroCategory` union (`:28-35`) = ops|hr|compliance|
  incidents|governance|sites|fleet — **no `finance`**. Rostering hero =
  `resources/js/pages/operations/rostering/index.tsx` (variant="hero" + real-state description +
  meta/badges + 3-4 real-data KPI stats + actions; **no calendar/week nav**).
- **Tabs (DECISION):** standardise Finance on **`TabStrip`** (`resources/js/components/rostering/tab-strip.tsx`)
  — toned pill tabs + icon chip + count badge + active underline-bar + roving-tabindex a11y. Used by **zero**
  finance pages today (only `donor-funds/Show` uses `ui/tabs`).
- **Modals:** `resources/js/components/wizard/shell.tsx` (`WizardShell` + `WizardStepPane` +
  `WizardSuccessPane` + `ReviewCard`/`ReviewRow`) + `primitives.tsx` (`Field`, `FieldErr`, `Segmented`,
  `TilePicker`, `ChipMulti`, `SelectInput`, `StepHead`, `SubHead`, `InfoCard`, `Ring`). Reference UX:
  `resources/js/components/clients/add-client-dialog.tsx`. Used by **zero** finance pages.
- **Calendar parity:** `resources/js/pages/my-calendar.tsx` + `resources/js/pages/calendar/global.tsx`
  (FullCalendar, month/agenda, click→modal) + `resources/js/lib/calendar/recur.ts`. **HR's M4-1 plans a
  shared `components/calendar/calendar-view.tsx` wrapper — reuse it** rather than re-instantiating FullCalendar.
- **Shared HR primitives already exist:** `resources/js/components/hr/` (hr-hero, hr-tabs wrapping TabStrip,
  people-picker, status-badge, wizard). **Mirror this** — create `resources/js/components/finance/`.

---

## Cross-cutting findings (apply across milestones)

- **Hero category gap is universal** — all ~105 finance pages render the default `--primary` gradient.
- **Tab chaos = no tabs** — one component (`TabStrip`) to rule them all; every sub-page becomes a tab.
- **Standalone-page / single-step-dialog CRUD** instead of wizard modals everywhere.
- **Data-blind AR** — receivables/aged-AR/statements read the orphaned legacy `Invoice` table.
- **4 orphaned jobs + 2 dead services** (`BillingJournalService`/`PostBillingJournalJob`, stale `FundingService`).
- **Three duplicate-backend problems:** client-money (legacy `ClientFund` vs empty `ClientLedgerEntry`),
  budgets (Governance store A vs Finance store B vs roadmap), and within-AR (legacy `Invoice` vs `FinInvoice`).
- **Money is `decimal(14,2)`** everywhere — use bcmath/decimal, never cents-as-int.
- **Permissions are SEEDED not migrated** — every new key → a seeder `DatabaseSeeder` calls + deploy runbook.

---

## Final hub / tab / route map (8 hubs + Settings)

Old routes redirect to `hub#tab`. Detail pages (site dashboard, client financials) stay as drill-downs.

| # | Hub (route) | Tabs (TabStrip) | Absorbs (old routes → redirect) |
|---|---|---|---|
| 1 | **Overview** `/finance` | Summary · Executive · By site | `dashboard`, `executive-dashboard`, `sites.overview` (4 dashboards → 1 hub; `site-dashboard`, `clients.financials` stay as detail) |
| 2 | **Receivables** `/finance/receivables` | Invoices · Quotes · Credit notes · Recurring charges · Statements · Aged AR · Price books · Allocations | `invoices`, `quotes`, `credit-notes`(AR), `recurring-charges`, `billing`, `price-books`, `payment-allocations`, `receivables` |
| 3 | **Payables** `/finance/payables` | Bills · Purchase orders · Vendors · Credit notes · Payment runs · Aged AP | `bills`, `purchase-orders`, `vendors`, `credit-notes`(AP), `payment-runs` |
| 4 | **Banking** `/finance/banking` | Accounts · Transactions · Reconciliation · Matching · Feeds · EFTPOS · Petty cash · Match rules | `bank-accounts`, `bank-transactions`, `bank-reconciliation`, `payment-matching`, `bank-feeds`, `eftpos`, `petty-cash`, `match-rules` |
| 5 | **Ledger** `/finance/ledger` | Chart of accounts · Journals · Cost centres · Fiscal periods · Currencies · FX revaluations · Fixed assets | `accounts`, `journals`, `cost-centres`, `fiscal-periods`, `currencies`, `fx-revaluations`, `fixed-assets` |
| 6 | **Funding & Client Money** `/finance/funding` | Funding streams · Funding claims · Client/resident funds · Donor/trust funds · Service billing | `finance/funding-streams`, `finance/donor-funds`, **+ migrate** `operations/funding/**`, `operations/client-funds/**` |
| 7 | **Tax & Compliance** `/finance/tax` | GST returns · IRD / payday filing · Audit exports · Consolidation · Intercompany | `gst-returns`, `ird-filings`, `audit-exports`, `Consolidation`, `Intercompany` |
| 8 | **Reports & Planning** `/finance/reports` | P&L · Balance sheet · Trial balance · Cash flow · Aged AR · Aged AP · Funding summary · Budget vs actuals · Cash-flow forecast | `reports/*`, `cash-flow-forecast`, `budget-vs-actuals` |
| — | **Settings & Integrations** `/finance/settings` | Integrations (Xero/MYOB) · Account mapping · Tax/GST config · Fiscal calendar · Finance permissions | `Integrations`, mapping, plus admin config |
| + | **Calendar** `/finance/calendar` | (FullCalendar month/agenda) | new — site-calendar parity |
| + | **Payroll** (HR↔Finance seam) | pay-run lifecycle + GL post status | spans `hr/payroll/*` + `finance/journals` + `finance/payment-runs` |

**Route-collapse safety rule:** before deleting/redirecting any route, grep the whole repo for the route
name/path/component, update callers, leave a `Route::redirect`, then run `php artisan route:list` + build.

---

## Cross-module reconciliation plan

1. **Client money (M6):** keep **legacy `ClientFund`/`ClientFundTransaction` as canonical** (only populated
   store, working trust-account 1010/2500 journals). Retire/feature-flag the empty `ClientLedgerEntry` **or**
   build its write path *after* fixing the `ClientLedgerService` netting flaw (segregate personal vs operational
   running balances). Consolidate the two profile finance tabs + family portal onto the canonical backend.
   Preserve `ClientFundJournalService` + `FundingClaimJournalService` verbatim. Delete stale `FundingService`.
2. **Budgets (M8):** make **Finance store B (`SiteBudgetLine`+`FinCostAllocation`) the single engine**;
   implement the orphaned `BudgetSyncInterface` with a concrete `FinanceBudgetSync`, bind it, and have
   Governance consume budgets/actuals **through it** (retire denormalised `budget_line_items.actual_amount`
   + the manual `recordActuals` clobber). Collapse the double hourly sync to one writer. Unify category
   vocabularies. Tie `SpendApproval.approve()` into AP (create/link a `FinBill` or gate the bill/run).
3. **Sites ledger:** already canonical (House Ledger posts to GL per site cost-centre) — no shadow ledger.
   Add `fin_vendor_id` FK to `SiteVendor` (compliance contact ↔ AP master).
4. **Capture-at-source (M9):** Damages, Catering grocery spend, Respite billing/funding, Asset
   capitalisation, operational AP→Bill/Vendor attribution — each an embedded Add-Client-style modal routing
   to `FinancialEventService`/`FinBill`/`FinInvoice`. No new ledgers.

---

## Cross-loop coordination (HR loop runs concurrently on `hr/*` → same auto-deploying main)

**Read `docs/hr-module-rebuild-plan.md` before every milestone; `git fetch` + skim `hr/*` + main.**

- **Payroll→Finance bridge (M5) — the headline shared seam.** HR owns pay CALCULATION
  (`PayrollExportService`/`NzPayrollCalculatorService`/`PayslipService`). Finance owns **POSTING** the run to
  the GL + the payment run + IRD payday filing. **The bridge is ~95% wired and tested already** (don't
  rebuild it). ⚠️ **Overlap:** HR's plan claims **M5-1 (generate payslips inside lock)** and **M5-2 (net-pay
  payment run)** as HR milestones. These are exactly the two open gaps. **Decision:** payslip-in-lock is
  HR-owned (it touches `PayrollExportService::lockRun` + `PayslipService`); the **net-pay payment run** is
  Finance-owned (it creates a `FinPaymentRun`/bank file + posts DR 2300/CR Bank). Before building Finance
  M5, check whether HR has shipped either — if HR built the payment run, **reuse it**; if not, build the
  Finance payment-run leg and leave a clearly-commented integration point for HR's lock change. **One bridge.**
- **Expenses → AP.** HR's M8-5 dispatches `PostExpenseJournalJob` on approve — but `HrExpenseClaimObserver`
  already routes expense claims via `ProcessFinancialEventJob` (the modern path). Coordinate: **don't
  resurrect the orphaned `PostExpenseJournalJob`**; the modern path is canonical. Reimbursement payment (the
  AP-disbursement leg) is the Finance side.
- **Approvals inbox.** HR's M9-3 wires a unified `ApprovalWorkflowService` inbox (leave/expense/timesheet/
  offer/pay-run). Finance payment-run + spend approvals should plug into the **same** inbox, not a fork.
- **Shared primitives.** HR shipped `resources/js/components/hr/` (hr-hero, hr-tabs→TabStrip, people-picker,
  status-badge, wizard). Finance mirrors with `resources/js/components/finance/` but **imports the same
  underlying `TabStrip`/`WizardShell`/`PeoplePicker`** — fork nothing. Calendar: reuse HR's planned
  `components/calendar/calendar-view.tsx` wrapper.
- **Tokens:** HR added `--category-hr`. Finance adds `--category-finance` in the same three CSS sites — no conflict.

---

## Milestones

> Order: design spine (M0) + GL hardening (M1) land first; AR data-blindness (M2) is a P0 so it's early;
> Payroll (M5) isn't blocked by later work. Each milestone = one `--no-ff` merge to main after all gates green.

### M0 — Foundations: the Finance design spine `[x] DONE — branch finance/m0-design-spine, gates green`
Land the spine M1–M10 build on. **No behaviour change**, design only.
- **[x] M0-1 Finance hero category token.** Added `--category-finance`/`-bg` (`oklch(... calc(h + 170))`,
  teal/green money hue) at all three `app.css` sites (`@theme` ~117, `:root` ~221, `.dark` ~346) +
  `--color-category-finance(-bg)`. Token resolves; types/build green.
- **[x] M0-2 `PageHeroCategory` + 'finance'.** Added `'finance'` to the union (`page-hero.tsx:35`); the hero
  machinery maps `category` → `var(--category-${category})` generically, so no other change needed.
- **[x] M0-3 `resources/js/components/finance/` primitives.** Shipped `finance-hero.tsx` (FinanceHero preset),
  `finance-tabs.tsx` (FinanceTabs over TabStrip + `useFinanceTab` `?tab=` sync), `money.tsx`
  (`formatMoney`/`formatMoneyCompact` en-NZ NZD + decimal-safe `AmountField` + `MoneyBadge`),
  `posting-preview.tsx` (`PostingPreview` DR/CR card + cents-safe `journalBalance` check), `wizard.ts`
  (re-export shared WizardShell kit + `useWizard`), `index.ts` barrel (re-uses HR's `StatusBadge`, no fork).
  10 vitest specs green. *Note:* `AccountPicker`/`VendorPicker`/`CustomerPicker` + a shared finance data
  table are deferred to the hubs that consume them (M1–M3) — they need real endpoints, so building them now
  would be stub UI (house rule). The backend-free spine (hero/tabs/money/posting/wizard) is what M0 lands.
- **[x] M0-4 `category="finance"` sweep.** Idempotent quote/brace-aware codemod added `category="finance"`
  to all **104** finance `<PageHero>` tags. Gates: types ✓, vitest 10/10 ✓, eslint (components + 104 pages) ✓, build ✓.

### M1 — Ledger hub + GL hardening `[in progress — branch finance/m1-ledger]`
**Part A (GL hardening) + Part B (Ledger hub) DONE & merged as verified increments.** Part C (wizard modals) next.
- **[x] M1-1 Ledger hub + TabStrip.** Built `/finance/ledger` (LedgerController redirects to the first tab the
  user can open) + a shared `LedgerTabsFooter` (components/finance/ledger-hub.tsx) dropped into every ledger
  sub-page's `PageHero footer` slot — so chart of accounts · journals · cost centres · fiscal periods ·
  currencies · FX revaluations · fixed assets all read as one hub with the finance TabStrip. Tabs are
  permission-filtered (no 403 dead tabs); each sub-route keeps its own controller/data and stays live (no
  hard redirect needed — the URLs now render the hub). Sidebar collapsed the scattered ledger items into one
  "General Ledger" entry. 3 LedgerHubRedirect tests; full Finance suite 53 green; types/build/lint/route:list green.
- **[~] M1-2 New Account / New Journal / FX-reval / Period-close as wizard modals.** *(Part C — old Create
  pages still work in the interim, so no dead buttons.)*
  - **[x] New Journal modal** — `components/finance/new-journal-dialog.tsx`: 3-step WizardShell (Details →
    Lines → Review) using the shared `PostingPreview` + `journalBalance` for a live debits==credits check;
    "Save & post" disabled until balanced. Wired into the journals Index (controller now passes `canManage` +
    accounts/cost-centres/funding-streams reference data, gated by the create permission). 2 index tests; full
    Finance suite 55 green; types/build/lint green.
  - **[x] New Account modal** — `components/finance/new-account-dialog.tsx`: 2-step WizardShell (Account →
    Options); ChartOfAccountsController@index passes canManage + parentAccounts/taxRates/fundingStreams; opens
    from the chart-of-accounts hero in place of the standalone Create page. 2 index tests; Finance suite 57 green.
  - **[ ] FX-revaluation modal**, **[ ] Period-close modal** — next (completes M1-2 → M1).
- **[x] M1-3 Fix `5020` leave-expense collision.** Added dedicated `5050 Leave Expense` (FinanceSeeder,
  idempotent) and repointed `config/finance.php` `event_accounts.leave_provision.debit` 5020→5050; `5020`
  stays ACC Employer Levy only. Test asserts a leave_provision event debits 5050/Leave Expense, balanced +
  idempotent. *(commit db73bcdd)*
- **[x] M1-4 `finance:verify-chart` name parity.** Added config-driven `config('finance.account_names')`
  (code→intended-name keyword, single source of truth) + a name-parity gate in `VerifyFinanceChart`; fails
  when a code is seeded under a contradictory name (would have caught 5020). Test covers the failure case. *(commit db73bcdd)*

### M2 — Sales & Receivables hub + AR data + recurring billing `[x]` COMPLETE (Edit-invoice modal deferred to M10 polish)
- **[x] M2-1 Receivables hub + TabStrip + wizard modals.** *Hub (commit bc1e2450):* shared
  `ReceivablesTabsFooter` (components/finance/receivables-hub.tsx) dropped into all 8 AR sub-page heros so
  invoices · quotes · recurring · billing · aged-AR · statements · price-books · allocations read as one
  hub (every tab `finance.ar.view`; credit-notes stay in AP since they're `finance.ap.*`-gated). Sidebar AR
  group collapsed to one "Receivables" entry. *Record Receipt modal (commit 2d121fe8):* RecordReceiptDialog
  (WizardShell) on the invoices index → posts the existing finance.receivables.allocate (balanced DR Bank/CR AR
  + FinPaymentAllocation, capped at outstanding); index now carries amount_due/amount_paid per row + canManage.
  *New Invoice modal (commit cc49cfb7):* NewInvoiceDialog (Details → Line items → Review), bill a client or
  funder, posts a draft to invoices.store with NZ GST per line; index passes clients + taxRates. NO empty-string
  Select values ('default' tax sentinel → null). *Deferred:* Edit-invoice modal — the working full Edit page
  stays; converting needs the invoice's lines which the index doesn't carry (M10 polish).
- **[x] M2-2 Kill AR data-blindness (P0).** Migrated receivables/aged-AR/statements reads to `FinInvoice` +
  `FinPaymentAllocation`; partial payments net. *(commit 892f32a9)*
- **[x] M2-3 `markPaid` posts a receipt journal (P0).** DR Bank / CR 1100 AR + `FinPaymentAllocation`, idempotent; balanced-receipt test. *(commit 892f32a9)*
- **[x] M2-4 Quote→Invoice conversion.** `QuoteController::convertToInvoice` (route quotes.convert-to-invoice,
  gated finance.ar.manage) builds a draft `FinInvoice` + lines from an accepted quote (net=amount, GST 15%),
  idempotent + links `converted_to_invoice_id`; "Convert to Invoice" action on quotes/Show. Also fixed a
  pre-existing bug where `QuoteController::store` wrote a non-existent `total` key (NOT-NULL `amount` never set
  → quote create failed). *(commit 1504d264)*
- **[x] M2-5 AR credit-note GST reversal.** Reverses revenue + 2200 GST proportionally; balanced. *(commit 892f32a9)*
- **[x] M2-6 Recurring charges engine.** Corrected columns (`is_active`/`next_charge_at`), daily schedule, `BillingEntry`→`FinInvoice`; test. *(commit 892f32a9)*
- **[x] M2-7 Retire dead AR code.** Deleted `BillingJournalService` + `PostBillingJournalJob` (orphaned — job dispatched nowhere; service referenced only by that job + a stale docblock). route:list/suite clean. *(commit 6f622e5f)*

### M3 — Purchases & Payables hub `[x]` COMPLETE (Edit-Bill modal + payment-run-modal descoped — see M3-1)
- **[x] M3-1 Payables hub + TabStrip + wizard modals.** *Hub DONE (commit 430c1cd5):* `PayablesTabsFooter`
  (components/finance/payables-hub.tsx, mirrors receivables-hub.tsx) in all 5 AP sub-page heros so bills ·
  purchase-orders · vendors · credit-notes · payment-runs read as one hub (every tab `finance.ap.view`).
  `PayablesController` redirects `/finance/payables` → first openable AP tab (bills); sidebar AP group collapsed
  to one "Payables" entry. 2 tests. *New Bill modal (commit 8aa7c64e):* NewBillDialog (Details → Line items →
  Review) on /finance/bills, each line requires an expense account + GST rate, posts a draft to bills.store;
  index passes accounts + canManage. **Surfaced + fixed a pre-existing P0** — `AccountsPayableService` stored the
  gst_rate PERCENTAGE (15) into the `decimal(5,4)` FRACTION column → 500 on every standard-GST bill (createBill/
  updateBill/credit-notes); also fixed `FinBillFactory`'s invalid `'void'` status (flaky). *New Vendor modal
  (commit 39067ecc):* NewVendorDialog (Details → Terms & review) on /finance/vendors → vendors.store. *New PO
  modal (commit 88403e3c):* NewPoDialog (Details → Line items → Review) on /finance/purchase-orders → purchase-
  orders.store (account optional per line). 5 store/posting tests. *Descoped by design:* Schedule Payment Run
  stays a full page — it's a multi-bill batch-selection workflow (bill_ids[] checklist + bank account + date),
  better suited to a page than a cramped modal; Approve/Process are already inline actions on the run Show page.
  Edit-Bill modal deferred to M10 (like Edit-Invoice — needs the bill's lines, which the index doesn't carry).
- **[x] M3-2 Bills `partial` status bug (P0).** Fixed `'partial'`→`'partially_paid'` (the enum value) across
  `BillController` summary (incl. total_overdue which only counted 'approved'), `CashFlowForecastService`
  projectOutflows, and `GlSyncService` bill push — all three silently excluded partially-paid bills. Test. *(commit c2dd2b28)*
- **[x] M3-3 PO/bill-number dedup.** Extracted `FinBill::nextNumber` + `FinPurchaseOrder::nextNumber` (robust
  MAX-of-numeric-suffix, per org/month); `AccountsPayableService` + `PurchaseOrderController` (store + convertToBill)
  use them; the controller's two duplicate string-orderBy generators (broke past 999/month) deleted. 3 tests. *(commit 5d627663)*

### M4 — Banking & Cash hub `[x]` COMPLETE
- **[~] M4-1 Banking hub + TabStrip + Bank-Reconcile workspace + confirm modal.** *Hub DONE (commit 93f81c1c):*
  `BankingTabsFooter` (components/finance/banking-hub.tsx, mirrors ledger-hub) in all 8 banking sub-page heros so
  accounts · transactions · reconciliation · matching · feeds · EFTPOS · petty-cash · match-rules read as one hub.
  Heterogeneous gates (bank.view / bank.manage / petty_cash.view→camelCase pettyCash in auth.can), so
  `BankingController` redirects `/finance/banking` to the first openable tab (mirrors LedgerController); sidebar
  Banking group collapsed + the separate Petty Cash entry folded into one "Banking" entry. 3 tests. *Remaining
  (next tick):* audit the bank-reconciliation workspace (create/show flow) + add a confirm modal / fill any real
  wiring gap (no stubs).
- **[x] M4-2 Activate match-rule engine.** `calculateMatchScore` now tags each candidate with the rule_type
  dimensions it satisfied; `matchUnmatchedTransactions` picks the highest-priority active rule whose rule_type the
  candidate satisfied (+ optional JSON conditions: min/max amount, description_contains) as the governing rule,
  uses ITS auto_confirm_threshold, and increments that rule's match_count on auto-confirm. 3 tests. *(commit bf5a3efe)*
- **[x] M4-3 Bank feeds: honest state.** *Audited — already honest:* feeds are env-gated behind
  `config('finance.bank_feeds.provider_setup_enabled')` (default false); the providers throw a clear "use CSV
  import" message but are unreachable (controller bails on the flag first); the Sync/Add-Feed buttons are
  `disabled` when off and the UI renders the provider_setup_message + a "CSV import" CTA. No dead buttons; CSV is
  the documented primary path. No code change needed.
- **[x] M4-4 Petty cash top-up/adjustment booking.** Top-up now posts a balanced DR Petty Cash (fund GL) / CR
  Bank (1000) journal (graceful balance-only fallback if accounts unconfigured). Also fixed a NOT-NULL
  `description` column vs nullable validation → 500 on description-less transactions (coalesce to ''). 2 tests. *(commit 501edb07)*
- **[x] M4-5 Reconcile-workspace audit + adjustment journal.** *(commit c24d32b7)* Audited sound:
  `completeReconciliation` throws on any unexplained variance (>$0.01) — a rec can't be finalised unbalanced; the
  Reconcile workspace's match/unmatch/complete are all wired. *Real gap fixed:* "match without journal" (bank
  fee/interest) marked a line reconciled with NO GL effect. `matchTransaction` now takes an optional adjustment
  account → posts a balanced adjustment journal (outflow DR account/CR bank, inflow DR bank/CR account) + matches
  the bank-side line; the workspace got an adjustment-account picker. 2 tests.

### M5 — Payroll end-to-end → Finance bridge `[x]` HR-OWNED, verified present (Finance-side sound)
**Verified 2026-06-14 (finance loop):** the Finance side of the bridge is in place and HR shipped its M5 (per HR's
memory). `PayrollJournalService` posts a BALANCED journal (DR gross + employer KiwiSaver + ACC levy, CR PAYE +
KiwiSaver + net-pay liability) via `PostPayrollJournalJob`; `AllocatePayrollCosts` listener +
`PayrollCostAllocationService` + `ProcessPayrollAllocationsJob` handle cost allocation; HR owns `PayslipService` /
`PayrollExportService` / payslip-in-lock. No Finance-side gap found — left as an integration note and advanced to M6.
- **[ ] M5-1 (HR-owned, verify) Payslip-in-lock.** Confirm HR shipped `generateBulkPayslips` inside
  `lockRun`; if not, leave an integration note. *Acceptance:* locking a payslip-less run posts a balanced journal + one payslip/employee.
- **[ ] M5-2 (Finance-owned) Net-pay payment run.** *Problem:* `PaymentRunService` pays only vendor bills;
  net pay never disbursed. *Evidence:* `PaymentRunService.php:104-164`. *Fix:* payroll-sourced `FinPaymentRun`
  / NZ direct-credit bank file from payslip `net_pay` + employee bank account (DR 2300/CR Bank), idempotent.
  *Acceptance:* an approved/posted run produces a Finance payment run paying each employee's net; balanced.
- **[ ] M5-3 Pay-run lifecycle UI in Payroll hub.** TabStrip (Runs · Payslips · Export profiles · GL mapping)
  + pay-run process modal (create→review w/ PAYE/KiwiSaver/net visible→approve→post→export→pay) surfacing
  journal-post status + failures. *Acceptance:* full lifecycle visible; deductions verifiable; post-failure surfaced (no silent `failed_jobs`).
- **[ ] M5-4 Bridge hardening.** Per-org role→GL mapping (reuse Integration mapping concept) replacing
  hardcoded codes (`PayrollJournalService:253`); preflight (open period + seeded accounts) with surfaced
  errors; reconcile run-gross vs payslip-gross. *Acceptance:* non-seeded org gets a clear error not a silent fail.
- **[ ] M5-5 IRD/PAYE payday filing.** *Problem:* `buildPaydayFilingPayload` dead; IRD covers GST only. *Fix:*
  surface a payday-filing export/record from a posted run on the IRD filings screen. *Acceptance:* a posted run yields a payday-filing artefact under IRD filings.

### M6 — Funding & Client Money hub + duplicate-backend reconciliation `[~]` (P0 balance-pollution fixed; backend-unification + hub remain)
- **[ ] M6-1 Funding & Client Money hub + TabStrip.** *Cross-module* — funding-streams + donor-funds live in
  finance (finance.admin / finance.reports.view) but funding/funding-claims + client-funds live in OPERATIONS
  (routes/operations.php: FundingController/FundingClaimController/ClientFundController). A clean hub needs the
  operations UI migrated into finance + old routes redirected — bigger + riskier than the prior same-module hubs;
  deferred. Tabs: Funding streams · Funding claims · Client/resident funds · Donor/trust funds · Service billing.
- **[~] M6-2 Reconcile client-money backend (P0).** *Balance-pollution slice DONE (commit f9d1fbf9):*
  `ClientLedgerService` mixed operational `FinCostAllocation` outflows (org cost-of-support, thousands/week) into
  the resident's PERSONAL running balance → families saw a hugely-negative balance. Now segregated — running
  balance/opening/personal totals move only on `ClientLedgerEntry`; operational rows shown for transparency +
  reported as `summary.operational_outflows`; each entry has `affects_personal_balance`. Consumed by client
  financials tab + insights API + summary service. 1 test. *Remaining:* the BACKEND UNIFICATION — `ClientFund`
  (legacy, populated, operations-written via ClientFundController) vs `ClientLedgerEntry` (the store
  ClientLedgerService reads, written via observer→GL). Decide one canonical store, point both client profile
  finance tabs + family portal at it, retire the other. (NOTE: `FundingService` is NOT dead — used by
  CheckExpiringAgreementsJob — so plan M6-5 is moot.)
- **[ ] M6-3 Client-Money Transaction modal.** Embed a permission-gated, audited "Record client transaction"
  modal (deposit/withdrawal/purchase/reimbursement) on the client finance tab → posts to the canonical
  trust-account path. *Acceptance:* transaction recorded from a modal; trust journal posts; audited.
- **[ ] M6-4 Funder remittance reconciliation.** Add approved-vs-claimed-vs-received tracking + match a funder
  payment to claims. *Acceptance:* a funder remittance reconciles against claims.
- **[~] M6-5 Delete stale `FundingService`.** VERIFIED NOT DEAD — `App\Services\Operations\FundingService` is
  used by `CheckExpiringAgreementsJob::handle`. Not removable; closing as not-applicable.

### M7 — Tax & Compliance hub `[~]` (hub + IRD-honesty done; M7-2 payday filing remains, cross-module)
- **[x] M7-1 Tax hub + TabStrip.** *(commit 8459af91)* `TaxTabsFooter` (components/finance/tax-hub.tsx, mirrors
  banking-hub) in the 4 tax sub-page heros — GST returns · IRD filing · audit exports · consolidation — read as
  one hub. Heterogeneous gates (tax.view / tax.manage / reports.view / admin), so `TaxController` redirects
  `/finance/tax` to the first openable tab (collect-first, like Ledger). consolidation/Index converted from a
  bespoke header to PageLayout+PageHero so it joins the hub. Sidebar collapsed the scattered GST/IRD/Audit/
  Consolidation entries into one "Tax & Compliance". 3 hub tests. (Modals — Prepare-GST etc. — deferred; the
  prepare/create flows already work as pages.)
- **[ ] M7-2 PAYE/IR348 payday filing (links M5-5).** Cross-module (payroll runs are HR-owned). Surface a
  payday-filing artefact from a posted pay run on the IRD screen. `buildPaydayFilingPayload` exists but is unwired.
  Deferred — needs the HR payroll-run boundary.
- **[x] M7-3 IRD GST e-filing honesty.** *(commit f555d8a7)* The submit path FAKED success (random `IRD-xxxx`
  reference + "received and queued") whenever any api_key was set, transmitting nothing — a user could believe a
  return was filed with IRD. No live Gateway integration exists (SOAP + WS-Security X.509), so submission now
  REFUSES unless `services.ird.simulation_enabled` is set, and a simulation is clearly labelled (SIM- reference,
  status 'simulated', "NOT transmitted" message, warning flash). 2 tests + updated the status-flow test.

### M8 — Reports & Planning hub + budget unification `[~]` (hub done; budget-unification P0 remains)
- **[x] M8-1 Reports hub + TabStrip.** *(commit e6f98de1)* `ReportsTabsFooter` (components/finance/reports-hub.tsx,
  mirrors tax-hub) in all 9 report sub-page heros — P&L · balance sheet · trial balance · cash flow · aged AR ·
  aged AP · funding summary · budget vs actuals · cash-flow forecast. All `finance.reports.view` (homogeneous), so
  `ReportsController` redirects `/finance/reports` → P&L (mirrors PayablesController). Every page already used
  PageHero + reads the real GL. Sidebar collapsed the 5-item Reports group to one "Reports" entry. 2 hub tests.
  (Period selector deferred — each report already has its own period filter.)
- **[~] M8-2 Unify budgets to one engine.** *Live-GL-actuals slice DONE (commit 069a36fc):* the most-visible bug —
  `getBudgetVsActualsReport` read each line item's denormalised `actual_amount` (only fresh after a manual
  `syncActuals()`), so the report was stale. Now computes actuals LIVE from posted journal lines (reuses
  `mapAccountToLineItem` + `sumPostedJournalLines` over the budget's fiscal-year range); per-line + category + grand
  variance derive from the live figure. Always accurate without a sync. 1 test. *Remaining (own tick — LARGE):* the
  STORE unification — `BudgetActualsController` reads the Governance `Budget` model, not Finance `SiteBudgetLine`;
  retire the denormalised `actual_amount` write path (`syncActuals` is now optional for the report); implement+bind
  `BudgetSyncInterface`; collapse any double scheduled writer; unify category vocab. Cross-module Governance+Finance.
- **[ ] M8-3 SpendApprovals → AP.** *Fix:* `SpendApproval::approve()` creates/links a `FinBill` (or gates the
  AP bill/payment-run via the `source` morph) so approved spend reaches Finance. *Acceptance:* approving a spend creates a financial record.
- **[ ] M8-4 Cash-flow forecast payroll feed.** Add payroll-due dates as an outflow source. *Acceptance:* forecast includes upcoming pay runs.

### M9 — Cross-module capture + Finance calendar `[ ]`
- **[ ] M9-1 Finance calendar (site-calendar parity).** Build `/finance/calendar` reusing the shared
  FullCalendar wrapper (HR M4-1's `calendar-view.tsx`), surfacing invoice/bill due dates, payment runs,
  recurring charges, pay-run dates, GST periods+due, IRD payday dates, period close, depreciation runs,
  budget periods. Click day/entry → modal. *Acceptance:* visually/behaviourally matches the site calendar; events real.
- **[ ] M9-2 Capture-at-source modals.** Damages → AP maintenance + optional insurance AR; Catering shopping
  complete → HouseLedger groceries; Respite confirm → AR vs funder + funding drawdown; Asset capitalisation →
  `FinFixedAsset` + journal; operational AP → `FinBill`+`FinVendor` attribution; `SiteVendor.fin_vendor_id` FK.
  Each an Add-Client-style modal routing to existing paths; permission-gated + audited. *Acceptance:* each capture posts to the canonical path; no new ledger.
- **[ ] M9-3 Scheduled-job + notification hygiene.** Delete the 4 orphaned jobs (`ImportBankTransactionsJob`,
  `PostBillingJournalJob`, `PostExpenseJournalJob`, `ProcessPaymentRunJob`) or wire them; confirm bill-due +
  variance notifications deliver. *Acceptance:* no orphaned job classes; notifications fire (tests).

### M10 — Settings & Integrations + final de-dup + polish `[ ]`
- **[ ] M10-1 Settings hub.** Integrations (Xero/MYOB) · Account mapping · Tax/GST config · Fiscal calendar ·
  Finance permissions, as TabStrip + modals.
- **[ ] M10-2 Wire Xero account mapping.** *Problem:* mapping UI saves `account_mapping` but the push reads
  `account->code` only. *Fix:* consume the saved mapping in `XeroSyncProvider` push/export. Add a MYOB
  "not yet supported" banner (no dead controls). *Acceptance:* a non-code mapping is honoured in sync.
- **[ ] M10-3 Final de-dup sweep (all three classes).** Verify every collapsed route redirects; extract any
  remaining near-identical finance hero/table/card code into `components/finance/`; confirm no cross-loop fork
  with HR (payroll bridge, expenses, approvals, calendar, primitives). *Acceptance:* no duplicate concept pages; dup map updated.
- **[ ] M10-4 Demo seeders.** Extend a `FinanceDemoSeeder` so every hub renders populated. *Acceptance:* fresh `migrate:fresh --seed` → no empty finance hub on the dev server.
- **[ ] M10-5 a11y + responsive + end-to-end pipeline tests + final parity.** Axe (no criticals) + mobile on
  every hub; consistent empty/loading/error states; end-to-end finance pipeline tests; side-by-side every hub
  vs Rostering on oblivionfindings.com. *Acceptance:* axe clean; responsive; DoD met.

---

## Verification gates (every milestone, before merge to main)

`npm run types` (0 errors) · `npm run build` (clean) · `npm run lint` (clean on touched) ·
`php artisan test tests/Feature/Finance` (NON-parallel) + touched suites + new tests (every posting pipeline
asserts a **balanced, idempotent** journal) · `php artisan route:list` (no dead routes; redirects resolve) ·
playwright visual on touched finance pages · axe (no criticals) · browser smoke on oblivionfindings.com as
demo admin (every modal, no console errors, real data, money posts, hero/tabs/calendar parity). Then update
this doc + memory, `git fetch`+rebase, merge `--no-ff`, push.

## Deploy runbook deltas (per milestone introducing permissions/seeders)
New permission keys → add to the seeder `DatabaseSeeder` calls + `db:seed --class=… --force` on deploy.
New demo data → `FinanceDemoSeeder`. Chart changes → reseed `FinanceSeeder` (org 0) + backfill script for tenants.
