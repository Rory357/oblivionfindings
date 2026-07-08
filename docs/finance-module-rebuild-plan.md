# Finance Module — Rebuild Plan (Design Parity + Xero/MYOB Completeness + Payroll→Finance + Cross-module reconciliation)

---

## ✅ Finance rebuild — Definition of Done status (2026-06-15)

The autonomous /loop has reached **steady state**: every finance-internal milestone achievable in a headless
loop is shipped to `main`, gated (types/lint/pint/build + the Finance feature suite, **131 green**), and ticked
below. The loop is **paused** — remaining work needs a browser or cross-module coordination (see below).

**SHIPPED (M0–M10):**
- **8-hub consolidation** (105 pages → Ledger · Receivables · Payables · Banking · Tax · Reports · Settings) — each
  a Rostering-style hero + standardised TabStrip; every hub index redirects to its first openable tab (or is a
  by-design landing page); sidebar collapsed; redirect/403 tests per hub.
- **Finance obligation Calendar** (`/finance/calendar`) — `FinanceCalendarAggregator` + 4 real-data providers
  (invoice/bill due, payment-run, NZ-GST deadline) → FullCalendar page reusing the shared wrapper, design-token
  event colours, source legend, read-only detail dialog.
- **GL integrity** — balance + open-fiscal-period enforced by `JournalPostingService::post()`; idempotency by
  state-machine; M8-2 live GL actuals (budget-vs-actuals reads posted journal lines); end-to-end lock-in tests for
  invoice/bill/payment-run/credit-note/expense/leave pipelines.
- **Xero account_mapping** honoured in the GL push (mapped → AccountID, else AccountCode); MYOB explicitly unsupported.
- **IRD honesty** (no fake live submission); **FinanceDemoSeeder** (every hub + the calendar render populated on
  `migrate:fresh --seed`); **component de-dup** (`FinanceSummaryCard`); **Edit-via-modal** for draft bills + invoices.

**DEFERRED — needs the live dev server / a browser (out of scope for the headless loop, → USER):** axe a11y +
responsive sweep on every hub; side-by-side-vs-Rostering visual parity on oblivionfindings.com.

**DEFERRED — cross-module (touch Governance/HR/Sites domains owned by other loops; need coordination):** M8-2 STORE
unification (Governance `Budget` vs Finance `SiteBudgetLine`); M6 client-money/funding backend unification; M9-2
capture-at-source modals (damages/catering/respite/asset → canonical finance paths); M7-2 payday filing (HR-owned).
Investigated + intentionally NOT built (would be wrong/invented): M8-3 SpendApproval→bill (governance pre-auth),
M8-4 payroll outflow (needs a FinPaymentRun.type / HR payroll model).

---

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
- **[~] M8-3 SpendApprovals → AP — investigated; NOT a simple "create a bill on approve".** `SpendApproval` is a
  GOVERNANCE pre-authorisation (has `valid_until`, `requires_board`, `resolution_id`, board-resolution link) with a
  NULLABLE `source` morph and NO vendor field; `approve()` only flips status + audits. Creating a `FinBill` on
  approve would be wrong accounting (a payable before the expense is incurred) AND impossible (FinBill.vendor_id is
  a required FK the approval lacks). The correct integration is the OTHER direction — when a bill/PO is created it
  links to its approval via the `source` morph — plus optionally ENFORCING approval on bills over a threshold.
  Workflow-design call; deferred (not a contained finance fix).
- **[~] M8-4 Cash-flow forecast payroll feed — investigated; needs a schema/HR change.** `FinPaymentRun` (status
  draft/approved/processing/completed + payment_date + total_amount) is the only finance-side dated payment
  obligation, but it has NO payroll-vs-vendor `type` column — so adding payment runs to `projectOutflows` would
  double-count the vendor bills already summed by due_date. A clean payroll outflow needs either a `FinPaymentRun.type`
  (+ exclude bill-paying runs from the bill sum) or the HR payroll-run model. Deferred (cross-module / schema).

### M9 — Cross-module capture + Finance calendar `[ ]`
- **[x] M9-1 Finance calendar — COMPLETE (backend 0332c90c + page 98524f7e).** Part 2 added
  `FinanceCalendarController@index` (Inertia `finance/Calendar`) + `finance.calendar.index` route +
  `resources/js/pages/finance/Calendar.tsx` — a month/list calendar reusing the shared `CalendarView`
  (FullCalendar) wrapper, loading obligations client-side from the JSON feed via FullCalendar's `datesSet`
  (initial + month nav), design-token event colours (invoice→success / bill→warning / payment-run→
  category-finance / GST→info; overdue overrides to critical), a clickable source legend/filter, per-range
  hero stats (obligations/overdue/money-in/money-out), and a read-only detail dialog (ref, counterparty,
  amount, status, direction, period, deep-link). Sidebar gained a Calendar entry under finance Overview
  (gated `finance.dashboard`). types/eslint/build green; 6 calendar tests (35 assertions); both routes live.
  Backend (part 1): a
  `FinanceCalendarAggregator` mirroring `SiteCalendarAggregator` (static `defaultProviders()` registry +
  optional injected override) unioning four real-data providers into one sorted, deep-linked feed of
  `FinanceCalendarItem`s: `InvoiceDueProvider` (AR `due_date`), `BillDueProvider` (AP `due_date`),
  `PaymentRunProvider` (`payment_date`), `GstReturnProvider` (NZ GST deadline computed from period end —
  28th-of-next-month with Nov→15 Jan / Mar→7 May concessions). `FinanceCalendarController@events` serves the
  JSON feed (`finance.calendar.events`, gated `finance.dashboard`, `?sources=` filter). 5 tests; suite 115 green.
  Deliberately omitted (no invented dates): IRD income-tax filing (no stored deadline), pay-run payday dates
  (HR-owned M7-2), budget-period bands. **NEXT (part 2):** `@index` Inertia page reusing the shared
  `calendar-view.tsx` wrapper (month grid + source legend + day/entry → read detail modal) + `finance.calendar.index`
  route + sidebar nav entry. *Acceptance:* visually/behaviourally matches the site calendar; events real.
- **[ ] M9-2 Capture-at-source modals.** Damages → AP maintenance + optional insurance AR; Catering shopping
  complete → HouseLedger groceries; Respite confirm → AR vs funder + funding drawdown; Asset capitalisation →
  `FinFixedAsset` + journal; operational AP → `FinBill`+`FinVendor` attribution; `SiteVendor.fin_vendor_id` FK.
  Each an Add-Client-style modal routing to existing paths; permission-gated + audited. *Acceptance:* each capture posts to the canonical path; no new ledger.
