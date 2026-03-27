<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\FundingClaimJournalService;
use App\Models\FundingClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostFundingClaimJournalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly FundingClaim $claim,
    ) {}

    public function handle(FundingClaimJournalService $service): void
    {
        $journal = $service->postFundingClaimJournal($this->claim);

        Log::info("Posted funding claim #{$this->claim->id} ({$this->claim->claim_reference}) to journal {$journal->journal_number}.");
    }
}
