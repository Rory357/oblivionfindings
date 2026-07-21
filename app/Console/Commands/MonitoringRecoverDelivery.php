<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\MonitoringDeliveryRecoveryService;
use Illuminate\Console\Command;

final class MonitoringRecoverDelivery extends Command
{
    protected $signature = 'monitoring:recover-delivery';

    protected $description = 'Recover expired monitoring outbox and replay dispatch leases';

    public function handle(MonitoringDeliveryRecoveryService $recovery): int
    {
        $claimed = $recovery->recover();
        $this->info("Monitoring delivery recovery claimed {$claimed['outbox']} outbox and {$claimed['replay']} replay item(s).");

        return self::SUCCESS;
    }
}
