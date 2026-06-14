# Finance Module — Gap Analysis (vs Xero/MYOB/QuickBooks + Supported-Living needs)

**Created:** 2026-06-14 · **Author:** Claude (Opus 4.8, autonomous /loop)
**Audit basis:** 9 parallel adversarial code sweeps over `resources/js/pages/finance/**` (~105 pages),
`app/Domain/Finance/Http/Controllers/**`, `app/Domain/Finance/Services/**` (~50), `app/Domain/Finance/Models/**`
(~60 `Fin*`), `app/Domain/Finance/Jobs/**` (24), `routes/finance.php` (689 lines), `routes/console.php`,
the cross-module finance code (Sites / Clients / Operations funding / Governance budgets / Fleet / Catering /
Respite / Meds), and the HR payroll bridge. **Every claim re-derived from current code.**

> ## ⚠️ Headline correction — the prior audit is STALE
> `FINANCE_READINESS_AUDIT.md` is dated **2026-05-01** and was largely **superseded by commit `616b93bc`
> ("feat(finance): complete production-readiness plan")**. Its biggest claims are no longer true:
> | Prior-audit claim | Current reality (verified) |
> |---|---|
> | **P0-1** Payroll never reaches GL; `PostPayrollJournalJob` has zero callers | **FALSE** — `PayrollExportController::lockRun:160` dispatches it; posts a balanced journal; tested (`PayrollJournalPostingTest`) |
> | **P0-2** Every event mis-posts/crashes on chart drift | **MOSTLY FIXED** — all config codes now seeded; one residual collision (`5020`) |
> | **P0-3** FinInvoice has no GL posting at all | **MOSTLY FIXED** — `FinInvoiceJournalService` posts on `send`, reverses on `cancel`; gap is `markPaid` receipt + reading the orphaned legacy AR table |
> | **P0-4** Funding-claim/client-fund journals never dispatched | **FALSE** — observers registered (`AppServiceProvider:213-214`); posts today; tested |
> | **P0-5** Bank-rec / payment-matching / EFTPOS settle nothing | **2 of 3 FIXED** — payment-matching + EFTPOS now post journals; bank-rec is tick-and-tie (correct) |
> | **P0-6** Operational scheduled jobs not registered | **MOSTLY FIXED** — recurring/operational jobs scheduled; only 4 orphaned jobs remain (dead code) |
> | **P0-7** Zero end-to-end finance tests | **FALSE** — `tests/Feature/Finance/` has ~20 real GL-posting tests |
> | **P1-3** Variance alerts computed but never delivered | **FALSE** — `SyncBudgetActualsJob` (hourly) dispatches `BudgetVarianceAlertNotification`; delivery-tested |
> | **P1-7** Audit exports unencrypted/no retention | **FIXED** — `Crypt::encryptString` + 7-yr scheduled prune |
> | **P1-2** Xero/MYOB are stubs | **PARTLY STALE** — Xero is a real HTTP client; MYOB is an explicit stub; mapping UI saves but is unused |
> | **P2-3/P2-5** Consolidation/intercompany/FX are CRUD-only | **STALE** — all three are functional engines posting real journals |
>
> **Conclusion:** the GL-posting *core* is sound, balanced, idempotent, and tested. The real work is
> (1) **design collapse to 8 hubs at Rostering parity** (the dominant gap — 0% adoption today) and
> (2) a **bounded set of genuine functional gaps** catalogued below. This document supersedes the
> 2026-05-01 audit for prioritisation.

---

## A. Architecture ground truth (verified, trustworthy)

- **GL posting hub:** `FinancialEventService::record()` (`app/Domain/Finance/Services/FinancialEventService.php:67`)
  → idempotency-keyed `FinFinancialEvent` (`:85`, dedupe on `posted|pending` `:93`) → balanced 2-line journal
  via `JournalPostingService::createAndPost` → `FinCostAllocation` (site/client/staff/asset/shift dims, `:152`)
  → back-links `journal_id`. Failure path **rethrows** (`:171`), not swallowed. Async via
  `ProcessFinancialEventJob` (`finance` queue, 3 tries).
