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
  - **[ ] New Account modal**, **[ ] FX-revaluation modal**, **[ ] Period-close modal** — next.
- **[x] M1-3 Fix `5020` leave-expense collision.** Added dedicated `5050 Leave Expense` (FinanceSeeder,
  idempotent) and repointed `config/finance.php` `event_accounts.leave_provision.debit` 5020→5050; `5020`
  stays ACC Employer Levy only. Test asserts a leave_provision event debits 5050/Leave Expense, balanced +
  idempotent. *(commit db73bcdd)*
- **[x] M1-4 `finance:verify-chart` name parity.** Added config-driven `config('finance.account_names')`
  (code→intended-name keyword, single source of truth) + a name-parity gate in `VerifyFinanceChart`; fails
  when a code is seeded under a contradictory name (would have caught 5020). Test covers the failure case. *(commit db73bcdd)*

### M2 — Sales & Receivables hub + AR data + recurring billing `[ ]` (contains P0s)
- **[ ] M2-1 Receivables hub + TabStrip + wizard modals.** Fold invoices/quotes/credit-notes(AR)/recurring/
  billing/price-books/allocations/receivables into `/finance/receivables`; New/Edit Invoice, New Quote,
  Credit Note, Record Receipt, Allocate Payment as `WizardShell` modals (line-item invoice = the multi-line wizard).
- **[ ] M2-2 Kill AR data-blindness (P0).** *Problem:* receivables index, aged-AR report, statements read the
  orphaned legacy `Invoice`. *Evidence:* `AccountsReceivableService.php:29,117,196`, `FinancialReportService.php:441`,
  `Client.php:388`. *Fix:* migrate these reads to `FinInvoice` (+ `FinPaymentAllocation` tagged `FinInvoice`);
  net partial payments. *Acceptance:* receivables/aged-AR/statements show real invoices; partial payments reduce balance.
- **[ ] M2-3 `markPaid` posts a receipt journal (P0).** *Problem:* `InvoiceController::markPaid:436` sets
  status only → AR overstated. *Fix:* post DR Bank/CR 1100 AR + write `FinPaymentAllocation` (reuse
  `PaymentMatchingService::postInvoiceReceiptJournal`), idempotent. *Acceptance:* marking paid clears AR in GL; test asserts balanced receipt.
- **[ ] M2-4 Quote→Invoice conversion.** *Problem:* `quotes.convert`→ServiceAgreement only. *Fix:* add accepted-quote→`FinInvoice`. *Acceptance:* accepted quote converts to a draft invoice with lines.
- **[ ] M2-5 AR credit-note GST reversal.** *Problem:* `approveCreditNote` AR branch omits 2200. *Fix:* reverse GST proportionally. *Acceptance:* AR credit note reverses revenue + GST; balanced.
- **[ ] M2-6 Recurring charges engine.** *Problem:* `RecurringChargeService` queries non-existent columns;
  `ProcessRecurringChargesJob` unscheduled. *Evidence:* `RecurringChargeService.php:13-41`. *Fix:* correct
  columns (`is_active`/`next_charge_at`), schedule the job (daily), generate `BillingEntry`→`FinInvoice`. *Acceptance:* a due recurring charge auto-generates an invoice on schedule; test.
- **[ ] M2-7 Retire dead AR code.** Delete `BillingJournalService` + `PostBillingJournalJob` (orphaned, only ever worked on legacy model). *Acceptance:* `route:list`/build clean; no callers.

### M3 — Purchases & Payables hub `[ ]`
- **[ ] M3-1 Payables hub + TabStrip + wizard modals.** Fold bills/POs/vendors/credit-notes(AP)/payment-runs;
  New/Edit Bill, New PO, New Vendor, Schedule Payment Run, Approve Payment Run as modals.
- **[ ] M3-2 Bills `partial` status bug.** *Fix:* `BillController:68-70` filter `'partially_paid'` not `'partial'`. *Acceptance:* summary counts partially-paid bills.
- **[ ] M3-3 PO/bill-number dedup.** Single source for `generateBillNumber` (service, not duplicated in controller). *Acceptance:* one generator.

### M4 — Banking & Cash hub `[ ]`
- **[ ] M4-1 Banking hub + TabStrip + Bank-Reconcile workspace + confirm modal.** Fold accounts/transactions/
  reconciliation/matching/feeds/eftpos/petty-cash/match-rules.
