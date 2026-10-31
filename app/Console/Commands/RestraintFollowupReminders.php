<?php

namespace App\Console\Commands;

use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Console\Command;

class RestraintFollowupReminders extends Command
{
    protected $signature = 'restraints:followup-reminders {--window=0}';

    protected $description = 'Stage current-authorised named review and person-centred follow-up reminders by NZ day.';

    public function handle(RestraintSourceDeliveryService $delivery): int
    {
        $count = $delivery->stageDueReminders(max(0, (int) $this->option('window')));
        $this->info("{$count} new reminder intent(s) recorded; delivery is tracked separately.");

        return self::SUCCESS;
    }
}
