# Finance module — production-readiness audit & phased plan

**Audit date:** 2026-05-01
**Working directory audited:** `C:\Users\steph\Herd\oblivionfindings` (live tree only — `.claude/worktrees` ignored).
**Bias:** repair existing wiring; no rewrites recommended. The Finance domain is structurally sound — most of it just isn't connected upstream.

## Architecture summary (what exists today)

The Finance module has **two** GL-posting paths:

1. **Modern path** — `FinancialEventService::record()` (`app/Domain/Finance/Services/FinancialEventService.php`) is the documented single-entry pipeline: idempotency-keyed `FinFinancialEvent` → balanced journal via `JournalPostingService` → `FinCostAllocation` (site/client/staff/asset/shift dimensions) → back-link `journal_id` on source. Dispatched async by `ProcessFinancialEventJob` (`app/Domain/Finance/Jobs/ProcessFinancialEventJob.php:23`).
2. **Legacy direct path** — `PayrollJournalService`, `BillingJournalService`, `FundingClaimJournalService`, `ClientFundJournalService`, `FixedAssetService`, `AccountsPayableService` post journals directly through `JournalPostingService::createAndPost()`. The header on `FinancialEventService.php:23` calls these out as "predate this service and remain independent."

`JournalPostingService` itself is robust: balances-must-equal validation, fiscal-period gate, account/cost-centre/funding-stream FK validation, atomic transactions, `JournalPosted` event firing (`app/Domain/Finance/Services/JournalPostingService.php:64-171`). The hourly `SyncBudgetActualsJob` (`routes/console.php:329`) and `BudgetActualsService::sumPostedJournalLines()` (`app/Domain/Finance/Services/BudgetActualsService.php:207-231`) read real posted-journal data — so once GL is fed, governance reporting is correct.

**The downstream is fine. The upstream is the problem.**

---

## P0 — production blockers

### P0-1 — Payroll never reaches GL
- **Evidence:** `PayrollJournalService::postPayrollJournal()` (`app/Domain/Finance/Services/PayrollJournalService.php:30`) and `PostPayrollJournalJob` (`app/Domain/Finance/Jobs/PostPayrollJournalJob.php:14`) have **zero callers**. `PayrollExportController::lockRun`/`export` (`app/Http/Controllers/Hr/PayrollExportController.php:141-210`) creates run, generates payslips (`PayslipService::generateBulkPayslips`), exports CSV — but never dispatches the GL job.
- **Why it matters:** Wages, PAYE, KiwiSaver, ACC levy, accrued wages — the largest cost line — never hit the ledger. Every downstream system suffers: `AllocatePayrollCosts` listener (`app/Listeners/Finance/AllocatePayrollCosts.php:21`) only fires on `JournalPosted` with `type='payroll'`, so cost-allocations never get built. P&L, site cost dashboards, staffing-cost services, board budget actuals all under-report by the entire wage bill.
- **Minimal change:** Dispatch `PostPayrollJournalJob` from `PayrollExportController::lockRun` (after successful lock) — payslips already exist by lock time. Set `tries=1` and pre-check `journal_id IS NULL` to keep it idempotent. Optional follow-up: surface the resulting `journal_number` in the run UI.
- **Tests to add:** Feature test — create run with two timesheets across two sites, lock, assert one `fin_journals.type='payroll'`, balanced lines on 5000/5010/5020/2100/2110/2120/2130/2300, `cost_allocated_at` set after listener fires, two `fin_cost_allocations` carrying `site_id`/`shift_id`.
- **Type:** wiring gap.

