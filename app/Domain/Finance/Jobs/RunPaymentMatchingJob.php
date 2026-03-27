<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\PaymentMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunPaymentMatchingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $orgId,
    ) {}

    public function handle(PaymentMatchingService $service): void
    {
        $results = $service->matchUnmatchedTransactions($this->orgId);

        Log::info("Payment matching completed for organisation #{$this->orgId}.", [
            'matched' => $results['matched'],
            'auto_confirmed' => $results['auto_confirmed'],
            'suggested' => $results['suggested'],
        ]);
    }
}
