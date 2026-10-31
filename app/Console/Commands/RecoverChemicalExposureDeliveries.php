<?php

namespace App\Console\Commands;

use App\Services\HealthSafety\ChemicalExposureSystemDeliveryService;
use Illuminate\Console\Command;

/** Every minute: retry exposure H&S deliveries that are due, and any the inline delivery never reached (job 3). */
class RecoverChemicalExposureDeliveries extends Command
{
    protected $signature = 'chemical-exposures:recover-deliveries {--limit=30}';

    protected $description = 'Deliver saved chemical exposures to the H&S register when an automatic delivery is due for a retry.';

    public function handle(ChemicalExposureSystemDeliveryService $delivery): int
    {
        $outcomes = $delivery->recoverDue(max(1, min(200, (int) $this->option('limit'))));
        $counts = array_count_values($outcomes);
        ksort($counts);
        $summary = $counts === [] ? 'none due' : implode(', ', array_map(fn ($state, $n) => "{$n} {$state}", array_keys($counts), $counts));
        $this->info("Exposure H&S deliveries: {$summary}.");

        return self::SUCCESS;
    }
}