- **[~] M9-3 Orphaned-job sweep DONE; notification audit deferred.** All 4 jobs resolved: `PostBillingJournalJob`
  already removed in M2-7; `PostExpenseJournalJob` is wired (HR `ExpenseService::approveClaim` dispatches it on
  M8-S1); `ImportBankTransactionsJob` + `ProcessPaymentRunJob` were truly orphaned (never dispatched/scheduled/
  referenced — thin async wrappers around `BankReconciliationService::importTransactions` and `PaymentRunService::
  processPaymentRun`, both already called synchronously by their controllers) → DELETED (main 24ce6b34, route:list
  clean, Finance suite 110 green). Remaining: confirm bill-due + budget-variance notifications deliver (own tick).

### M10 — Settings & Integrations + final de-dup + polish `[ ]`
- **[x] M10-1 Settings hub — SHIPPED (main 2bf7b622); the 8th/final hub.** `SettingsController@index` redirects
  `/finance/settings` to the first openable admin tab (mirror TaxController; a tab LIST not a perm-keyed map so
  order survives a future differently-gated tab), and a `SettingsTabsFooter` (`components/finance/settings-hub.tsx`)
  drops into each sub-page's `PageHero footer`. Scope is the TWO genuinely standalone `finance.admin` surfaces —
  accounting **Integrations** (Xero/MYOB) and **Funding Streams** — which sat loose in the sidebar "Other" group;
  they collapse into one "Settings" sidebar entry. *Deliberately NOT pulled in (would fork the concept — already
  hub tabs):* fiscal periods / cost centres / currencies (Ledger hub) and match rules (Banking hub). Account
  mapping already lives as the Integrations→Mapping detail; standalone Tax/GST-config + fiscal-calendar surfaces
  don't exist (not invented — no stubs). types/eslint/build green; SettingsHubTest (redirect+403); suite 118 green.
  **8-HUB CONSOLIDATION COMPLETE:** Ledger · Receivables · Payables · Banking · Tax · Reports · Settings (+ the
  Calendar feature surface). Remaining M10-1 scope (Tax/GST-config tab, finance-permissions UI) deferred — no
  existing surface to collapse; would be net-new (do only when the backend exists).
- **[x] M10-2 Wire Xero account mapping — SHIPPED (main 5a9e98bc).** Confirmed the bug: the integration mapping
  UI saves `account_mapping` (JSON, cast array, keyed by `(string) local account id` → Xero **AccountID**) but
  `XeroSyncProvider`'s `manualJournalLinePayload`/`billPayload` only ever emitted `account->code` as `AccountCode`,
  so a saved mapping was ignored. *Fix:* a single `accountReference($integration, $account)` helper — a saved
  mapping WINS and is sent as Xero `AccountID` (exact account); an unmapped account falls back to
  `AccountCode => account->code` (prior behaviour). Threaded `$integration` through the journal/bill payload
  builders; GL journal untouched (export-only, money-safe + idempotent). MyobSyncProvider already throws an explicit
  "not supported yet" (no dead controls — left as-is). New test asserts mapped→AccountID + unmapped→AccountCode;
  existing no-mapping test still green. Finance suite 119 green. *Acceptance met:* a non-code mapping is honoured in sync.
- **[x] M10-3 De-dup sweep — DONE (main 83fcbb99).** Route-redirect coverage already complete (Ledger/Banking/
  Payables/Reports/Tax/Settings hub redirect tests; Receivables is a landing page by design). Component sweep:
  the only GENUINE byte-identical duplication was the hub KPI/summary card — copy-pasted 7× across invoices (4) +
  bills (3) index heroes. Extracted `components/finance/summary-card.tsx` (`FinanceSummaryCard`, tone-keyed to the
  status palette) and replaced all 7 inline blocks with one-line calls; output byte-identical (the redundant
  `dark:` variants dropped — status tokens already adapt). gst-returns/site-dashboard are deliberate variants
  (solid badge, mono values) — left untouched (folding them in would change their look). Other candidates checked
  + rejected as NOT genuine duplication: KPI cards already use 3 different shared components (FleetStatCard /
  OpsStatCard / inline KpiCard); status-config maps are domain-specific per entity (bespoke, not duplicated);
  empty-states are contextual. (Known but out-of-scope: ~70 finance pages re-implement `Intl.NumberFormat` NZD
  inline instead of `money.tsx`'s `formatMoney` — a large mechanical migration, not a copy-paste block; deferred.)
  No cross-loop fork with HR. types/eslint/build green; suite unchanged at 126.
- **[x] M10-4 Demo seeders — SHIPPED (main 8519cce0).** Root cause: `DatabaseSeeder` called only
  `FinancePermissionsSeeder` (permissions, no data) so every finance hub + the new Calendar rendered EMPTY on a
  fresh seed. Added `FinanceDemoSeeder` (wired into `DatabaseSeeder` after the other `*DemoSeeder`s), modelled on
  the DuskDatabaseSeeder factory recipe but with NEAR-TERM invoice/bill/payment-run/GST dates (+ one overdue
  invoice and bill) so the Finance Calendar shows live, correctly-coloured events in the current view. Scoped to
  org 1, idempotent (skips when demo invoices exist). Seeds chart of accounts + posted journals (Ledger), bank
  accounts + petty cash (Banking), vendors + bills + PO + payment run (Payables), invoices + credit note
  (Receivables), a recent GST return (Tax + Calendar), plus a fixed asset + donor fund. 3 tests (every hub
  populates · calendar returns live events incl. overdue · idempotent). Finance suite 122 green. *Acceptance met.*
  (Not seeded — no factory: FinFundingStream / FinAccountingIntegration; Settings is a config hub, empty-OK.)
- **[~] M10-5 end-to-end pipeline tests DONE (main ab8e726e); a11y/responsive live-verify deferred.** Audited GL
  journal-posting coverage across every money pipeline. Balance + open-fiscal-period are architecturally enforced
  by `JournalPostingService::post()` (throws on imbalance / non-open period). Idempotency is by state-machine —
  each poster guards on status (`approveBill` requires draft/awaiting_approval; `processPaymentRun` requires
  approved; `approveCreditNote` requires draft; invoice/expense/leave guard on journal_id/event-key) so replay
  can't double-post (the coverage audit's "no guard → double-post" suspicion was disproved by reading the code —
  **no bug**). The only GAP was *tests*: bill-approval and payment-run had zero journal-posting coverage. Added
  `BillAndPaymentRunJournalPostingTest` (4 tests): each posts a single BALANCED journal to the right accounts
  (DR Expense/CR AP; DR AP/CR Bank) and replaying throws + posts no second journal. Finance suite 126 green.
  *Remaining (deferred — needs the live dev server / browser, out of scope for the headless loop):* axe a11y +
  responsive sweep on every hub, side-by-side-vs-Rostering parity on oblivionfindings.com.
