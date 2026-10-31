<?php

namespace App\Jobs;

use App\Services\HealthSafety\ProcedureReviewNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchProcedureReviewDelivery implements ShouldQueue
{
    use Queueable;

    // The retained intent and scheduled recovery own retry timing.
    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(ProcedureReviewNotifications $notifications): void
    {
        $notifications->deliver($this->deliveryId);
    }
}
