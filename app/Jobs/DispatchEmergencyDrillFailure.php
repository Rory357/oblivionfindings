<?php

namespace App\Jobs;

use App\Services\HealthSafety\EmergencyDrillFailureDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchEmergencyDrillFailure implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(EmergencyDrillFailureDeliveryService $delivery): void
    {
        $delivery->deliver($this->deliveryId);
    }
}
