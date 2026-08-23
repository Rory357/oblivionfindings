<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\FundingClaimJournalService;
use App\Models\FundingClaim;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostFundingClaimJournalJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $claimId,
    ) {}

    public function uniqueId(): string
    {
        return 'funding-claim-journal:'.$this->claimId;
    }

    public function handle(FundingClaimJournalService $service): void
    {
        $claim = FundingClaim::query()->findOrFail($this->claimId);

        if ($claim->journal_id !== null) {
            $service->postFundingClaimJournal($claim);

            return;
        }

        if (! in_array($claim->status, ['submitted', 'approved'], true)) {
            return;
        }

        $journal = $service->postFundingClaimJournal($claim);

        Log::info("Posted funding claim #{$claim->id} ({$claim->claim_reference}) to journal {$journal->journal_number}.");
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $claim = FundingClaim::query()
                ->whereKey($this->claimId)
                ->whereNull('journal_id')
                ->lockForUpdate()
                ->first();
            if (! $claim) {
                return;
            }

            $claim->forceFill([
                'gl_posting_status' => 'failed',
                'gl_posting_error' => mb_substr($exception?->getMessage() ?? 'Funding Claim journal posting failed.', 0, 2000),
            ])->saveQuietly();

            AuditLogger::logOrFail('funding.claim.gl.exhausted', $claim, [
                'actor_id' => (int) ($claim->submitted_by ?: $claim->created_by),
                'client_id' => $claim->client_id,
                'site_id' => $claim->site_id,
                'service_agreement_id' => $claim->service_agreement_id,
                'provenance_digest' => $claim->provenance_digest,
                'gl_posting_attempts' => $claim->gl_posting_attempts,
                'failure_type' => $exception ? class_basename($exception) : 'Throwable',
            ]);
        }, 3);
    }
}
