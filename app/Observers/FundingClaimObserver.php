<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\PostFundingClaimJournalJob;
use App\Models\FundingClaim;
use Illuminate\Support\Facades\Log;

class FundingClaimObserver
{
    public function created(FundingClaim $claim): void
    {
        $this->dispatchIfReady($claim);
    }

    public function updated(FundingClaim $claim): void
    {
        if (! $claim->wasChanged('status')) {
            return;
        }

        $this->dispatchIfReady($claim);
    }

    private function dispatchIfReady(FundingClaim $claim): void
    {
        if (! in_array($claim->status, ['submitted', 'approved'], true)) {
            return;
        }

        if ($claim->journal_id !== null) {
            return;
        }

        $hasCanonicalOwnership = FundingClaim::query()
            ->whereKey($claim->id)
            ->whereHas('client', fn ($clientQuery) => $clientQuery
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->whereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
            ->exists();

        if (! $hasCanonicalOwnership) {
            return;
        }

        try {
            PostFundingClaimJournalJob::dispatch($claim);
        } catch (\Throwable $e) {
            Log::error("FundingClaimObserver: Failed to dispatch GL job for claim #{$claim->id}: {$e->getMessage()}");
        }
    }
}
