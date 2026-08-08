<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'monitoring_metric_rollup_coverages';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_series_id');
            $table->foreignId('target_series_id');
            $table->string('target_tier', 16);
            $table->timestamp('covered_from', 6);
            $table->timestamp('covered_until', 6);
            $table->timestamp('completed_at', 6);
            $table->timestamps();

            $table->foreign('source_series_id', 'monitor_rollup_cov_source_fk')
                ->references('id')->on('monitoring_metric_series')->restrictOnDelete();
            $table->foreign('target_series_id', 'monitor_rollup_cov_target_fk')
                ->references('id')->on('monitoring_metric_series')->restrictOnDelete();
            $table->unique(
                ['source_series_id', 'target_tier'],
                'monitor_rollup_cov_source_tier_uq',
            );
            $table->index(
                ['target_series_id', 'covered_until'],
                'monitor_rollup_cov_target_until_idx',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE monitoring_metric_rollup_coverages
            ADD CONSTRAINT monitor_rollup_cov_tier_chk
            CHECK (target_tier IN ('hourly', 'daily'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE monitoring_metric_rollup_coverages
            ADD CONSTRAINT monitor_rollup_cov_bounds_chk
            CHECK (covered_from < covered_until)
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'Cannot remove durable metric roll-up coverage after retention watermarks exist.',
            );
        }

        Schema::dropIfExists(self::TABLE);
    }
};