- **[x] M10-6 Edit-via-modal — Bills + Invoices DONE (main 28bc9d7f + 11cba27a).** Invoices: aligned the update
  contract with create (UpdateInvoiceRequest gained client_id/funding_body + required_without_all client_name + the
  'default'→null `prepareForValidation`; `InvoiceController@update` resolves the client and derives
  client_id/client_name/email/address like `store`; `@index` eager-loads lines), then added the `invoice` edit prop
  to NewInvoiceDialog + a draft-only Edit row-action on the Receivables index. Draft-only + post-on-SEND keep it
  GL-safe. 3 tests (client-billed derive + 'default' tax sentinel; funder-billed funding_body; non-draft refused);
  suite 131 green. Earlier (Bills):
  `NewBillDialog` now takes an optional `bill` prop → EDIT mode (prefill, gst fraction→percentage, PUT
  `finance.bills.update`); Payables index gained a draft-only Edit row-action (keyed-per-row modal) and
  `BillController@index` eager-loads `lines` for prefill. GL-safe (update already rejects non-draft). 2 tests
  (edit persists + gst-as-fraction; non-draft refused); suite 128 green. **Invoices NOT done:** the invoice
  update contract is asymmetric with create — `UpdateInvoiceRequest` accepts `client_name` (required) but not the
  create dialog's `client_id`/`funding_body`, and `update()` only writes `client_name`, so reusing `NewInvoiceDialog`
  in edit mode would 422 on client-billed invoices. Doing it cleanly requires aligning the invoice update contract
  to the create contract (accept `client_id`/`funding_body`, derive `client_name` from the client) + eager-loading
  invoice lines in the index — a follow-up tick. Full-page `invoices.edit` already works, so this is a UX upgrade
  not a gap.

---

## Completion phase (C-series) — appended 2026-07-07 after full re-audit

**Method:** 4 parallel adversarial code sweeps + a full browser sweep (local dev server at `bdf8189e` == origin/main,
demo admin, FinanceDemoSeeder data) — every hub, every tab, the dashboards, operations funding/client-funds, and
`/hr/payroll` opened and probed; Rostering captured as the parity bar. Prior claims re-derived; corrections below.

### P0 defects found live (fix first — C0)
1. **`/finance/reports/profit-loss` → 500** on any org. `FinancialReportService::getAccountBalancesForPeriod`
   (`app/Domain/Finance/Services/FinancialReportService.php:594-617`) applies `FinAccount::forOrganization()`
   (unqualified `where organization_id`) then joins `fin_journal_lines`+`fin_journals` → MySQL 1052 ambiguous
   column. Same pattern risk at `:534` (funding-stream summary joins). **Fix:** qualify the scope
   (`qualifyColumn`) so every joined use is safe; regression test hits the P&L endpoint.
2. **Flagship hero off-contract:** `pages/finance/Dashboard.tsx:469` renders `PageHero` **without**
   `category="finance"` (purple `--primary`, every other finance page is the amber finance token) AND hardcodes
   "across **14 sites** and **5 funding streams**" as literal text (`:484-485`) while real `siteCount` props sit
   in the meta row. Fixed inside C1.
3. **Local demo-data trap (dev only):** `FinanceDemoSeeder` guard = "any FinInvoice for org 1 exists" — one junk
   factory invoice blocked all demo data. Extend guard/seeder in C8 (seed marker, not mere existence).

### Consistency ledger (browser-verified 2026-07-07)
Global truths (apply to EVERY finance list page unless noted): **no tab count badges anywhere**, **no row
context-menus anywhere**, **no Skeleton loaders**, **EmptyState/EmptySearch component used nowhere** (bespoke
empty divs exist on ~half the pages), **~80 of 106 pages format money with inline `Intl.NumberFormat`** instead
of `money.tsx`, **16 pages use native `confirm()`**, **8 report pages + Dashboard + donor-funds share a
hardcoded hex `CHART_COLORS` array**, ~25 pages hand-roll `statusConfig` colour maps beside ~15 correct
`StatusBadge` users. Export exists only on: invoices/Show, IrdFilings/Show, CashFlowForecast/Show,
bank-accounts (Index+Show), Consolidation/RunResults, audit-exports.

| Surface (hub) | Hub footer tabs | Notable divergences beyond the global truths |
|---|---|---|
| Dashboard `/finance/dashboard` | – (FinanceHubsBar) | **No `category="finance"`** (purple hero); hardcoded site/stream counts; hex chart colours; otherwise strongest page (formatMoney ✓ StatusBadge ✓ NeedsAttentionStrip ✓ wizard quick-actions ✓) |
| executive-dashboard | ✗ none | `category` ✓ (verified via CSS probe — earlier sweep claim wrong); `formatCurrency` from **fleet-utils**; hand-rolled severity colours; orphan (no hub) |
| sites-overview `/finance/sites` | ✗ none | orphan dashboard; fleet-utils money; hex chart colours |
| site-dashboard (drill-down) | ✗ | fleet-utils money, hex chart colours |
| clients/Financials (drill-down) | ✗ | fleet-utils money; severity colour map |
| Ledger ×7 tabs | ✓ all 7 pages | cost-centres+currencies `confirm()`; fx-reval `confirm()` on post; Create/Edit full-pages for accounts/fixed-assets/fx-reval; accounts Show inline `formatNZD` |
| Receivables ×8 tabs | ✓ all 8 (incl. billing/Entries — earlier claim wrong) | quotes+recurring+price-books Create/Edit full-pages; invoices Show `confirm()`×2; no aged-AR drill filters |
| Payables ×5 tabs | ✓ all 5 | bills/PO/vendors/credit-notes Create/Edit full-pages; PO Show `confirm()`×2; payment-run approve/process bare `router.post` |
| Banking ×8 tabs | ✓ all 8 | bank-accounts+petty-cash Create full-pages; bank-feeds `confirm()`; match-rules `confirm()`; reconcile workspace = page (by design, confirm steps need modals) |
| Tax ×4 tabs | ✓ all 4 | gst-returns Show `confirm()` (mark filed); IrdFilings Show `confirm()` (submit); gst Prepare = full page; audit-exports Create full-page + `confirm()` delete; Intercompany is `/finance/intercompany/{group}` detail (no hub tab — fold under Consolidation) |
| Reports ×9 tabs | ✓ all 9 | **P&L 500 (C0)**; all 8 use hex `CHART_COLORS`; all inline Intl money; budget-vs-actuals lives here (`/finance/reports/budget-vs-actuals`) |
| Settings ×2 tabs | ✓ both | funding-streams inline CRUD forms + `confirm()` delete |
| Calendar | – (by design) | ✓ formatMoney; STATUS_TONE local map |
| donor-funds Index/Show | ✗ **orphan** | Show imports **`ui/tabs`** (only straggler); hex colours; Create full-page; inline receipt/expenditure/report forms |
| CashFlowForecast | ✓ Reports footer | Create full-page; `confirm()` delete ×2 |
| operations/client-funds ×3 | ✗ (ops module) | **purple ops hero**, no finance idioms at all; Create full-page → C4 migrates into Funding hub |
| operations/funding + claims | ✗ (ops module) | plain pages, no PageHero contract → C4 |
| hr/payroll | HR tabs (1 tab visible) | run list has Lock/Pay net/Bank file/Export actions; **no GL-failure surfacing, no filing action** → C5 |