### P0-2 — Chart-of-accounts code conflicts will mis-post or crash every event
- **Evidence:** `config/finance.php` references codes like `6210` (Vehicle Maintenance), `6300` (Equipment Maintenance), `6500` (Staff Expenses), `6510` (Training), `6520` (Travel & Mileage), `6400` (Rent), `6410` (Utilities), `6420`, `6430`, `6440`, `6431-6437`, `4100` (Funding Income), `4200` (House Income), `4210` (Resident Contributions), `4220` (Donations), `2310` (Claims Payable), `2400` (Accrued Leave), `2500` (Provision for Claims), `6600` (Incident Remediation). The seeded chart in `database/seeders/FinanceSeeder.php:103-173` does NOT seed `2310`, `4210`, `4220`, `6210`, `6410`-`6440`, `6431`-`6437`, `6510`, `6520`, `6600`. Worse, **conflicting names on the same code**:
  - `6200` seeded as "IT & Communications" — config expects "Fuel & Oil" for `fuel_expense`
  - `6300` seeded as "Office Supplies" — config expects "Equipment Maintenance" for `asset_maintenance_expense`
  - `6400` seeded as "Professional Fees" — config expects "Rent & Lease" for `site_rent_expense`
  - `6500` seeded as "Compliance & Audit" — config expects "Staff Expenses" for `expense_claim`
  - `4100` seeded as "Interest Income" — config expects "Funding Income"
  - `2400` seeded as "Holiday Pay Accrual" — usable but `leave_provision` config calls it "Accrued Leave Liability"
  - `2500` seeded as "Client Trust Funds" — `incident_remediation` config expects "Provision for Claims"
- **Why it matters:** `FinancialEventService::resolveAccount()` (`app/Domain/Finance/Services/FinancialEventService.php:316`) throws `RuntimeException` when a code doesn't exist — meaning fuel, training, mileage, expense claims, site rent/utilities, house ledger, client ledger, asset maintenance, fleet maintenance, leave provision, donor income all immediately fail. For codes that *do* exist with the wrong name (6200, 6300, 6400, 6500, 4100, 2500), events post silently to the wrong account — financial reports become factually wrong.
- **Minimal change:** Reconcile `FinanceSeeder::getDefaultAccounts()` with `config/finance.php`. Either rename/add seed entries to match config codes, or change the config codes to match the seeded codes — pick one direction and align. New accounts to add at minimum: `2310, 4210, 4220, 6210, 6410-6440, 6510, 6520, 6600`. Renumber colliding codes (`6200, 6300, 6400, 6500, 4100, 2500`) and update the config or rename the seeded accounts. Run a one-off backfill seeder for already-deployed orgs.
- **Tests to add:** Unit test — for every key in `config('finance.event_accounts')` and `config('finance.payment_type_accounts')`, assert a seeded `FinAccount` exists with that code in the org used by `FinanceSeeder`. Feature test — fire one financial event of every event_type and assert it posts without throwing.
- **Type:** data-quality / wiring (config vs seed divergence).

### P0-3 — AR (`FinInvoice`) has no GL posting at all
- **Evidence:** `fin_invoices` migration (`database/migrations/2026_03_28_004100_create_fin_invoices_table.php:11-44`) has no `journal_id` or `gl_posted_at` columns. `FinInvoice` model has no GL relationship. `InvoiceController::store/send/markPaid` (`app/Domain/Finance/Http/Controllers/InvoiceController.php:95-355`) never posts a journal. `BillingJournalService::postInvoiceJournal()` (`app/Domain/Finance/Services/BillingJournalService.php:28`) accepts the *legacy* `App\Models\Invoice` (which has `journal_id`) — but the FinInvoice route is the one the UI uses. There are two parallel invoice systems, and the live one is GL-blind.
- **Why it matters:** Aged receivables, GST collected, P&L revenue, funding-stream summary reports — none reflect AR-side revenue when invoices are issued through `/finance/invoices`. Cash receipts hit the bank but there's no AR balance to clear.
- **Minimal change:** Add `journal_id` and `gl_posted_at` columns to `fin_invoices` (one migration). Add a `FinInvoiceJournalService` modeled on `BillingJournalService` but accepting `FinInvoice` (DR 1100 AR / CR 4xxx revenue per line account_id / CR 2200 GST). Dispatch from `InvoiceController::send` (status transition draft→sent is the right life-cycle point per accrual accounting) via a new `PostFinInvoiceJournalJob`. Apply `journal_id IS NULL` guard. Reverse on `cancelled`. Don't rewrite the controller — wrap the existing transaction.
- **Tests to add:** Feature — create invoice with two lines + GST, send, assert balanced journal posted with one DR to 1100 = total, CR to revenue accounts = subtotals, CR to 2200 = GST; cancel and assert reversing journal.
- **Type:** missing feature (architectural gap by design — needs to be filled).