- **`JournalPostingService::post`** (`:64`) is rigorous: draft-only, ≥2 lines, **debits==credits to 2dp**
  (`bccomp`), accounts active+same-org, **open fiscal period required**, cost-centre/funding-stream FK
  validation, atomic, fires `JournalPosted` → `AllocatePayrollCosts` + `LogJournalPosted`.
- **8 observers** (registered `AppServiceProvider:205-214`) feed the GL on model events: ClientLedgerEntry,
  HouseLedgerEntry, TimesheetMileage, HrExpenseClaim (→approved), HrCourseEnrollment (→completed),
  AssetMaintenanceLog, FleetFuelLog, FleetWorkOrder (→completed), plus ClientFundTransaction +
  FundingClaim (→ legacy `Post*JournalJob`).
- **Money is `decimal(14,2)`** across `fin_journal_lines.debit/credit`, `fin_journals.total_amount`,
  `fin_invoices.*`, `fin_bills.*`, `fin_accounts.opening_balance`. **Not** integer minor units. All posting
  code must use bcmath/decimal string math (it does — `bccomp(...,2)`).
- **Chart of accounts** lives at **org 0** (`FinanceSeeder` default), unique `(organization_id, code)`.
  `finance:verify-chart` artisan command exists (closes P1-8) but checks existence only, not name parity.
- **`AccountsReceivableService` / `FinancialReportService` aged-AR / statements read the LEGACY
  `App\Models\Invoice` table** which nothing writes to anymore → those surfaces silently render empty.
  This is the single highest-impact AR data defect (see §C-AR).

---

## B. Core accounting — feature parity vs Xero/MYOB/QuickBooks

Legend: ✅ done & wired · 🟡 half-built / data-blind / not-enforced · ❌ missing.

### General Ledger (Ledger hub) — **strong**
| Capability | State | Evidence |
|---|---|---|
| Chart of accounts CRUD | ✅ | `accounts/*`, `FinAccount`, seeded org-0 chart |
| Manual journals (post/reverse) | ✅ | `journals/*`, `JournalPostingService::post/reverse` |
| Recurring journals | 🟡 | `RecurringJournalService` + `GenerateRecurringJournalsJob` **scheduled** (daily 02:45) — works, but overlaps `PostSiteRentJob`/`PostSiteUtilitiesJob` (double-config risk) |
| Cost centres | ✅ | `cost-centres/*`, validated in posting |
| Fiscal periods + period close | ✅ | `fiscal-periods/*`, open-period gate enforced |
| Currencies + FX revaluation | ✅ | `CurrencyService`, `FxRevaluationService::postRevaluation` posts real adjustment journal |
| Chart drift: `5020` collision | 🟡 | `leave_provision` debit maps to `5020` = "ACC Employer Levy" (no dedicated Leave Expense acct) → silent mis-post |

### Accounts Receivable (Receivables hub) — **wired but data-blind in places**
| Capability | State | Evidence |
|---|---|---|
| Invoice draft→sent→paid | 🟡 | `InvoiceController` send→`PostFinInvoiceJournalJob` (DR 1100/CR rev/CR 2200 GST), cancel reverses. **`markPaid:436` posts NO receipt journal** → AR overstated unless paid via bank-match |
| Quotes | 🟡 | full CRUD + send/accept, **but `convert`→`ServiceAgreement`, not an invoice** — no quote→invoice |
| Credit notes | 🟡 | `approveCreditNote` posts reversing journal, **but AR branch omits GST (2200) reversal** |
| Recurring/subscription billing | ❌ | `ProcessRecurringChargesJob` **unscheduled**; `RecurringChargeService` queries **non-existent columns** (`active`/`next_charge_date` vs `is_active`/`next_charge_at`) → never fires |
| Customer statements | 🟡 | `generateStatement` complete **but reads orphaned legacy `Invoice`** → empty |
| Aged receivables | 🟡 | two impls, **both read legacy `Invoice`** → empty; `FinancialReportService:441` also ignores partial payments |
| Payment allocation / matching | 🟡 | `payment-matching` works (posts receipt, writes `FinPaymentAllocation` tagged `FinInvoice`); `payment-allocations` invoice path posts nothing; AR-service path tags **legacy `Invoice`** → the two never reconcile |
| EFTPOS / online receipt | ✅ | EFTPOS settlement posts DR Bank/CR 1180 |
| Price books | ✅ | full CRUD, consumed by Quotes |

