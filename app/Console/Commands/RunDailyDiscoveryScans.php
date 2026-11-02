<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Discovery\Services\DailyDiscoveryScheduler;
use Illuminate\Console\Command;

final class RunDailyDiscoveryScans extends Command
{
    protected $signature = 'monitoring:run-daily-discovery';

    protected $description = 'Start the daily network scan for each hub scan area whose time has come today';

    public function handle(DailyDiscoveryScheduler $scheduler): int
    {
        $this->info('Daily network scans started: '.$scheduler->startDue());

        return self::SUCCESS;
    }
}