**No dead buttons / stub controls / orphan endpoints found** (route map 246 routes, all POST/PUT/DELETE have UI
triggers; all `disabled` states conditional). 7 WizardShell dialogs exist (journal, account, bill, invoice, PO,
vendor, receipt) — the mould for C2.

### Seam table (owners re-verified 2026-07-07)
| Seam | Owner | Verified state | C-series action |
|---|---|---|---|
| Pay calculation (PAYE/KiwiSaver/net) | HR — `NzPayrollCalculatorService`, `PayslipService` | payslips generate in `lockRun` ✓ (`PayrollExportService:176-180`) | consume, never re-derive |
| Payroll GL post | Finance — `PayrollJournalService` via `PostPayrollJournalJob` (dispatched from `PayrollExportController@lockRun:163`) | balanced ✓, idempotent ✓, **hardcoded chart codes** (`:393-415`), **failures die in failed_jobs** | C5: per-org mapping + preflight + surfaced status |
| Net-pay disbursement | Finance — `postNetPayPayment` (DR 2300/CR bank, `PayrollJournalService:214-295`) + `buildNetPayDirectCreditCsv` (`:302-320`); UI `hr/payroll/index.tsx:404-419`; routes `hr.php:682,684` | **shipped ✓** (post-plan-doc) | C5: link a typed `FinPaymentRun`, forecast outflow |
| Payday filing (IR348) | Finance — `IrdFilingService::createPaydayFiling:40-87`, route `finance.php:583` | backend ✓, **no UI trigger**; **ESCT hardcoded '0.00'** (`IrdFilingService:68-69`), no payslip esct column | C5: filing action on run + esct column/calc (coordinate: HR owns payslip schema — additive migration OK per seam) |
| Finance calendar payroll | Finance — `PayrollObligationProvider` registered in `FinanceCalendarAggregator:51` | **shipped ✓** | none |
| Expenses → AP | Finance observers (`HrExpenseClaimObserver` → `ProcessFinancialEventJob`) | canonical path live | don't resurrect `PostExpenseJournalJob` |
| Client money | **split**: ops `ClientFundController` (writes `ClientFundTransaction`, **GL columns orphaned — `ClientFundJournalService` never called**, rows: 0 locally) vs finance `ClientLedgerEntry` (observer→GL wired, `ClientLedgerService` netting fixed f9d1fbf9, rows: 0) | **both empty in dev**; ledger-entry store is the schema/GL-complete one | C4: PAUSE-AND-ASK Chane, then unify |
| Budgets | Governance `Budget`+`BudgetLineItem` (denorm `actual_amount`) vs Finance `SiteBudgetLine`+live-GL. Report reads **live GL** ✓ (069a36fc intact); **ONE** hourly sync (`console.php:583` — "double sync" claim false); `BudgetSyncInterface` orphaned (0 impls); `fin_bills.spend_approval_id` **column exists**, no model relation/enforcement | C6: PAUSE-AND-ASK store, implement+bind interface, approval linkage |
| Shared UI spine | `TabStrip`/`WizardShell`/`StatusBadge`/`PageHero` shared app-wide | finance wraps them in `components/finance/` ✓ | never fork |

### C-milestones (finalised)
- **[x] C0 — Hotfixes.** DONE 2026-07-07 (merge `7807f855`). Qualified `organization_id` via `qualifyColumn()`
  in all 36 finance models' org scopes (both `fn (`/`fn(` spellings); new `ReportsRenderTest` renders all 8
  report tabs against posted journals + locks P&L figures (revenue 1150/expenses 400/net 750). Finance suite
  151 green. Browser-verified: P&L renders (amber hero, 9 tabs) on the local dev server.
- **[x] C1 — Overview hub (the 8th hub).** DONE 2026-07-07. `GET /finance` now serves the Summary dashboard
  (route NAME `finance.dashboard` kept → every existing caller lands on the hub; old `/finance/dashboard` URL
  → `Route::redirect`). New `OverviewTabsFooter` (components/finance/overview-hub.tsx): **Summary · Executive ·
  By site · Cash position**, all `finance.dashboard`-gated, rendered in all four heroes above the existing
  period-pills/filters row. Summary hero fixed: `category="finance"` (was purple `--primary`) + real
  site/funding-stream counts replace the hardcoded "14 sites / 5 funding streams". Executive + By-site brought
  on-contract: PageLayout width=wide, hero KPI stats promoted from body cards, `formatMoney` replaces
  fleet-utils `formatCurrency`, `StatusBadge` replaces the hand-rolled budget badge, `EmptyState` replaces
  bespoke empties, new shared `chart-palette.ts` (CSS-var palette) kills sites-overview's hex `CHART_COLORS`.
  NEW Cash-position tab: `CashPositionController` composes existing data only — live bank + petty-cash balances
  and the next-30-days obligations from `FinanceCalendarAggregator` (in/out/projected totals) — page fully
  on-contract. Sidebar entry renamed Overview → `/finance`. 65 breadcrumb hrefs + FinancePolicy/Rbac/visual
  test URLs updated off the old path. Gates: types ✓ eslint(touched) ✓ build ✓ route:list ✓ OverviewHubTest
  (5) + dashboard/nav tests 17 green ✓ browser-smoked all four tabs with screenshots, console clean.
  *(By design kept: FinanceHubsBar on Summary; genuine drill-downs site-dashboard + clients/Financials stay
  pages — they come on-contract in C3.)*
