<?php

namespace App\Jobs;

use App\Services\HealthSafety\ProcedureReviewNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverProcedureReviewDeliveries implements ShouldQueue
{
    use Queueable;

    public function handle(ProcedureReviewNotifications $notifications): void
    {
        $notifications->recoverPending();
    }
}
