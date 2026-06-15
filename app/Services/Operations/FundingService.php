<?php

namespace App\Services\Operations;

use App\Models\ServiceAgreement;

/**
 * Service-agreement funding helpers used by the operations layer.
 *
 * NOTE (gap 1.3): the previous `generateClaimFromBilling()` / `approveClaim()`
 * methods were stale, broken (they wrote non-fillable keys — `reference`/`amount`
 * instead of `claim_reference`/`total_amount`, and a non-existent
 * `FundingClaimItem.billing_entry_id`) and had ZERO callers. Funding claims are
 * created and approved through `FundingClaimController` + `FundingClaimJournalService`,
 * so the dead methods were removed rather than left as a latent corruption risk.
 * Only the live, wired alert helpers (used by CheckExpiringAgreementsJob) remain.
 */
class FundingService
{
    public function getExpiringAgreements(int $organizationId, int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceAgreement::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addDays($days))
            ->where('ends_at', '>=', now())
            ->with(['client:id,first_name,last_name'])
            ->orderBy('ends_at')
            ->get();
    }

    public function getBudgetAlerts(int $organizationId, float $thresholdPercent = 80): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceAgreement::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('total_budget', '>', 0)
            ->whereRaw('(budget_used / total_budget) * 100 >= ?', [$thresholdPercent])
            ->with(['client:id,first_name,last_name'])
            ->get();
    }
}
