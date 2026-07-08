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
use App\Models\BillingEntry;
use App\Models\PriceBook;
use App\Models\Quote;
use App\Models\RecurringCharge;
use Illuminate\Database\Eloquent\Model;

/**
 * Row counts for the finance hub tab strips — the count badge next to each tab.
 *
 * Shared once per finance request by {@see \App\Http\Middleware\HandleInertiaRequests}
 * as `financeHubCounts`, keyed hub → tab id → count; every `*TabsFooter` reads its own
 * hub's slice and sets `badge` per tab. Only LIST tabs are counted — report / dashboard
 * / workspace tabs (aged-AR, statements, reconciliation, matching, consolidation, and the
 * Overview / Reports / Settings hubs) have no list to size, so they carry no badge.
 *
 * A count is the total rows in that list (all statuses), org-scoped — i.e. exactly how
 * many rows the tab's list holds. Zero is returned as 0; the footer omits the badge for 0
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
    public function forOrganization(?int $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        return [
            'receivables' => [
                'invoices' => $this->count(FinInvoice::class, $organizationId),
                'quotes' => $this->count(Quote::class, $organizationId),
                'recurring-charges' => $this->count(RecurringCharge::class, $organizationId),
                'billing' => $this->count(BillingEntry::class, $organizationId),
                'price-books' => $this->count(PriceBook::class, $organizationId),
                'allocations' => $this->count(FinPaymentAllocation::class, $organizationId),
            ],
            'payables' => [
                'bills' => $this->count(FinBill::class, $organizationId),
                'purchase-orders' => $this->count(FinPurchaseOrder::class, $organizationId),
                'vendors' => $this->count(FinVendor::class, $organizationId),
                'credit-notes' => $this->count(FinCreditNote::class, $organizationId),
                'payment-runs' => $this->count(FinPaymentRun::class, $organizationId),
            ],
            'banking' => [
                'accounts' => $this->count(FinBankAccount::class, $organizationId),
                'transactions' => $this->count(FinBankTransaction::class, $organizationId),
                'petty-cash' => $this->count(FinPettyCashFund::class, $organizationId),
                'match-rules' => $this->count(FinMatchRule::class, $organizationId),
            ],
            'ledger' => [
                'accounts' => $this->count(FinAccount::class, $organizationId),
                'journals' => $this->count(FinJournal::class, $organizationId),
                'cost-centres' => $this->count(FinCostCentre::class, $organizationId),
                'fiscal-periods' => $this->count(FinFiscalPeriod::class, $organizationId),
                'fixed-assets' => $this->count(FinFixedAsset::class, $organizationId),
            ],
            'tax' => [
                'gst-returns' => $this->count(FinGstReturn::class, $organizationId),
                'ird-filings' => $this->count(FinIrdFiling::class, $organizationId),
                'audit-exports' => $this->count(FinAuditExport::class, $organizationId),
            ],
        ];
    }

    /**
     * Org-scoped row count for a model, guarded so one bad table never breaks the
     * finance chrome. The `organization_id` column is qualified (table-prefixed) to
     * dodge the ambiguous-column trap that any global scope join could reintroduce —
     * the same class of bug that once 500'd the P&L report.
     *
     * @param  class-string<Model>  $model
     */
    protected function count(string $model, int $organizationId): int
    {
        try {
            /** @var Model $instance */
            $instance = new $model;

            return (int) $model::query()
                ->where($instance->qualifyColumn('organization_id'), $organizationId)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
