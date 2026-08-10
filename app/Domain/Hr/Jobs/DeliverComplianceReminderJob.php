<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverComplianceReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 600];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(HrComplianceReminderDeliveryService $deliveries): void
    {
        try {
            $deliveries->deliver($this->deliveryId);
        } catch (Throwable $exception) {
            $deliveries->recordFailure($this->deliveryId, $exception);

            throw $exception;
        }
    }
}