- **[x] C2 — Modal sweep — MERGED to main `fa184916` 2026-07-08.** Whole milestone shipped: 11 full-page
  flows → WizardShell dialogs + all 16 native `confirm()` → shared ConfirmDialog + guarded period-close modal.
  Browser-verified (donor-receipt + asset-disposal balanced journals, payment-run process guard, period-close
  guard — screenshots). 2 real GL bugs fixed (recurring-charge `starts_at` 500; asset-disposal 8100/8400
  out-of-balance). Finance suite 186 green. Only deferred item: GST-Prepare page→wizard (works as a page).
  Batch detail below.
- **[x] C2 (batch detail) — Modal sweep** (retire full-page flows via `Route::redirect` + WizardShell conversions, the M10-6
  edit pattern for edits; `alert-dialog` for every one of the 16 native `confirm()` sites; payment-run
  approve/process get confirm modals; period-close gets a guarded impact-preview modal; GST Prepare becomes a
  wizard ending in the return). Split into 3 batches:
  - **[x] C2a (committed `f4a06c41` on finance/c2-modal-sweep):** recurring charge · price book · petty-cash
    fund · bank account · audit export · cash-flow forecast → WizardShell dialogs; 9 retired URLs → NAMED
    `Route::redirect`; all six browser-verified with REAL submissions (rows persist, KPIs move, success panes,
    console clean). **2 real bugs fixed:** RecurringChargeController@store 500'd on every create (`starts_at`
    NOT NULL, never set — retired full-page form had the same latent bug); FinanceDemoSeeder GL accounts had
    no `sub_type` so the bank modal's GL picker was empty/unsubmittable on fresh seed. ModalSweepRedirectsTest
    (redirects + renders + starts_at persistence). ⚠️ named redirects only — unnamed ones inside the
    `->name('finance.')` group collide on `route:cache`.
  - **[~] C2b (agent running):** quote C/E · credit-note C · fixed-asset C/E + disposal · donor-fund C +
    receipt/expenditure transaction modals (post trust journals → PostingPreview) · funding-stream inline→modal.
  - **[x] C2c (confirm sweep):** shared `components/finance/confirm-dialog.tsx` (`ConfirmDialog` wrapping
    shadcn AlertDialog; `variant` destructive|default, `processing` guard) replaces **all 16 native `confirm()`
    sites** — audit-exports, bank-feeds, bills/Show, CashFlowForecast (Index+Show), Consolidation/Show,
    cost-centres, currencies, funding-streams, fx-reval post, gst-returns mark-filed, invoices send + mark-paid,
    IrdFilings submit, match-rules, PO approve + convert, payment-run approve + process. `grep confirm(` under
    pages/finance = **0**. **PLUS a guarded period-close impact modal** (fiscal-periods/Index — closing a period
    previously fired on a single click with NO confirmation; now a destructive ConfirmDialog spelling out that no
    further journals can post to a closed period). ⚠️ Fixed 3 defects in the sub-agent's partial work: it hit its
    session limit having (a) DELETED both payment-run confirmations without replacing them (approve/process fired
    unguarded — a regression), (b) left invoices/Show with the send + mark-paid triggers but NO dialog renders
    (dead buttons), (c) left 3 confirm() stragglers (PO ×2, IRD ×1). All fixed + re-verified: every touched file
    renders its dialog and every trigger opens it. types/eslint/build green.
  - **[ ] C2c leftover — GST Prepare page → wizard.** `gst-returns/Prepare.tsx` is still a full page (frequency
    + period selection → creates the return). It WORKS (no dead button) — this is purely an idiom upgrade to a
    WizardShell dialog on the gst-returns index, deferred as a small follow-up (lower priority than banking the
    verified conversion + confirm sweep).
  *Acceptance: zero `<Create|Edit>.tsx` full-page finance flows still linked (except GST-Prepare, tracked above);
  zero native confirm() ✓; every new modal browser-smoked.*
