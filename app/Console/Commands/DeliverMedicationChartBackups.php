<?php

namespace App\Console\Commands;

use App\Services\Medication\BackupDelivery\BackupDeliveryService;
use Illuminate\Console\Command;

class DeliverMedicationChartBackups extends Command
{
    protected $signature = 'medications:chart-backups';

    protected $description = 'Prepare due NZ chart backups and deliver only through an explicitly enabled transport';

    public function handle(BackupDeliveryService $backups): int
    {
        $results = $backups->dispatchDue();
        $this->line(json_encode($results, JSON_THROW_ON_ERROR));

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
