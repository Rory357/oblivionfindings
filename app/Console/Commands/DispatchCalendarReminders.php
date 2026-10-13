<?php

namespace App\Console\Commands;

use App\Domain\Hr\Services\HrCalendarReminderDelivery;
use Illuminate\Console\Command;

class DispatchCalendarReminders extends Command
{
    protected $signature = 'hr:dispatch-calendar-reminders';

    protected $description = 'Prepare and deliver due HR calendar reminders, retrying interrupted delivery.';

    public function handle(HrCalendarReminderDelivery $delivery): int
    {
        $now = now();
        $prepared = $delivery->stage($now);
        $sent = $delivery->deliver($now);
        $this->info("Prepared {$prepared}; delivered {$sent} calendar reminder(s).");

        return self::SUCCESS;
    }
}
