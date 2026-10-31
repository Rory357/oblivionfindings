<?php

namespace App\Console\Commands;

use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Console\Command;

class RecoverRestraintDeliveries extends Command
{
    protected $signature = 'restraints:recover-deliveries {--limit=30}';

    protected $description = 'Retry durable restraint source deliveries with fresh exact source and audience authority.';

    public function handle(RestraintSourceDeliveryService $delivery): int
    {
        $count = $delivery->recoverPending((int) $this->option('limit'));
        $this->info("{$count} retained intent(s) attempted; inspect actual delivery state separately.");

        return self::SUCCESS;
    }
}
