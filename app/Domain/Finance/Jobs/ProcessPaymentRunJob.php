<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\PaymentRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public FinPaymentRun $paymentRun,
        public int $userId,
    ) {}

    public function handle(PaymentRunService $service): void
    {
        try {
            $service->processPaymentRun($this->paymentRun, $this->userId);

            Log::info("Payment run {$this->paymentRun->run_number} processed successfully.");
        } catch (\Exception $e) {
            Log::error("Failed to process payment run {$this->paymentRun->run_number}: {$e->getMessage()}");

            $this->paymentRun->update(['status' => 'failed']);

            throw $e;
        }
    }
}
