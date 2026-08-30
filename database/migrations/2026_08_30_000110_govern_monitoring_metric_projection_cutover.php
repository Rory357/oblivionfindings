<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER = 'monitoring_metric_current_summaries_bi_receipt';

    private const UPDATE_TRIGGER = 'monitoring_metric_current_summaries_bu_receipt';

    private const LEGACY_TIME_INDEX = 'monitoring_metric_point_receipts_series_observed_idx';

    private const UNIQUE_TIME_INDEX = 'monitoring_metric_point_receipts_series_observed_uq';

    public function up(): void
    {
        if (! Schema::hasTable('monitoring_metric_point_receipts')
            || ! Schema::hasTable('monitoring_metric_current_summaries')) {
            throw new RuntimeException('Metric projection cutover requires the durable receipt schema.');
        }

        $insertTriggerExists = $this->triggerExists(self::INSERT_TRIGGER);
        $updateTriggerExists = $this->triggerExists(self::UPDATE_TRIGGER);
        if ($insertTriggerExists !== $updateTriggerExists) {
            throw new RuntimeException('Metric projection receipt bridge is only partially installed.');
        }

        if (! $insertTriggerExists) {
            $baseMigration = require __DIR__
                .'/2026_08_30_000100_govern_monitoring_metric_projection_replays.php';
            $baseMigration->installCurrentSummaryReceiptBridge();
        }

        $this->ensureUniqueReceiptTimeIndex();
        $this->reconcileLegacySummaryKeys();

        // A pending row from before the bridge may represent either a failed
        // projection or an external success whose exact event time is no
        // longer reconstructable. Leave the bridge installed, but require an
        // explicit external-outcome reconciliation before automatic replay.
        if (DB::table('monitor_observations')->whereNull('metrics_projected_at')->exists()) {
            throw new RuntimeException(
                'Pending metric projections predate the receipt bridge and require explicit reconciliation.',
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The metric projection cutover is forward-only and requires a writer-quiesced recovery procedure.',
        );
    }

    private function reconcileLegacySummaryKeys(): void
    {
        $seriesMismatchExists = DB::table('monitoring_metric_current_summaries as summaries')
            ->join(
                'monitoring_metric_point_receipts as receipts',
                'receipts.idempotency_key',
                '=',
                'summaries.last_idempotency_key',
            )
            ->whereColumn('receipts.series_id', '!=', 'summaries.series_id')
            ->exists();
        if ($seriesMismatchExists) {
            throw new RuntimeException(
                'Legacy metric summary receipt series evidence is inconsistent.',
            );
        }

        DB::statement(<<<'SQL'
            UPDATE monitoring_metric_current_summaries AS summaries
            INNER JOIN monitoring_metric_point_receipts AS receipts
                ON receipts.idempotency_key = summaries.last_idempotency_key
               AND receipts.series_id = summaries.series_id
            SET
                summaries.last_idempotency_key = NULL,
                summaries.updated_at = CURRENT_TIMESTAMP
            WHERE NOT (receipts.observed_at <=> summaries.observed_at)
        SQL);
    }

    private function ensureUniqueReceiptTimeIndex(): void
    {
        $duplicateTimeExists = DB::table('monitoring_metric_point_receipts')
            ->select(['series_id', 'observed_at'])
            ->groupBy(['series_id', 'observed_at'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateTimeExists) {
            throw new RuntimeException(
                'Metric receipt times are ambiguous and require explicit reconciliation.',
            );
        }

        if ($this->indexExists(self::UNIQUE_TIME_INDEX)) {
            return;
        }

        if ($this->indexExists(self::LEGACY_TIME_INDEX)) {
            Schema::table('monitoring_metric_point_receipts', function (Blueprint $table): void {
                $table->dropIndex(self::LEGACY_TIME_INDEX);
            });
        }

        Schema::table('monitoring_metric_point_receipts', function (Blueprint $table): void {
            $table->unique(
                ['series_id', 'observed_at'],
                self::UNIQUE_TIME_INDEX,
            );
        });
    }

    private function triggerExists(string $trigger): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', $trigger)
            ->exists();
    }

    private function indexExists(string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'monitoring_metric_point_receipts')
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
