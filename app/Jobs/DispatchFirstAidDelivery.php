<?php

namespace App\Jobs;

use App\Services\HealthSafety\FirstAidDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchFirstAidDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(FirstAidDeliveryService $delivery): void
    {
        $delivery->deliver($this->deliveryId);
    }
}
