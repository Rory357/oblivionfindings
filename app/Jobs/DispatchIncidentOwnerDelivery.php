<?php

namespace App\Jobs;

use App\Services\Incidents\IncidentOwnerNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchIncidentOwnerDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $deliveryId) {}

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(IncidentOwnerNotifications $notifications): void
    {
        $notifications->deliver($this->deliveryId);
    }
}