### Accounts Payable (Payables hub) — **strong**
| Capability | State | Evidence |
|---|---|---|
| Bills approve→pay | ✅ | `AccountsPayableService::approveBill` DR exp/CR 2000; `recordPayment` updates paid (bank journal posted by run/match) |
| Purchase orders → bill | ✅ | `PurchaseOrderController::convertToBill` |
| Vendors | ✅ | `FinVendor` CRUD (no destroy), backs AP |
| Batch payment runs + approval | ✅ | `PaymentRunService::processPaymentRun` DR AP/CR Bank + NZ direct-credit CSV; 2-step approve→process |
| Aged payables | ✅ | real buckets from `total−paid` |
| Bills "partial" status bug | 🟡 | `BillController:68-70` filters `'partial'` but service writes `'partially_paid'` → summary undercounts |

### Banking & Cash (Banking hub) — **mostly wired; feeds + match-rules thin**
| Capability | State | Evidence |
|---|---|---|
| Bank accounts | ✅ | `FinBankAccount` with `gl_account_id` |
| Statement CSV import | ✅ | `BankTransactionController::import` |
| Bank feeds (ANZ/ASB/BNZ/Westpac) | ❌ | providers **throw** "not supported"; OAuth consent partial but **no token exchange** → zero real txns |
| Reconciliation | 🟡 | `completeReconciliation` flips status only (no journal) — tick-and-tie against existing journals (correct), but does not itself settle |
| Auto-match rules | 🟡 | `match-rules` CRUD full **but engine ignores `rule_type`/`conditions`/`priority`** — only reads max `auto_confirm_threshold`; score is hardcoded |
| Payment matching | ✅ | posts DR AP/CR Bank or DR Bank/CR AR, idempotent (`lockForUpdate` + `journal_id`) |
| EFTPOS settlement | ✅ | `reconcileBatch`→`postSettlementJournal` |
| Petty cash | 🟡 | expense posts to GL; **top-up/adjustment update balance only (unbooked)** |

### Fixed assets / multi-entity / FX — **functional (exceeds prior-audit claims)**
| Capability | State | Evidence |
|---|---|---|
| Fixed assets + depreciation | ✅ | `FixedAssetService` posts DR dep-exp/CR accum-dep; `RunDepreciationJob` scheduled monthly |
| Capitalise operational purchase → FinFixedAsset | ❌ | `Asset` model has no `purchase_cost`; no capitalisation path (cross-module gap) |
| Multi-currency + FX reval | ✅ | `FxRevaluationService` posts real journal |
| Consolidation + intercompany | ✅ | real TB aggregation, ownership %, IC elimination; IC posts two balanced journals |

### Reporting (Reports hub) — **all read real GL** (no stubs)
P&L, Balance Sheet, Trial Balance, Cash Flow, Aged AP, Funding-Stream Summary, Budget-vs-Actuals all query
posted `fin_journal_lines` (`FinancialReportService`, `BudgetActualsService:207`). **Aged AR is the exception**
(reads legacy `Invoice`). Cash-Flow Forecast (`CashFlowForecastService`) forecasts from real AR/AP/GST/recurring
due dates + Base/Best/Worst scenarios — **no payroll-due feed yet**.

### NZ tax & compliance (Tax hub)
| Capability | State | Evidence |
|---|---|---|
| GST return auto-prepare from ledger | ✅ | `GstReturnService::prepareReturn` from posted lines w/ `tax_rate_id`; `CalculateGstReturnJob` scheduled bi-monthly |
| GST e-filing (GST101A) | 🟡 | `IrdFilingService::submitFiling` is **credential-gated simulation** (real IRD Gateway POST is a TODO) |
| **PAYE / IR348 payday filing** | ❌ | `buildPaydayFilingPayload` exists but is **dead code** — no controller/route/UI/payroll feed |
| Audit exports (encrypted + retention) | ✅ | `Crypt::encryptString`, `.zip.enc`, 7-yr prune scheduled |