### P0-4 — Funding-claim and client-fund journals never dispatched
- **Evidence:** `FundingClaimJournalService` is only called by `PostFundingClaimJournalJob` (`app/Domain/Finance/Jobs/PostFundingClaimJournalJob.php:31`), and that job has zero dispatchers. `ClientFundJournalService` has zero callers anywhere. There is no observer on `FundingClaim` or equivalent.
- **Why it matters:** Funding revenue (Whaikaha, ACC, NASC) is the second-largest revenue stream and never recognises. `funding_stream_summary` report is empty.
- **Minimal change:** Identify the source model the project intends as the funding claim trigger (likely under `app/Models/FundingClaim*` or `App\Domain\Clinical`). Create a small observer that dispatches `PostFundingClaimJournalJob` when claim status → `submitted` (or `approved`, depending on accounting policy). For `ClientFundJournalService`, decide if it's superseded by `ClientLedgerEntryObserver` (which already wires `funding`-type entries to GL via the modern pipeline) — if yes, delete the dead service; if no, wire to `FinDonorFundTransaction` similarly.
- **Tests to add:** Feature test on the chosen funding claim source → journal posting + AR balance.
- **Type:** wiring gap (or, for `ClientFundJournalService`, decide-then-delete-or-wire).

### P0-5 — Bank reconciliation, payment matching, and EFTPOS settle nothing
- **Evidence:**
  - `BankReconciliationService::completeReconciliation()` updates statuses and balances; no `JournalPostingService` call.
  - `PaymentMatchingService::confirmMatch()` marks the match `confirmed`; does not post a clearing journal or allocate against AP/AR.
  - `EftposReconciliationService::reconcileBatch()` flips status to `reconciled`; no settlement journal.
- **Why it matters:** Bank-cleared transactions and matched receipts/payments do not flow into GL, so cash-basis reports diverge from accrual reports, and AP/AR balances do not clear when bills/invoices are paid via the bank-feed path. Aged payables/receivables grow forever.
- **Minimal change:** Two narrow additions:
  1. In `PaymentMatchingService::confirmMatch()` (`app/Domain/Finance/Services/PaymentMatchingService.php:186`), when match links to a `FinBill` or `FinInvoice`, call `JournalPostingService::createAndPost()` with DR 2000 AP/CR 1000 Bank (bill payment) or DR 1000/CR 1100 AR (invoice receipt), set `journal_id` on the match, increment `bill.amount_paid` (already implemented in `AccountsPayableService::recordPayment`).
  2. EFTPOS settlement: similar — DR 1000 Bank / CR 1180 Card-Clearing. Bank-rec completion already validates totals, no journal needed there if settlement is journal'd.
- **Tests to add:** Feature — import a bank transaction matching an approved bill, confirm match, assert AP balance falls by paid amount, assert bank balance increases (cash-side journal exists).
- **Type:** missing feature (small).

### P0-6 — Operational scheduled jobs not registered
- **Evidence:** `routes/console.php` schedules `PostSiteRentJob`, `PostSiteUtilitiesJob`, `PostLeaveProvisionJob`, `SyncBudgetActualsJob`, `ReconcileTimesheetsJob`. Missing: `RunDepreciationJob`, `SyncBankFeedsJob`, `RunPaymentMatchingJob`, `CheckBillDueDatesJob`, `CalculateGstReturnJob`, `GenerateRecurringJournalsJob`, `SnapshotFinancialReportsJob`, `SyncAccountingIntegrationJob`. All exist as classes, none are scheduled.
- **Why it matters:** Without depreciation and recurring journal generation, P&L and balance sheet drift; without bill-due reminders, AP overdue notifications never fire; without report snapshots, you have no immutable point-in-time audit trail.
- **Minimal change:** Add the 7-8 schedule entries to `routes/console.php`. Choose cadences:
  - `RunDepreciationJob` — monthly 1st at 03:00 NZT (after rent at 02:00 and utilities at 02:30)
  - `SyncBankFeedsJob` — every 30 minutes during business hours, hourly otherwise
  - `RunPaymentMatchingJob` — chained after sync (or every 30 min)
  - `CheckBillDueDatesJob` — daily 07:00
  - `GenerateRecurringJournalsJob` — daily 02:45
  - `SnapshotFinancialReportsJob` — monthly 1st at 23:55 (end-of-period)
  - `CalculateGstReturnJob` — bi-monthly 28th at 04:00
  - `SyncAccountingIntegrationJob` — only after Xero/MYOB providers are real (P1-2)
