<?php

namespace App\Jobs;

use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecoverWorkerParticipationMeetingDeliveries implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(WorkerParticipationMeetingDeliveryService $delivery): void
    {
        $delivery->recoverPending();
    }
}
