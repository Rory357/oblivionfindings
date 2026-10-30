<?php

namespace App\Console\Commands;

use App\Services\Fleet\VehicleObligationReminderService;
use Illuminate\Console\Command;

class FleetDeliverObligationReminders extends Command
{
    protected $signature = 'fleet:deliver-obligation-reminders';

    protected $description = 'Tell vehicle obligation owners about service, compliance and vehicle check due points within their lead time.';

    public function handle(VehicleObligationReminderService $reminders): int
    {
        $counts = $reminders->deliverDue();
        $this->info("Delivered {$counts['sent']} vehicle obligation reminders; {$counts['failed']} need attention.");

        return self::SUCCESS;
    }
}