- **Tests to add:** A `Schedule::events()` test asserting each command is scheduled with the expected cron and `withoutOverlapping()`.
- **Type:** wiring gap.

### P0-7 — Zero end-to-end tests for any finance pipeline
- **Evidence:** `tests/Browser/Finance/` contains 8 Dusk smoke tests checking pages load (e.g. `tests/Browser/Finance/FinanceBillsTest.php:6-14` just asserts "Bills" text appears). `tests/Feature/FinancePolicyTest.php` only covers RBAC. **No test references** `FinJournal`, `fin_journals`, `journal_id`, `gl_posted_at`, `FinancialEventService`, `PayrollJournalService`, `BillingJournalService`, `ProcessFinancialEventJob`, `PayrollCostAllocationService`, any observer, or `FixedAssetService::runDepreciation`.
- **Why it matters:** Every fix in P0-1 through P0-6 needs a regression net before going live. Without coverage, balance/posted-period/idempotency invariants will regress silently.
- **Minimal change:** Add `tests/Feature/Finance/` directory with one feature test per upstream flow: payroll, expense claim, training, fuel, asset maint, fleet maint, mileage, leave provision, site rent, site utilities (estimate→actual true-up), house ledger, client ledger, donor receipt/expenditure, bill approval, credit note approval, invoice send (after P0-3), payment matching (after P0-5), depreciation run. Each test: build minimal source data → trigger → assert balanced journal exists → assert cost allocations carry expected dimensions → assert source `journal_id` is back-linked → re-trigger and assert idempotent (no duplicate event). Use `RefreshDatabase` + `FinanceSeeder` so chart-of-accounts mismatches surface immediately.
- **Type:** missing tests (P0 because every P0 above needs them to verify).

---

## P1 — required hardening

### P1-1 — Decommission or migrate legacy `App\Models\Invoice`
- **Evidence:** `BillingJournalService::postInvoiceJournal()` accepts `App\Models\Invoice`, while the live `/finance/invoices` UI uses `FinInvoice`. There are two separate AR systems. `App\Services\Operations\BillingService::generateInvoice()` (`app/Services/Operations/BillingService.php`) creates legacy invoices from operational billing entries.
- **Why it matters:** After P0-3 fixes the FinInvoice path, the legacy path becomes a confusing parallel that can post duplicate or orphan journals.
- **Minimal change:** Once P0-3 lands and `FinInvoice` posts journals, change `BillingService::generateInvoice` to create `FinInvoice` records, leave the legacy `Invoice` model in place but stop creating new rows; freeze legacy invoices via DB read-only flag and plan an archival migration. Don't drop the table — historical data lives there.
- **Type:** larger design risk if rushed; treat as P1 cleanup to do *after* P0-3.

### P1-2 — Xero/MYOB providers are stubs
- **Evidence:** `XeroSyncProvider` and `MyobSyncProvider` throw `RuntimeException('...integration pending configuration')`. `SyncAccountingIntegrationJob` has retry logic but the provider always fails fast.
- **Why it matters:** Posting journals into the system is meaningless if there's no path out to the customer's actual accounting system. Audit trail breaks at the integration boundary.
- **Minimal change:** Implement the OAuth2 + journal-export endpoints for Xero (highest customer demand in NZ) using Saloon. Keep MYOB as stub with a clear "not yet supported" UI banner until needed. Reuse the existing `FinGlSyncLog`, `FinAccountingIntegration`, `FinAccountMapping` tables — they're correctly modeled. Don't redesign.
- **Tests to add:** Recorded HTTP fixture tests for Xero token refresh, journal upsert, contact upsert; integration test against Xero sandbox is post-go-live.
- **Type:** missing feature.