- **[ ] M4-2 Activate match-rule engine.** *Problem:* `rule_type`/`conditions`/`priority` ignored;
  hardcoded score. *Evidence:* `PaymentMatchingService.php:51,162-176`. *Fix:* evaluate rules in scoring,
  increment `match_count`. *Acceptance:* a rule changes which txns auto-confirm; test.
- **[ ] M4-3 Bank feeds: honest state.** *Problem:* providers throw; no token exchange. *Fix:* implement
  OAuth token exchange for at least one provider **or** hide bank-feed UI behind a feature flag and document
  CSV import as supported (house rule: no stub UI). *Acceptance:* no dead "sync" buttons; CSV path clearly primary.
- **[ ] M4-4 Petty cash top-up/adjustment booking.** *Fix:* book the funding-side journal on top-up. *Acceptance:* top-up posts; balanced.

### M5 — Payroll end-to-end → Finance bridge `[ ]` `[headline — coordinate with HR]`
**Read HR's M5 status first (`git fetch`, `hr/*`, plan doc).** Bridge is ~95% wired + tested.
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

### M6 — Funding & Client Money hub + duplicate-backend reconciliation `[ ]` (contains P0)
- **[ ] M6-1 Funding & Client Money hub + TabStrip.** Tabs: Funding streams · Funding claims · Client/resident
  funds · Donor/trust funds · Service billing. Migrate `operations/funding/**` + `operations/client-funds/**`
  UI into the hub; redirect old operations routes.
- **[ ] M6-2 Reconcile client-money backend (P0).** *Problem:* legacy `ClientFund` (populated) vs empty
  `ClientLedgerEntry` (richer) + `ClientLedgerService` netting flaw + two divergent profile tabs.
  *Evidence:* gap-analysis §C. *Fix:* keep legacy `ClientFund` canonical; fix `ClientLedgerService` to
  **segregate** personal vs operational running balances (`:163-225`); point both profile finance tabs +
  family portal at the canonical backend; feature-flag/retire empty `ClientLedgerEntry`. *Acceptance:* one
  client-money backend; resident personal balance never includes operational cost allocations; family portal matches.
- **[ ] M6-3 Client-Money Transaction modal.** Embed a permission-gated, audited "Record client transaction"
  modal (deposit/withdrawal/purchase/reimbursement) on the client finance tab → posts to the canonical
  trust-account path. *Acceptance:* transaction recorded from a modal; trust journal posts; audited.
- **[ ] M6-4 Funder remittance reconciliation.** Add approved-vs-claimed-vs-received tracking + match a funder
  payment to claims. *Acceptance:* a funder remittance reconciles against claims.
- **[ ] M6-5 Delete stale `FundingService`.** Verify dead (controller bypasses it; writes non-existent columns), then remove. *Acceptance:* no callers; build clean.

### M7 — Tax & Compliance hub `[ ]`
- **[ ] M7-1 Tax hub + TabStrip + modals.** GST returns · IRD/payday filing · Audit exports · Consolidation ·
  Intercompany. Prepare-GST, File-Payday/IRD, FX-reval, Period-close as modals.
- **[ ] M7-2 PAYE/IR348 payday filing (links M5-5).** Surface payday filing from posted pay runs. *Acceptance:* payday-filing artefact assembles from a run.
- **[ ] M7-3 IRD GST e-filing honesty.** Either wire the real IRD Gateway submission or clearly label the
  current credential-gated simulation (no dead "submit" implying live filing). *Acceptance:* submit state is truthful.

### M8 — Reports & Planning hub + budget unification `[ ]`
- **[ ] M8-1 Reports hub + TabStrip + period selector.** P&L · BS · TB · Cash flow · Aged AR · Aged AP ·
  Funding summary · Budget vs actuals · Cash-flow forecast. (All read real GL already — just re-home + theme.)
- **[ ] M8-2 Unify budgets to one engine.** *Problem:* three stores + double sync + orphaned interface.
  *Evidence:* gap-analysis §E. *Fix:* Finance `SiteBudgetLine`+`FinCostAllocation` canonical; implement
  `BudgetSyncInterface` (`FinanceBudgetSync`) + bind; Governance consumes via it; retire denormalised
  `actual_amount` + manual `recordActuals`; collapse double hourly sync to one writer; unify category
  vocabularies. *Acceptance:* one budget source of truth; Governance reads via interface; one scheduled writer.
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