### Integrations (Settings hub)
| Capability | State | Evidence |
|---|---|---|
| Xero sync | ✅ | real HTTP client (OAuth refresh, push/pull accounts/journals/bills/contacts); `SyncAccountingIntegrationJob` scheduled hourly |
| MYOB sync | ❌ | explicit stub (`unsupported()` throws) — acceptable; hide behind a "not yet supported" banner |
| Account mapping UI | 🟡 | `Mapping.tsx` **saves** `account_mapping` JSON **but the Xero push never reads it** — matches by account `code` only → mapping is cosmetic |

---

## C. Supported-living-specific (the differentiators)

### Funding streams & claims — **wired, but a stale orchestration service lingers**
- ✅ `FundingClaim` (legacy `app/Models`) + `FundingClaimObserver` (→ submitted/approved) → `PostFundingClaimJournalJob`
  → `FundingClaimJournalService` posts DR funder-receivable / CR funding-revenue keyed off `funding_body`,
  with reversal. **Posts today; tested** (`FundingClaimJournalDispatchTest`).
- ✅ `FinFundingStream` (Finance domain) = funder catalogue / dimension (`default_revenue_account_id`).
- 🟡 `app/Services/Operations/FundingService.php` writes **stale column names** (`reference`/`amount`/
  `billing_entry_id`) that no longer exist; **bypassed by the controller** → dead/broken, verify-then-delete.
- ❌ **Funder remittance reconciliation** (approved vs claimed vs received, match a funder payment to claims) — not modelled.
- ❌ **Auto-generate claims from delivered/rostered service** — `BillingService` generates AR invoices from timesheets, but there is no auto funding-claim generation pipeline.

### Client / resident money — **THREE stacks; the real duplicate-backend problem**
| Stack | State | Evidence |
|---|---|---|
| **Legacy `ClientFund`/`ClientFundTransaction`** | ✅ **populated** | `ClientFundController::addTransaction` → observer → `ClientFundJournalService` posts to **trust accounts 1010/2500** (segregated — does NOT net against operational P&L). Idempotent. This is the only working, populated per-resident money store. |
| **Modern `ClientLedgerEntry`** | ❌ **empty** | richer design (approval workflow, soft-deletes, `posts_to_gl`, type taxonomy, `ClientLedgerEntryObserver`→`ProcessFinancialEventJob`) but **zero write paths anywhere** — no `::create`, no factory, no seeder. Fully wired front-to-GL, never receives data. |
| **Finance donor/petty/stream** | ✅ | `DonorFundService` (restricted grants) + `PettyCashService` (office float) — **NOT duplicates** of resident money; leave as-is. |

- ⚠️ **Netting flaw:** `ClientLedgerService` (read model behind `clients/Financials.tsx`) folds operational
  `fin_cost_allocations` **into the same running balance** as personal `ClientLedgerEntry` money
  (`ClientLedgerService.php:163-225`). Masked today only because `ClientLedgerEntry` is empty. If ever
  populated, resident personal balances co-mingle with operational costs — **violates segregation**.
- ⚠️ **Two profile finance tabs read different backends:** `operations/clients/tabs/finance.tsx`
  (`ClientController:702-759`) reads legacy `ClientFund` (live) + `ClientLedgerEntry` (empty);
  `clients/Financials.tsx` reads modern `ClientLedgerService` (personal section always empty). Family portal
  reads the legacy surface. → one canonical home needed.

### Donor / trust / grant funds — ✅ `DonorFundService` (receipts/expenditures post real journals; reports). Polish only.
### Per-site / per-service cost centres — ✅ every event carries `site_id`/dims via `FinCostAllocation`; `SiteCostService` reads them.
### Service-level billing (rostered hours → invoice/claim) — ✅ `BillingService` (ServiceAgreement + rate matrix → BillingEntry → FinInvoice, NZ GST 15%). The only legitimate `FinInvoice` creator outside the Finance domain. No profile UI to manage agreements/rates yet.

