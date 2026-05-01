<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinBankAccount;
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
        public ?int $orgId = null,
    ) {}

    public function handle(PaymentMatchingService $service): void
    {
        $orgIds = $this->orgId !== null
            ? collect([$this->orgId])
            : FinBankAccount::active()
                ->distinct()
                ->pluck('organization_id')
                ->filter();

        foreach ($orgIds as $orgId) {
            $results = $service->matchUnmatchedTransactions((int) $orgId);

            Log::info("Payment matching completed for organisation #{$orgId}.", [
                'matched' => $results['matched'],
                'auto_confirmed' => $results['auto_confirmed'],
                'suggested' => $results['suggested'],
            ]);
        }
    }
}
