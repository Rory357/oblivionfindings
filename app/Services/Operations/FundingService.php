<?php

namespace App\Services\Operations;

use App\Models\ServiceAgreement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service-agreement funding helpers used by the operations layer.
 *
 * NOTE (gap 1.3): the previous `generateClaimFromBilling()` / `approveClaim()`
 * methods were stale, broken (they wrote non-fillable keys — `reference`/`amount`
 * instead of `claim_reference`/`total_amount`) and had ZERO callers. Funding claims are
 * created and approved through `FundingClaimController` + `FundingClaimJournalService`,
 * so the dead methods were removed rather than left as a latent corruption risk.
 * Only the live, wired alert helpers (used by CheckExpiringAgreementsJob) remain.
 */
class FundingService
{
    public function getExpiringAgreements(int $days = 30): Collection
    {
        return ServiceAgreement::query()
            ->whereHas('client', fn ($clientQuery) => $clientQuery
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDays($days))
            ->where('ends_at', '>=', now())
            ->with(['client:id,first_name,last_name'])
            ->orderBy('ends_at')
            ->get();
    }

    public function getBudgetAlerts(float $thresholdPercent = 80): Collection
    {
        return ServiceAgreement::query()
            ->whereHas('client', fn ($clientQuery) => $clientQuery
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->where('status', 'active')
            ->where('total_budget', '>', 0)
            ->whereRaw('(budget_used / total_budget) * 100 >= ?', [$thresholdPercent])
            ->with(['client:id,first_name,last_name'])
            ->get();
    }
}
