<?php

namespace App\Console\Commands;

use App\Domain\Governance\Services\ComplianceEngineService;
use Illuminate\Console\Command;

class ProcessComplianceReminders extends Command
{
    protected $signature = 'governance:compliance-reminders';
    protected $description = 'Process due compliance reminders and send notifications';

    public function handle(ComplianceEngineService $service): int
    {
        $this->info('Processing compliance reminders...');

        $count = $service->processDueReminders();

        $this->info("Sent {$count} compliance reminders.");

        return self::SUCCESS;
    }
}
