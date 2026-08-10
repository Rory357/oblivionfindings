<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Services\DeviceDocumentLifecycleService;
use Illuminate\Console\Command;

final class ReconcileDeviceDocumentStorage extends Command
{
    protected $signature = 'security-devices:reconcile-document-storage
        {--limit=100 : Maximum governed document rows to reconcile in this run}';

    protected $description = 'Recover staged uploads, quarantined removals, and legacy Device document integrity evidence';

    public function handle(DeviceDocumentLifecycleService $lifecycle): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $result = $lifecycle->reconcileAll($limit);

        $this->components->info(sprintf(
            'Device document storage: %d processed, %d recovered, %d pending, %d orphan stages removed.',
            $result['processed'],
            $result['recovered'],
            $result['pending'],
            $result['orphan_stages_removed'],
        ));

        return $result['pending'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
