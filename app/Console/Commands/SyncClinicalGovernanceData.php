<?php

namespace App\Console\Commands;

use App\Domain\Governance\Services\ClinicalGovernanceAutomationService;
use Illuminate\Console\Command;

class SyncClinicalGovernanceData extends Command
{
    protected $signature = 'governance:sync-clinical-data';

    protected $description = 'Sync automated clinical governance indicators from Health & Clinical data';

    public function handle(ClinicalGovernanceAutomationService $automationService): int
    {
        $snapshot = $automationService->syncCurrentSnapshot();

        $this->info(sprintf(
            'Clinical governance snapshot synced for %s to %s (snapshot #%d).',
            $snapshot->period_start?->toDateString(),
            $snapshot->period_end?->toDateString(),
            $snapshot->id,
        ));

        return self::SUCCESS;
    }
}
