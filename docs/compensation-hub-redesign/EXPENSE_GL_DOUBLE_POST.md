# Expense-claim GL double-post — Finance hand-off

> **Status: documented, NOT fixed.** Per `COMPENSATION_HUB_GAP_ANALYSIS.md` §"Cross-domain — DO NOT touch this pass", the expense→GL posting is Finance's system of record. This note traces the exact behaviour and flags the likely-correct single path so Finance can decide. **No posting behaviour was changed.**

## Symptom

When an expense claim transitions `submitted → approved`, **two independent GL journals are posted for the same claim**, to **different accounts**.

## The two paths (both fire from the one `status → approved` transition)

### Path A — the purpose-built expense journal (intended)
`ExpenseController::approve()` → `ExpenseService::approveClaim()` (`app/Domain/Hr/Services/ExpenseService.php:117`):
1. `$claim->update(['status' => 'approved', …])`
2. if `journal_id === null` → `PostExpenseJournalJob::dispatch($claim)` (`:137`)
   → `ExpenseJournalService::postExpenseClaimJournal()` (`app/Domain/Finance/Services/ExpenseJournalService.php:39`):
   - **DR** per **item category** via `CATEGORY_ACCOUNT_MAP` (`:18`): travel→**6100**, meals→**7010**, accommodation→**6000**, supplies→**6300**, mileage→**6100**, other→**6300**
   - **CR 2000** Accounts Payable (claim total)
   - sets `journal_id` + `gl_posted_at`, and has a matching `reverseExpenseClaimJournal()`.

### Path B — the generic financial-event pipeline (redundant)
`ExpenseService::approveClaim()`'s `$claim->update(...)` in step 1 above ALSO fires `HrExpenseClaimObserver::updated()` (`app/Observers/HrExpenseClaimObserver.php:19`), which:
- dispatches `ProcessFinancialEventJob` with `event_type: 'expense_claim'`
  - **DR 6500** Staff Expenses (from `config('finance.event_accounts.expense_claim.debit')`, `config/finance.php:39`)
  - **CR 2310** Expense Claims Payable (`payment_type: PAYMENT_REIMBURSEMENT`, per the observer docblock)

## Why the `journal_id` guards don't prevent it

Both paths guard on `journal_id === null`, but **both guards evaluate during the same request, before either job runs**:
- The observer's guard (`:29`) runs synchronously inside `$claim->update(...)` — `journal_id` is still `null`, so it dispatches Path B.
- `approveClaim` then checks `$result->journal_id === null` (`:135`) — still `null`, so it dispatches Path A.

Both jobs enqueue; each posts its own journal. Net effect: **the expense is booked twice**, to **contradictory accounts**:

| | Debit | Credit |
|---|---|---|
| Path A (ExpenseJournalService) | 6100 / 6000 / 6300 / 7010 (by category) | **2000** Accounts Payable |
| Path B (observer / financial-event) | **6500** Staff Expenses | **2310** Expense Claims Payable |

Note the **chart-of-accounts contradiction** even on the debit side: `config/finance.php` maps `expense_claim → 6500` and `mileage_reimbursement → 6520`, while `CATEGORY_ACCOUNT_MAP` maps the same categories to 6100/6300/7010/6000. These two mappings disagree and need reconciliation regardless of which path is kept.

## Recommended single path (Finance to confirm)

**Keep Path A (`ExpenseJournalService`), retire Path B (the observer's GL dispatch).** Rationale:
- Path A is category-granular, sets `journal_id`/`gl_posted_at` (which the **pay** gate already depends on — `ExpenseController::pay()` refuses to disburse until `gl_posted_at !== null`), and ships a reversal method. It's explicitly invoked and mirrors the payroll `lock → PostPayrollJournalJob` bridge.
- Path B is the generic `FinFinancialEvent` pipeline duplicating the same economic event to a second account pair.

If instead the `FinFinancialEvent` pipeline is Finance's system of record, keep Path B and remove the `PostExpenseJournalJob` dispatch from `approveClaim()` — but then the `pay()` gate on `gl_posted_at` must be re-pointed at whatever field the financial-event path sets.

## Suggested fix shape (for whoever picks this up — do NOT apply without Finance sign-off)
- Comment out / remove the GL dispatch in `HrExpenseClaimObserver::updated()` (Path B), **or** the `PostExpenseJournalJob::dispatch()` in `ExpenseService::approveClaim()` (Path A) — not both.
- Reconcile `CATEGORY_ACCOUNT_MAP` (`ExpenseJournalService`) with `config('finance.php').event_accounts.expense_claim/mileage_reimbursement` so the debit accounts agree.
- Add a regression test asserting exactly **one** `FinJournal` (or one financial event) per approved claim.

## Related (also cross-module, out of scope here)
- **Mileage rate fragmentation** — three independent sources: Operations `MileageClaim` (0.97, user-editable), Fleet `FleetPersonalTrip` (0.95, IRD), Timesheet `mileage_km` (config). The new expense claim dialog reads the single `config('finance.mileage_rate_per_km')` (0.95); consolidating the other three onto it is a future Finance/Operations decision. The read-only Settings tab visualises the intended end-state.
