<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\BankFeedService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBankFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $orgId = null,
    ) {}

    public function handle(BankFeedService $service): void
    {
        $logs = $service->syncAllActive($this->orgId);

        $successful = collect($logs)->where('status', '!=', 'failed')->count();
        $failed = collect($logs)->where('status', 'failed')->count();

        Log::info('Bank feeds sync job completed.', [
            'organization_id' => $this->orgId,
            'total_feeds' => count($logs),
            'successful' => $successful,
            'failed' => $failed,
        ]);
    }
}
