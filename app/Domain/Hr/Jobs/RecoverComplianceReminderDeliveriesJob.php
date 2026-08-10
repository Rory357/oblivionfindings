<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecoverComplianceReminderDeliveriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'hr-compliance-reminder-delivery-recovery';
    }

    public function handle(HrComplianceReminderDeliveryService $deliveries): void
    {
        $deliveries->recoverPending();
    }
}
