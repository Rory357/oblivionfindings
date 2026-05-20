<?php

namespace App\Domain\Governance\Console;

use App\Domain\Governance\Services\RecurringMeetingService;
use Illuminate\Console\Command;

/**
 * Spawns upcoming GovernanceMeeting rows from active RecurringMeetingSchedule
 * templates. Scheduled to run weekly.
 */
class SpawnRecurringMeetingsCommand extends Command
{
    protected $signature = 'governance:spawn-recurring-meetings
        {--months=3 : How many months ahead to materialise}';

    protected $description = 'Generate GovernanceMeeting rows from active RecurringMeetingSchedule templates';

    public function handle(RecurringMeetingService $service): int
    {
        $months = (int) $this->option('months');
        if ($months < 1 || $months > 12) {
            $months = 3;
        }

        $count = $service->generateUpcoming($months);

        $this->info("Spawned {$count} governance meeting(s) for the next {$months} month(s).");

        return self::SUCCESS;
    }
}