### P1-3 — Variance alerts are computed but never delivered
- **Evidence:** `BudgetVarianceAlertNotification` (`app/Domain/Finance/Notifications/BudgetVarianceAlertNotification.php`) is defined; no dispatcher anywhere. `BudgetVarianceService` and the hourly `SyncBudgetActualsJob` calculate variance, dashboards display it, but nobody is notified.
- **Minimal change:** Inside `SyncBudgetActualsJob` (or a downstream listener on the same data) — after sync, query budget lines crossing `insight_thresholds.budget_approaching_pct` and `budget_over_pct` (`config/finance.php:262`), debounce against a `last_alerted_at` column on `SiteBudgetLine` (add one — small migration), notify the budget owner role.
- **Tests to add:** Feature — seed a budget line at 90% consumed, run job, assert notification queued; run again, assert no second notification within debounce window.
- **Type:** wiring gap.

### P1-4 — Bill due-date and aging notifications
- **Evidence:** `CheckBillDueDatesJob` and `BillDueNotification`/`BillOverdueNotification` exist. The job is not scheduled (P0-6 also covers this). Once scheduled, confirm it actually filters correctly and respects `amount_paid < total_amount`.
- **Minimal change:** Schedule (in P0-6) plus a unit test asserting it picks up only unpaid bills with `due_date <= today + N`.
- **Type:** wiring gap (sub-issue of P0-6).

### P1-5 — Idempotency on payroll and other "predate FinancialEventService" paths
- **Evidence:** `PayrollJournalService::postPayrollJournal()` checks `$payrollRun->journal_id !== null` (`app/Domain/Finance/Services/PayrollJournalService.php:32`) — that's the only guard. A re-dispatch where `journal_id` is somehow null but a journal was actually created (queue failure mid-update) double-posts. Same risk for `BillingJournalService` and `FixedAssetService`.
- **Minimal change:** Wrap `journal_id` assignment in the same DB transaction as `createAndPost` (already so) and add a deterministic uniqueness key — for payroll, a unique index on `fin_journals (organization_id, type='payroll', source_id)` where source_id = payroll_run_id; partial unique index on `posted` rows.
- **Tests to add:** Concurrency test simulating two simultaneous dispatches for the same run; assert exactly one journal exists.
- **Type:** bug fix / hardening.

### P1-6 — Bank feed providers return empty arrays
- **Evidence:** All 4 NZ bank providers (`AnzBankFeedProvider`, `AsbBankFeedProvider`, `BnzBankFeedProvider`, `WestpacBankFeedProvider`) return `[]` from `fetchTransactions()`.
- **Why it matters:** `SyncBankFeedsJob` runs but imports nothing. Reconciliation requires manual CSV upload via `BankTransactionController::import` — works, but defeats the "bank feed" name.
- **Minimal change:** This is genuinely a "missing feature." Defer until bank-API access is procured. In the meantime, hide bank-feed UI behind a feature flag and document CSV import as the supported path.
- **Type:** missing feature.

### P1-7 — Audit export retention & encryption
- **Evidence:** `AuditExportService` writes ZIPs to local storage at `audit-exports/{orgId}/{exportId}.zip`. Unencrypted; no retention policy in the code.
- **Why it matters:** Audit exports contain payroll, vendor, GST, and journal data — sensitive enough to justify at-rest encryption, especially given NZ Privacy Act 2020 obligations.
- **Minimal change:** Use Laravel's encrypted disk (or wrap with `Crypt::encrypt` before write); add a daily prune in `EnforceDataRetentionJob` for exports older than the configured retention window (default 7 years for financial records under IRD requirements).
- **Type:** hardening.

### P1-8 — Finance config drift detection
- **Minimal change:** Add an Artisan command `finance:verify-chart` that walks every code referenced in `config/finance.php` and asserts it exists for the org's chart. Fail CI if drift exists. Cheap protection against P0-2 happening again.
- **Type:** hardening.

---

## P2 — polish / non-blocking

### P2-1 — Consolidate dashboard services
- **Evidence:** `FinancialKPIService`, `FinancialInsightsService`, `SiteFinancialDashboardService`, `StaffingCostService`, `ClientCostService`, `CostPerResidentService`, `SiteCostService`, `DashboardAggregatorService`, `ClientFinancialSummaryService` — significant overlap in queries against `fin_journal_lines` and `fin_cost_allocations`.
- **Minimal change:** Don't refactor unless tests in P0-7 reveal slow N+1 queries. If they do, extract a small `FinanceQueryBuilder` for the common joins. Otherwise leave alone — a working surface is better than an elegant one.
- **Type:** polish (only if perf evidence appears).

