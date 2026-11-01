<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use Illuminate\Console\Command;

final class PruneSensorReadings extends Command
{
    protected $signature = 'security-devices:prune-readings';

    protected $description = 'Delete sensor readings older than 13 months';

    public function handle(SensorReadingHistory $history): int
    {
        $total = 0;
        do {
            $deleted = $history->prune();
            $total += $deleted;
        } while ($deleted > 0 && $total < 1_000_000);
        $this->info("Sensor readings deleted: {$total}");

        return self::SUCCESS;
    }
}
