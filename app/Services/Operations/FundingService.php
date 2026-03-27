<?php

namespace App\Services\Operations;

use App\Models\FundingClaim;
use App\Models\FundingClaimItem;
use App\Models\BillingEntry;
use App\Models\ServiceAgreement;

class FundingService
{
    public function generateClaimFromBilling(ServiceAgreement $agreement, string $periodStart, string $periodEnd, int $submittedBy): FundingClaim
    {
        $entries = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereBetween('service_date', [$periodStart, $periodEnd])
            ->whereIn('status', ['pending', 'invoiced'])
            ->get();

        $totalAmount = $entries->sum('amount');

        $claim = FundingClaim::create([
            'service_agreement_id' => $agreement->id,
            'organization_id' => $agreement->organization_id,
            'reference' => $this->generateClaimReference($agreement->organization_id),
            'amount' => $totalAmount,
            'status' => 'draft',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'submitted_by' => $submittedBy,
        ]);

        foreach ($entries as $entry) {
            FundingClaimItem::create([
                'funding_claim_id' => $claim->id,
                'billing_entry_id' => $entry->id,
                'description' => sprintf('%s — %s hrs', $entry->service_date->format('d M Y'), $entry->hours),
                'amount' => $entry->amount,
            ]);
        }

        return $claim;
    }

    public function approveClaim(FundingClaim $claim, int $approvedBy): void
    {
        $claim->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $approvedBy,
        ]);

        // Update agreement budget usage
        $agreement = $claim->serviceAgreement;
        if ($agreement) {
            app(BillingService::class)->updateAgreementUsage($agreement);
        }
    }

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

    protected function generateClaimReference(int $organizationId): string
    {
        $count = FundingClaim::where('organization_id', $organizationId)->count();
        return 'FC-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);
    }
}
