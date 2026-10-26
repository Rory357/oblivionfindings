<?php

namespace App\Jobs;

use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchWorkerParticipationMeetingDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(WorkerParticipationMeetingDeliveryService $delivery): void
    {
        $delivery->deliver($this->deliveryId);
    }
}
