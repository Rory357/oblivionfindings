<?php

namespace App\Console\Commands;

use App\Services\HealthSafety\FirstAidDeliveryService;
use Illuminate\Console\Command;

/** Stage a deduplicated NZ-day digest; delivery independently rechecks actual current ownership and source access. */
class FirstAidFollowupReminders extends Command
{
    protected $signature = 'first-aid:followup-reminders {--window=0}';

    protected $description = 'Stage authorized first-aid follow-up reminders for durable local delivery.';

    public function handle(FirstAidDeliveryService $delivery): int
    {
        $staged = $delivery->stageDueReminders(max(0, (int) $this->option('window')));
        $this->info("First aid follow-up reminders: {$staged} new digest intent(s) recorded; delivery is tracked separately.");

        return self::SUCCESS;
    }
}
