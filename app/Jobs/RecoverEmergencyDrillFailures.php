<?php

namespace App\Jobs;

use App\Services\HealthSafety\EmergencyDrillFailureDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverEmergencyDrillFailures implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(EmergencyDrillFailureDeliveryService $delivery): void
    {
        $delivery->recoverPending();
    }
}