### P2-2 — Recurring journal generator
- **Evidence:** `RecurringJournalService` and `GenerateRecurringJournalsJob` exist. Once scheduled (P0-6), confirm the recurrence logic supports the cadences the project actually needs (monthly rent is already covered by `PostSiteRentJob` — make sure operators don't double-configure).
- **Minimal change:** Documentation pass clarifying overlap with `PostSiteRentJob`/`PostSiteUtilitiesJob`.
- **Type:** polish.

### P2-3 — FX revaluation and consolidation
- **Evidence:** `FxRevaluationService`, `ConsolidationService`, `IntercompanyService` exist with full CRUD. Only useful in multi-currency / multi-entity setups.
- **Minimal change:** None — leave as-is for single-org NZ deployments. Add feature-flag if you want to hide them from the UI for orgs that don't use them.
- **Type:** polish.

### P2-4 — Donor fund reporting completeness
- **Evidence:** `DonorFundService` posts receipts and expenditures correctly via `JournalPostingService`. Donor fund reports (`FinDonorFundReport`) exist.
- **Minimal change:** Add a test that exercises the full receipt → expenditure → report PDF cycle. No code change expected.
- **Type:** polish (test only).

### P2-5 — IRD e-filing flow
- **Evidence:** `IrdFilingService` and routes exist; status-flow looks complete but unverified end-to-end.
- **Minimal change:** Manual smoke test against IRD sandbox. No code expected; defer until tax season.
- **Type:** polish.

---

## What was deliberately NOT recommended

- **Rewriting `PayrollJournalService` to route through `FinancialEventService`.** The header on `FinancialEventService.php:23` explicitly preserves the legacy services. Both paths produce balanced journals through `JournalPostingService` and the listener-based `AllocatePayrollCosts` already bridges payroll into the modern cost-allocation table. Repair the wiring; don't unify the abstractions.
- **Replacing `App\Models\Invoice` immediately.** P1-1 plans a careful migration. Rushing it risks losing legacy data linkages.
- **Touching the chart of accounts schema.** The data model is fine; the *seed values* are wrong (P0-2). That's a one-file fix plus a backfill script.
- **Reorganising the Finance domain layout.** ~60 models, ~40 services — large but coherent. The structure can support production once wiring is fixed.

---

## Summary of evidence quality

| Area | Status | Confidence |
|------|--------|-----------|
| Modern observer-based pipeline (8 observers, ProcessFinancialEventJob, FinancialEventService) | Wired and idempotent | High — verified directly |
| Site rent / utilities / leave provision jobs | Wired, scheduled, idempotent | High — verified directly |
| Payroll → GL | **Disconnected** | High — `PostPayrollJournalJob` has zero callers |
| FinInvoice → GL | **Disconnected by schema** | High — verified migration |
| Bank reconciliation / payment matching → GL | **Disconnected** | Medium — sub-agent report; spot-checked services |
| Xero/MYOB sync | **Stubs** | Medium — sub-agent report |
| Chart-of-accounts vs config | **Mismatched** | High — verified seeder and config side-by-side |
| Test coverage of any of this | **Zero** | High — grep returned no matches |
| Budget actuals from posted journals | Correct | High — verified service code |
| Reports/dashboards from real GL | Correct | Medium — sub-agent report |

P0-1, P0-2, and P0-3 alone explain why Finance "looked feature-rich while not actually receiving complete, reliable data" — exactly the concern that triggered this audit.

---

## Recommended P0 execution order

1. **P0-2 first** (chart-of-accounts seed reconciliation). Without this, every other P0 fix will fail at runtime with `RuntimeException: GL account not found`.
2. **P0-7 scaffolding** — set up `tests/Feature/Finance/` with one passing happy-path test (e.g. expense claim) so subsequent P0 work has a regression net.
3. **P0-1** payroll wiring.
4. **P0-3** FinInvoice GL posting.
5. **P0-4** funding-claim observer.
6. **P0-5** payment-matching journal posting.
7. **P0-6** scheduled jobs registration.
8. Round-trip P0-7 with full pipeline coverage.
