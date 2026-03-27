<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\GstReturnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateGstReturnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orgId,
        public string $periodStart,
        public string $periodEnd,
        public string $frequency,
        public string $basis,
    ) {}

    public function handle(GstReturnService $service): void
    {
        $gstReturn = $service->prepareReturn($this->orgId, [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'filing_frequency' => $this->frequency,
            'basis' => $this->basis,
        ]);

        Log::info("GST return prepared for organisation #{$this->orgId}, period {$this->periodStart} to {$this->periodEnd}.", [
            'gst_return_id' => $gstReturn->id,
            'gst_payable' => $gstReturn->gst_payable,
        ]);
    }
}