---

## D. Payroll end-to-end → Finance (the headline shared seam with HR)

**Status: ~95% wired** (the prior audit's "disconnected" is wrong; HR's "~80% built" is closer).
Chain that works today: `PayrollExportController::lockRun:160` → `PostPayrollJournalJob` →
`PayrollJournalService::postPayrollJournal` (DR 5000 gross/5010 KS-er/5020 ACC = CR 2100 PAYE/2110 ACC/
2120 KS/2130 SL/**2300 Accrued Wages (net)**) → `JournalPosted` → `AllocatePayrollCosts` →
`ProcessPayrollAllocationsJob` → `PayrollCostAllocationService` (timesheet-attributed, split by site/client).
Tested by `PayrollJournalPostingTest` (asserts debits==credits).

**Two genuine gaps (the only blockers):**
1. ❌ **Payslip pre-generation not enforced at lock.** `PayrollExportService::lockRun` does **not** call
   `generateBulkPayslips`; `postPayrollJournal` requires payslips or **throws "no payslips to post"** and the
   job dies to `failed_jobs` silently (run stays `locked`, `journal_id=null`). The passing test only works
   because it hand-generates payslips first. → generate payslips inside lock (or block lock until they exist).
2. ❌ **Net pay never disbursed.** `PaymentRunService` pays **only vendor `FinBill`s**; nothing debits 2300
   Accrued Wages to the bank. No employee net-pay payment run / direct-credit bank file. → build a
   payroll-sourced payment run (DR 2300 / CR Bank from payslip `net_pay` + employee bank account).
3. ❌ **PAYE/IR348 payday filing** not surfaced from a posted run (see §B Tax).
4. 🟡 Bridge hardening: GL codes hardcoded (`PayrollJournalService:253`); requires open period + seeded
   accounts or the queued job throws unseen; run-gross (rate rule) vs payslip-gross (NZ calc) can diverge.

> **Cross-loop note:** HR's plan claims M5-1 (payslip-in-lock) and M5-2 (net-pay payment run) as **HR**
> milestones. Finance owns GL posting + the payment run + IRD filing. These overlap — **build ONE bridge**.
> See the rebuild-plan "Cross-loop coordination" section before touching M5.

---

## E. Cross-module finance — capture gaps (infrastructure is trustworthy)

The GL bridge + observers + `BillingService` mean operational modules **never hand-roll journals** (clean,
no shadow GL). Already-wired: Site House Ledger, Site Rent/Utilities (scheduled), Fleet fuel/work-orders,
Asset maintenance, Mileage, Service-agreement AR. **Remaining capture GAPs** (each needs an embedded
Add-Client-style modal routing to the existing `FinancialEventService`/`FinBill`/`FinInvoice` paths):

| Touchpoint | Gap | Target |
|---|---|---|
| Site Damages | repair cost / insurable loss posts nothing | on-repair → AP maintenance event; optional insurance AR |
| Catering / meal shopping | food spend never reaches finance (qty-only) | shopping-list complete → `HouseLedgerEntry` (groceries) per site |
| Respite bookings | no billing/funding despite funder selection at intake | on-confirm → AR invoice vs funder + respite-funding drawdown |
| Asset capitalisation | no `purchase_cost`; no path to `FinFixedAsset` | add cost + "capitalise" action → `FinFixedAsset` + journal |
| Operational AP attribution | fleet/asset/utility events credit generic AP `2000`, no vendor/bill line | route operational AP through `FinBill`+`FinVendor` for aging/payment-runs |
| Pharmacy orders (eMAR) | reorders are qty-only, no AP | optional pharmacy-order → AP bill (if in scope) |
| Incident insurable loss | no AR recovery capture | optional insurance-recovery AR on serious incidents |
| Site Vendors vs FinVendor | compliance-contact table unlinked to AP vendor master | add optional `fin_vendor_id` FK |

### Budgets — THREE stores, double sync, dead interface
- **Store A (Governance fiscal):** `budgets`/`budget_line_items` (annual, category) — `actual_amount` **denormalised**, synced from journals.
- **Store B (Finance site-monthly):** `site_budget_lines` + `fin_cost_allocations` (per-site/month) — planned-only, variance derived, **journal-backed, has working alerting**.
- **Store C (roadmap):** governance envelope rollup.
- A and B **never reconcile** (different category vocabularies, periods, actuals plumbing). `BudgetActualsService`
  (a Finance service) reaches **across into Governance models**. `SyncBudgetActualsJob` (hourly) **and**
  `governance:update-budget-variances` (hourly) both run the same sync → **store A synced twice/hour**.
  `BudgetSyncInterface` (the contract meant to prevent this parallel copy) is **orphaned — zero implementations**.
  `SpendApproval` approves money but **posts nothing to AP/GL** (`source` morph never set).
- ✅ Variance **alert delivers** for store B (prior-audit P1-3 is stale).

---

## F. Design parity — the dominant gap (0% adoption today)

Every finance page calls `<PageHero>` but **none passes `category=`** → all render the default `--primary`
(ops-purple) gradient. **No `'finance'` in `PageHeroCategory`** (`page-hero.tsx:28-35`); **no
`--category-finance` token** (`app.css`). **`TabStrip` used by zero finance pages** (only `donor-funds/Show`
uses `ui/tabs`). **`WizardShell` used by zero finance pages** — CRUD is standalone Create/Edit/Show pages
(documents) or single-step shadcn `Dialog` (admin entities). **No Finance calendar.** Dead-UI sweep: **0**
"coming soon"/TODO stubs — the module is functionally complete; the gap is the **Rostering design contract**.

The ~40 leaf nav items collapse into **8 hubs + Settings** (see rebuild plan §Hub/Tab/Route map).

---

## G. Reprioritised defect list (supersedes the 2026-05-01 P0/P1/P2)

**P0 (correctness / data-blind):**
- AR surfaces (`/finance/receivables`, aged-AR report, statements) read the **orphaned legacy `Invoice`** → empty. *(M2)*
- `markPaid` posts no receipt journal → AR overstated. *(M2)*
- Payroll: payslip-in-lock not enforced (silent GL fail) + net pay never disbursed. *(M5, w/ HR)*
- Client-money: empty `ClientLedgerEntry` + netting flaw + two divergent profile tabs. *(M6)*
- `5020` chart collision (leave_provision mis-posts). *(M1)*

**P1 (functional gaps):**
- Recurring charges engine broken (wrong columns + unscheduled). *(M2)*
- AR credit-note GST not reversed. *(M2)*
- Quote→invoice conversion missing. *(M2)*
- Match-rule conditions decorative; bank feeds throw (no token exchange). *(M4)*
- Budgets: 3 stores, double sync, orphaned `BudgetSyncInterface`, SpendApprovals posts nothing. *(M8)*
- PAYE/IR348 payday filing missing. *(M5/M7)*
- Cross-module capture gaps (Damages/Catering/Respite/Asset-capitalisation/AP-attribution). *(M9)*
- No Finance calendar. *(M9)*

**P2 (cleanup / polish):**
- 4 orphaned jobs (`ImportBankTransactionsJob`, `PostBillingJournalJob`, `PostExpenseJournalJob`, `ProcessPaymentRunJob`) — delete or wire. *(M10)*
- Dead `BillingJournalService`/`PostBillingJournalJob`; stale `FundingService`. *(M2/M6)*
- Xero account-mapping unused; MYOB stub banner. *(M10)*
- Bills `'partial'` vs `'partially_paid'` summary bug. *(M3)*
- `finance:verify-chart` doesn't check name parity. *(M1)*
- Petty-cash top-up/adjustment unbooked. *(M4)*

See `docs/finance-module-rebuild-plan.md` for the milestone-grouped Problem→Evidence→Fix→Acceptance plan,
the hub/tab/route map, the cross-module reconciliation plan, and the HR cross-loop coordination notes.