- **[~] C3 — Consistency sweep** (mechanical, per-hub ticks). Split into sub-batches:
  - **[x] C3a — format/token sweep (branch finance/c3-consistency, 4 parallel agents, verified).** 68 finance
    pages migrated off inline `Intl.NumberFormat`/local `formatCurrency`/`formatNZD`/fleet-utils → canonical
    `formatMoney`/`formatMoneyCompact` (components/finance/money.tsx). 16 files' hardcoded hex `CHART_COLORS`
    arrays → `chart-palette.ts` (`chartColor(i)` / `CHART_PALETTE`); semantic red/green recharts fills kept as
    `var(--status-success|critical)` (meaning preserved, still no literal hex). The last `ui/tabs` straggler
    (donor-funds/Show.tsx) → canonical `FinanceTabs` (controlled state, same two sections). Module-wide greps:
    `Intl.NumberFormat`=0, `#rrggbb`=0, `@/components/ui/tabs`=0, fleet-utils=0. Gates: types 0, eslint clean on
    all 68, build clean, Finance suite green (frontend-only). Browser-verified: report themed charts, index
    money, donor-funds tabs. (One kept wrapper: Consolidation `formatCurrencyStr(v,currency)` delegates to
    `formatMoney` for multi-currency — on-contract.) ⚠️ AGENT VERIFY LESSON: grep the module globally after
    parallel agents (a half-migrated sibling file showed as a transient tsc error mid-run — cleared when its
    owning agent finished; don't gate until ALL agents land).
  - **[x] C3b — StatusBadge sweep — MERGED to main `b221252b` 2026-07-08.** Extended `resources/js/lib/
    status-colors.ts` with the finance status vocabulary (sent/paid/partially_paid/awaiting_approval/processing/
    failed/validated/submitted/accepted/received/reconciled/posted/filed/amended/reversed/discrepancy/generating/
    eliminated/partial/final/fully_spent/…) — ONE shared map, so `<StatusBadge status={x}/>` renders the right
    severity everywhere. 35 pages migrated off local statusConfig/statusColors/STATUS_VARIANTS maps + local
    StatusBadge helper fns (3 parallel per-hub agents + Integrations/bank-feeds finished by hand). Module-wide
    grep: hand-rolled status colour maps = 0. Categorical TYPE maps (account/txn type, category, frequency,
    filing type) correctly LEFT. Intentional standardisation: cancelled→neutral, PO sent→info, some completed→
    info (single source of truth). Per-status icons StatusBadge has no slot for were dropped. Gates: types 0,
    eslint clean, build clean, suite 186 green. Browser-verified: invoices Paid=green/Sent=info, bills
    Approved=green — right colours, no console errors. *(EmptyState/skeleton adoption folded into C3d/C3e — the
    empty-list states are lower-risk and pair naturally with the command-layer list work.)*
  - **[ ] C3d — command layer** (search + filter chips synced to URL + sort + CSV export + pagination on every
    list tab; right-click context menus (shift-context-menu mould); **tab count badges** wired from controllers).
    Shipping per-concern sub-batches:
    - **[x] C3d-export — CSV export on all 15 lists** (merged `e7ea0a9e`). Shared `streamSanitizedCsv` helper on
      `SanitizesCsvOutput` (base Controller) → BOM + `putCsv` per row (formula-injection safe); an `export(Request)`
      on every finance list controller mirroring its index filters; 14 `finance.<x>.export` routes registered
      **before** resource routes (so `/x/export` isn't captured as `{param}`); Export-CSV `<a href>` in each hero.
      41 tests (ListExport{,Payables,Ledger,TaxBank}Test), full finance suite 227 green. ⚠️ helper is
      `streamSanitizedCsv` not `streamCsv`/`exportCsv` (both taken as `private` elsewhere → visibility fatal);
      ⚠️ Vendor export omits the encrypted bank_account_number by design.
    - **[x] C3d-badges — tab count badges.** `FinanceHubCountsService` returns `[hub][tab => count]` for the
      list tabs (report / dashboard / workspace tabs carry none); shared once per finance request as a lazy,
      finance-route-scoped `financeHubCounts` Inertia prop (non-finance pages + partial reloads pay nothing);
      each of the 5 list-hub `*TabsFooter` reads its slice and badges tabs via `tabCountBadge` (omits 0, caps
      `999+`). Every count org-scoped + individually guarded (bad table → no badge, never a 500 in middleware).
      Browser-verified on demo data: receivables (Invoices 6 · Quotes 1 · Recurring 1 · Price books 1; Billing/
      Allocations 0 → no badge; Aged-AR/Statements reports → no badge) + payables (Bills 4 · POs 2 · Vendors 4 ·
      Credit notes 2 · Payment runs 2), no console errors. Gates: types 0, eslint 0, vitest 3, suite 230 green.
    - **[x] C3d-list — command layer on the laggard lists.** Replicated the invoices/bills golden template (hand-rolled
      inline filter Card + inline paginator `.links`, no shared FilterBar/LaravelPagination so the pages stay visually
      identical to invoices/bills; broad EmptyState/skeleton adoption stays C3e's module-wide job). **Donor-funds**:
      now `->paginate(20)->withQueryString()->through(…)` + search / status / restricted filters (shared
      `applyFundFilters` so index + CSV export show the same rows) + filter bar + paginator + filter-aware empty.
      **Credit-notes**: added search (CN # / vendor / client) + credit-date range on top of its type/status
      (shared `applyCreditNoteFilters`). **Accounts** (a *tree*): client-side search + active filter over the loaded
      chart (you never paginate a chart of accounts) — prunes to matches keeping ancestors. **GST returns**: already
      had status/year + pagination → left as-is. No clickable column sort (the golden template has none — adding it to
      only these would break consistency; defer a uniform sort). Gates: types 0, eslint 0, suite 232, browser-verified
      (donor-funds `?search=` round-trips + hydrates; accounts tree 8→1 on "bank"; credit-notes bar renders), no console errors.
    - **[x] C3d-menus — right-click context menus** on list rows. Reused HR's generic `useRowContextMenu()`
      (portal, cursor-positioned, token-styled, keyboard-nav; `RowCtxItem[]` API) — re-exported via the finance
      barrel alongside the existing StatusBadge HR re-export (no fork, zero HR files touched). Each row's menu
      MIRRORS that page's existing inline actions (Open first), same guards + same handlers — never an invented
      route or a bypassed confirm (payment-run Approve/Process stay on the Show page behind ConfirmDialog, so the
      Index menu is Open-only). **Batch 1 MERGED — the 7 AP/AR transactional lists**: invoices (Open/Edit-draft/
      Record-receipt), bills (Open/Edit-draft), purchase-orders (Open), quotes (Open/Edit-draft), vendors (Open),
      credit-notes (Open), payment-runs (Open). Gates: types 0, eslint 0, build clean, suite 232 (no PHP touched);
      browser-verified the menu opens per-row with the right guarded items (invoices Paid→Open, Sent→Open+Record
      receipt), no console errors. **Follow-up batch MERGED — 12 ledger/banking/tax lists** (4 parallel agents,
      every diff verified additive + grep + types): journals/accounts-tree/gst-returns/ird-filings/bank-accounts/
      petty-cash → Open; fixed-assets → Open+Edit; bank-accounts → Open+Edit; audit-exports → Download+Delete
      (via ConfirmDialog); cost-centres → Delete; currencies → Delete (non-base); fiscal-periods → Close (open);
      fx-revaluations → Post-to-GL (draft) — all through the page's own setState/confirm handler with the same
      guard, `onContextMenu` attached only when a row has an applicable action (no empty menus). accounts-tree
      threads `onRowContextMenu` down the recursive AccountRow (element stays at page level). **bank-transactions
      N/A** — plain rows with no navigation and no inline actions (menu would be empty), correctly skipped. Gates:
      types 0, eslint 0, build clean, suite 232 (no PHP); browser-verified fixed-assets (Open+Edit / Open by status)
      + audit-exports (Download+Delete), no console errors. **C3d-menus is now DONE across every finance list that
      has a navigable row or an inline action.**
  - **[x] C3e — empty states + axe + responsive + Intercompany fold. COMPLETE.** Per-concern sub-batches:
    - **[x] C3e-1 — EmptyState/EmptySearch adoption** on 16 finance list pages (invoices golden + 4 agents): each
      empty branch now renders the shared `@/components/ui/empty-state` — `EmptySearch` (filters active → "No X match
      your filters" + Clear, via the page's `clearFilters`; several pages that lacked one got a minimal `clearFilters`
      added) vs `EmptyList` (empty → "No X yet" + a create CTA wired to the page's own existing trigger). In-table
      empties keep the `TableRow/TableCell colSpan` wrapper (cell `p-0`, EmptyState `border-0`); Card/div empties swap
      inner content. Pages: invoices/bills/purchase-orders/vendors/quotes/credit-notes/donor-funds/journals/fixed-assets/
      bank-accounts/bank-transactions/petty-cash/gst-returns/ird-filings/audit-exports/payment-runs. Gates: types 0
      (validated every create-trigger/clearFilters ref), eslint 0, build clean, suite 232 (no PHP); browser-verified
      invoices EmptySearch renders ("No invoices match your filters" + Clear), no console errors. ⚠️ DEFERRED (low-value,
      rarely-empty pure-config lists): cost-centres / fiscal-periods / currencies / fx-revaluations keep hand-rolled empties.
    - **[x] C3e-2 — skeletons: N/A (code-confirmed).** No finance controller uses `Inertia::defer` and no finance
      list does a client-side fetch — every list loads synchronously, so a loading skeleton would never render.
      Deliberately shipped none (no stub skeletons). Revisit only if a future finance surface adds a deferred prop.
    - **[x] C3e-3 — axe no-criticals.** Ran real axe-core (served temporarily from public/, deleted after) on the
      hubs + list pages. Finance was nearly clean; the ONE critical was `button-name` on the shadcn filter
      `<SelectTrigger>` comboboxes (visible placeholder, no accessible name) → added a descriptive `aria-label` to
      all 26 filter-toolbar Selects across 16 list pages (modal/form Selects left alone — labelled via their own
      Label). Browser-verified 0 `button-name` (and 0 criticals) on invoices + bills. NOTE: `color-contrast`
      (serious, NOT critical) remains on the shared status-badge tokens (status-info-bg/text) + sidebar chrome —
      app-wide design-token/chrome concern, out of finance scope, not fixed here.
    - **[x] C3e-4 — responsive: finance is clean.** Finance list tables ride the shadcn `<Table>` wrapper
      (`relative w-full overflow-x-auto`), so wide tables scroll in their own container with ZERO body overflow at
      tablet (verified invoices 775px table @ 753px viewport, no horizontal body scroll). Only /finance overview has
      a minor ~34px overflow from the SHARED PageHero decoration circle (`-right-16`, all modules) + a recharts SVG —
      not finance-authored, minor, desktop-first app → left as acceptable. No finance code changes needed.
    - **[x] C3e-5 — Intercompany already nested under Consolidation (verified from code — the prior "orphan" claim
      was stale).** `intercompany.index` is `/finance/intercompany/{group}` — a PER-GROUP page, not a top-level orphan;
      there is no standalone `/finance/intercompany` route to redirect. It's reached from the Consolidation group Show
      page (an "Intercompany" button, Consolidation/Show.tsx:315) and its breadcrumb is already
      `Finance › Consolidation › {group} › Intercompany` (Intercompany/Index.tsx:197-201). It can't be a TAX_TABS tab
      because it needs a group context. So no restructure/redirect was needed. Only polish applied: added a hero
      `backHref` to the parent group (matching the Consolidation Show hero) for consistent back-nav. types 0, eslint 0,
      build clean. ⚠️ browser-verify of the Intercompany page is blocked by demo data (no consolidation group seeded);
      change is type-safe + code-verified (same href as the breadcrumb).
  - **✅ C3 COMPLETE** — one visual language across the finance module: PageHero+FinanceTabs+count badges, WizardShell
    modals + shared ConfirmDialog, formatMoney/StatusBadge everywhere, the full command layer (export · badges ·
    search/filter/pagination · right-click menus), shared EmptyState/EmptySearch, axe-critical-clean, responsive,
    design-tokens-only. Next milestone: **C4 funding & client-money hub (PAUSE-AND-ASK on the canonical store).**
- **[~] C4 — Funding & Client Money hub** (`/finance/funding`; tabs Funding streams · Funding claims ·
  Client/resident funds · Donor/trust funds · Service billing).
  **✅ CANONICAL-STORE DECISION MADE (Chane, this session): ClientFund/ClientFundTransaction is canonical.**
  ⚠️ The PRIOR recommendation in this entry was BACKWARDS — re-derived from code (untrust-the-audit paid off):
  - **ClientFund/ClientFundTransaction = the working, tested, GL-posting trust store.** `ClientFundController@addTransaction`
    (app/Http/Controllers/Operations/ClientFundController.php:156) → `ClientFundTransactionObserver@created` →
    `PostClientFundJournalJob` → `ClientFundJournalService::postClientFundJournal` posts a BALANCED journal to segregated
    trust accounts (deposit DR 1010 Bank-Trust / CR 2500 Client Trust Funds; withdrawal reversed), idempotent on
    `journal_id`. `ClientFundJournalDispatchTest` genuinely asserts the 1010/2500 lines + one-journal-per-txn (NOT a stub).
  - **ClientLedgerEntry = DORMANT.** Modelled + observed (would GL-post via ProcessFinancialEventJob) + READ by
    `ClientLedgerService` + `ClientController.php:806` — but a full `app/` grep finds ZERO `ClientLedgerEntry::create`/
    `->create`/`new`. Nothing writes it → the client-profile "ledger entries" it feeds is always empty; the M6-2
    segregation fix guards an empty store.
  - **Both tables EMPTY in dev DB (0/0/0)** → NO data migration; purely architectural + low-risk.
  **IMPLEMENTATION (per Chane = ClientFund canonical), staged sub-batches:**
  (C4-A) build /finance/funding hub SHELL — finance PageHero + FinanceTabs footer (5 tabs) + a collect-first
  FundingController@index redirect; tabs point at existing surfaces (finance funding-streams + donor-funds already
  in finance; operations/funding-claims + operations/client-funds migrated into finance behind /finance/funding with
  NAMED Route::redirects; finance/billing = service billing).
  **(C4-B-1 DONE — merged this session):** repointed `ClientLedgerService` (behind the finance client-financials tab,
  insights API, and summary service) from the DORMANT empty `ClientLedgerEntry` → the canonical `ClientFundTransaction`
  (deposits/withdrawals via `fund.client_id`), keeping the exact getLedger/summary return shape so all 3 consumers now
  show REAL trust-fund activity + a correct personal running balance (segregation preserved — operational FinCostAllocation
  costs shown, never move the personal balance). ClientMoneySegregationTest updated to seed ClientFundTransaction; finance
  suite 232. ClientLedgerEntry model+observer+table left intact (reserved).
  **(C4-B-2 DONE — merged this session):** removed the vestigial empty `ledger_entries` (ClientLedgerEntry) read from the
  client PROFILE (ClientController.php + the operations/clients/tabs/finance.tsx "Client ledger" Card) — the profile already
  shows ClientFund funds + recent_transactions. Pure removal (−81 lines); types/eslint/build clean; browser-verified the
  finance tab renders Funds/Recent-fund-transactions/Purchase-requests/Discrepancies with the empty Client-ledger section
  gone + no console errors. ClientLedgerEntry model/observer/table left intact (reserved). **✅ C4-B COMPLETE — client money
  is fully repointed at the canonical ClientFund store.** ⚠️ C4-B touched the CLIENTS module (agreed seam — Chane approved).
  Then: Client-Money Transaction modal (deposit/withdrawal via the working ClientFund trust path, receipt upload, audited)
  + funder remittance reconciliation. Client money never nets against operational accounts (segregation preserved:
  trust liability 2500, not operational revenue).
- **[~] C5 — Payroll residuals — RE-DERIVED FROM CODE (audit was optimistic; the FINANCE GL bridge is already SOUND).**
  `PayrollJournalService::postPayrollJournal` (app/Domain/Finance/Services/PayrollJournalService.php) posts a BALANCED NZ
  journal (DR 5000 Wages / 5010 KiwiSaver-er / 5020 ACC-levy; CR 2100 PAYE / 2110 ACC-payable / 2120 KiwiSaver-payable /
  2130 Student-loan / 2300 Accrued-wages), idempotent (journal_id + findExistingPayrollJournal), resolves each canonical
  code via findAccountByCode + THROWS if missing (never invents a code / never posts unbalanced); `postNetPayPayment`
  (DR 2300 / CR bank) + `buildNetPayDirectCreditCsv` work. Verdict per claim:
  - **(a) GL-post FAILURE surfacing = REAL but CROSS-MODULE.** PostPayrollJournalJob ($tries=1) catches→logs→re-throws →
    silent failed_jobs; the run (HrPayrollRun) has `gl_posted_at` but NO gl_status/gl_error, so a failed post looks like
    "not posted yet". Fixing = an hr_payroll_runs column + the HR-owned payroll-run UI + a retry endpoint (dispatched from
    HR's PayrollExportController:164) → **HR-SEAM item, not finance-in-lane.**
  - **(b) config-drive codes = LOW value** — hardcoded-canonical-codes + throw-if-missing is the CORRECT safe pattern
    (same as ClientFundJournalService 1010/2500), not a bug. Skip.
  - **(c) preflight** — the service already throws on missing accounts + (via JournalPostingService.post) closed period;
    surfacing it before dispatch is part of (a)'s HR-side UI work.
  - **(d) reconcile** run.total_gross vs Σpayslip gross — a real integrity guard, but also lands on the HR run.
  - **(e) ESCT** = HR-OWNED (hr_payslips + NzPayrollCalculatorService compute it).
  - **(f) forecast double-count = MOOT** — CashFlowForecastService has ZERO payroll references (no payroll outflow in the
    forecast at all), so nothing to double-count. (Payroll-in-forecast would be a NEW feature, not a bug fix.)
  **⇒ C5's finance-side is essentially done (GL bridge sound). Remaining residuals (a/c/d/e) are HR-SEAM/HR-owned — do
  them WITH HR (hr_payroll_runs column + run UI + calculator), not as a finance fork.** (3) payday-filing action = M5-5
  (route exists), can ship finance-side later.
- **[~] C6 — Budgets + approvals. ✅ CHANE DECIDED: "Keep both + non-destructive cleanup"** (2nd/last reserved decision).
  RE-DERIVED FROM CODE — the "duplicate budget backend" was a MISDIAGNOSIS (untrust-the-audit again):
  - Finance `SiteBudgetLine` = per-SITE, per-MONTH OPERATIONAL budget (managers plan by category; actuals live from
    fin_cost_allocations); used by BudgetForecastApiController + BudgetVarianceService + FinancialForecast/InsightsService.
  - Governance `Budget`/`BudgetLineItem` = board-approved ANNUAL ORG budget; its budget-vs-actuals report reads actuals
    LIVE from the GL (BudgetActualsService.php:91, bypassing the denormalised actual_amount cache → always accurate).
  - Roadmap `InitiativeBudget` = roadmap initiatives (separate domain, not competing).
  → NOT duplicate stores — DIFFERENT budgeting levels, both GL-backed. All 4 tables EMPTY (0 rows) → purely architectural,
  NO migration. `BudgetSyncInterface` = ORPHANED (no impl/binding). Genuine DOUBLE-SYNC: SyncBudgetActualsJob (console.php
  hourly) + `governance:update-budget-variances` (hourlyAt(10)) BOTH called BudgetActualsService::syncActuals.
  **DECISION = keep both levels; non-destructive cleanup:**
  - **[x] C6-1 double-sync fixed** — removed the redundant `governance:update-budget-variances` hourly schedule
    (SyncBudgetActualsJob already runs syncActuals + variance alerts hourly; the command stays for manual use).
    schedule:list confirms one budget sync; php -l clean.
  - **[ ] C6-2 retire the orphaned BudgetSyncInterface** — both budgets read live GL directly, so the
    governance-pulls-from-finance contract is unnecessary dead code (grep consumers first; remove the interface +
    any references; do NOT implement FinanceBudgetSync — the audit's "implement it" was wrong).
  - **[ ] C6-3 SpendApproval threshold** — FinBill→spendApproval relation on the existing `fin_bills.spend_approval_id`
    + a link picker on bill create/edit + config-gated threshold enforcement (bill over category threshold requires a
    linked approval); approve() NEVER creates bills.
- **[ ] C7 — Capture-at-source** (in-lane embedded modals posting through canonical paths, no new ledgers):
  Sites damage/repair → FinBill (+optional insurance AR); Catering shopping-complete → HouseLedger groceries;
  Respite booking-confirmed → AR invoice vs funder + funding drawdown; Asset/Fleet purchase → FinFixedAsset
  capitalisation journal; `SiteVendor.fin_vendor_id` FK + vendor attribution.
- **[ ] C8 — Final parity pass.** Whole module vs Rostering side-by-side in browser; FinanceDemoSeeder v2
  (marker-based guard + funding/client-money/payroll rows); route:list clean; ledger 100% green; axe clean;
  plan-doc DoD re-ticked with screenshots; memory updated.

### Deploy runbook deltas (C-series)
- C4 introduces funding-hub permissions → extend `FinancePermissionsSeeder` + `db:seed --force` on deploy.
- C5 adds `hr_payslips.esct` + `fin_payment_runs.type` + GL-mapping table migrations (additive, reversible).
- C8 reseeds `FinanceDemoSeeder` (new guard marker).

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
