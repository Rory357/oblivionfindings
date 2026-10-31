<?php

namespace App\Jobs;

use App\Services\HealthSafety\FirstAidDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverFirstAidDeliveries implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(FirstAidDeliveryService $delivery): void
    {
        $delivery->recoverPending();
    }
}
