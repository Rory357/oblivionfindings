<?php

namespace App\Jobs;

use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchRestraintDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(RestraintSourceDeliveryService $delivery): void
    {
        $delivery->deliver($this->deliveryId);
    }
}
