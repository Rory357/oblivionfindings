<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAuditExport;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinMatchRule;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BillingEntry;
use App\Models\PriceBook;
use App\Models\Quote;
use App\Models\RecurringCharge;
use Illuminate\Database\Eloquent\Model;

/**
 * Row counts for the finance hub tab strips — the count badge next to each tab.
 *
 * Shared once per finance request by {@see HandleInertiaRequests}
 * as `financeHubCounts`, keyed hub → tab id → count; every `*TabsFooter` reads its own
 * hub's slice and sets `badge` per tab. Only LIST tabs are counted — report / dashboard
 * / workspace tabs (aged-AR, statements, reconciliation, matching, consolidation, and the
 * Overview / Reports / Settings hubs) have no list to size, so they carry no badge.
 *
 * A count is the total application-wide rows in that list (all statuses) — i.e. exactly
 * how many rows the tab's list holds. Zero is returned as 0; the footer omits the badge for 0
 * so an empty list reads clean (and its page shows the EmptyState). Every count is
 * individually guarded (try/catch → 0) so a missing table or model quirk yields no badge
 * for that one tab rather than 500-ing every finance page — this runs in middleware.
 *
 * Keep the tab ids here in lockstep with the `*_TABS` arrays in components/finance/*-hub.tsx.
 */
class FinanceHubCountsService
{
    /**
     * @return array<string, array<string, int>>
     */
    public function forApplication(): array
    {
        return [
            'receivables' => [
                'invoices' => $this->count(FinInvoice::class),
                'quotes' => $this->count(Quote::class),
                'recurring-charges' => $this->count(RecurringCharge::class),
                'billing' => $this->count(BillingEntry::class),
                'price-books' => $this->count(PriceBook::class),
                'allocations' => $this->count(FinPaymentAllocation::class),
            ],
            'payables' => [
                'bills' => $this->count(FinBill::class),
                'purchase-orders' => $this->count(FinPurchaseOrder::class),
                'vendors' => $this->count(FinVendor::class),
                'credit-notes' => $this->count(FinCreditNote::class),
                'payment-runs' => $this->count(FinPaymentRun::class),
            ],
            'banking' => [
                'accounts' => $this->count(FinBankAccount::class),
                'transactions' => $this->count(FinBankTransaction::class),
                'petty-cash' => $this->count(FinPettyCashFund::class),
                'match-rules' => $this->count(FinMatchRule::class),
            ],
            'ledger' => [
                'accounts' => $this->count(FinAccount::class),
                'journals' => $this->count(FinJournal::class),
                'cost-centres' => $this->count(FinCostCentre::class),
                'fiscal-periods' => $this->count(FinFiscalPeriod::class),
                'fixed-assets' => $this->count(FinFixedAsset::class),
            ],
            'tax' => [
                'gst-returns' => $this->count(FinGstReturn::class),
                'ird-filings' => $this->count(FinIrdFiling::class),
                'audit-exports' => $this->count(FinAuditExport::class),
            ],
        ];
    }

    /**
     * Application-wide row count for a model, guarded so one bad table never breaks
     * the finance chrome.
     *
     * @param  class-string<Model>  $model
     */
    protected function count(string $model): int
    {
        try {
            return (int) $model::query()->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
