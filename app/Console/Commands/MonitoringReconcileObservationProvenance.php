<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\MonitoringObservationProvenanceReconciler;
use Illuminate\Console\Command;

final class MonitoringReconcileObservationProvenance extends Command
{
    protected $signature = 'monitoring:reconcile-observation-provenance
        {--chunk=500 : Rows inspected per chunk}';

    protected $description = 'Reconcile and verify Monitoring observation provenance after the expand deployment';

    public function handle(MonitoringObservationProvenanceReconciler $reconciler): int
    {
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 5000) {
            $this->error('--chunk must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        $counts = $reconciler->reconcile($chunkSize);
        $this->table(['Metric', 'Count'], collect($counts)
            ->except('schema_ready')
            ->map(fn (int $count, string $metric): array => [$metric, $count])
            ->values()
            ->all());

        if (! $counts['schema_ready']) {
            $this->error('Observation provenance columns are not installed.');

            return self::FAILURE;
        }

        if ($counts['partial'] > 0
            || $counts['contradictory'] > 0
            || $counts['unresolved'] > 0
            || $counts['missing'] > 0) {
            $this->error('Observation provenance reconciliation did not reach zero gaps.');

            return self::FAILURE;
        }

        $this->info('Observation provenance reconciliation reached zero gaps.');

        return self::SUCCESS;
    }
}
