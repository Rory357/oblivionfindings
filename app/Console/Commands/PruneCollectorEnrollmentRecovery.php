<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\CollectorEnrollmentRecoveryPruner;
use Illuminate\Console\Command;

final class PruneCollectorEnrollmentRecovery extends Command
{
    protected $signature = 'monitoring:prune-enrolment-recovery
        {--batch-size=100 : Rows per batch (1-1000)}
        {--max-batches=10 : Maximum batches per run (1-100)}';

    protected $description = 'Erase expired sealed collector enrolment responses while preserving enrolment history';

    public function handle(CollectorEnrollmentRecoveryPruner $pruner): int
    {
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $maxBatches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($batchSize === false || $maxBatches === false) {
            $this->error('Batch size must be 1-1000 and max batches must be 1-100.');

            return self::INVALID;
        }
        $this->info('Expired collector recovery responses erased: '.$pruner->prune($batchSize, $maxBatches));

        return self::SUCCESS;
    }
}
