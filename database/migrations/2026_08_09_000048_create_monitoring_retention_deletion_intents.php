<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_retention_deletion_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('intent_uuid')->unique();
            $table->string('job_reference', 128);
            $table->foreignId('series_id');
            $table->foreignId('site_id');
            $table->foreignId('device_id');
            $table->foreignId('monitor_id')->nullable();
            $table->foreignId('policy_id')->nullable();
            $table->unsignedInteger('policy_version');
            $table->string('policy_scope_kind', 32);
            $table->char('policy_identity_key', 64);
            $table->unsignedInteger('retention_days');
            $table->string('data_class', 32);
            $table->string('retention_tier', 16);
            $table->timestamp('period_start', 6);
            $table->timestamp('period_end', 6);
            $table->unsignedInteger('occupied_bucket_count');
            $table->char('rollup_evidence_sha256', 64);
            $table->string('state', 32)->default('pending');
            $table->timestamp('delete_acknowledged_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps(6);

            $table->foreign('series_id', 'monitoring_retention_intent_series_fk')
                ->references('id')->on('monitoring_metric_series')->restrictOnDelete();
            $table->foreign('site_id', 'monitoring_retention_intent_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_retention_intent_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('monitor_id', 'monitoring_retention_intent_monitor_fk')
                ->references('id')->on('monitors')->restrictOnDelete();
            $table->foreign('policy_id', 'monitoring_retention_intent_policy_fk')
                ->references('id')->on('monitoring_retention_policies')->nullOnDelete();
            $table->unique(
                ['series_id', 'retention_tier', 'period_start', 'period_end'],
                'monitoring_retention_intent_range_unique',
            );
            $table->index(['state', 'id'], 'monitoring_retention_intent_state_idx');
            $table->index(['job_reference', 'state'], 'monitoring_retention_intent_job_idx');
        });

        Schema::table('monitoring_retention_tombstones', function (Blueprint $table): void {
            $table->foreignId('deletion_intent_id')->nullable()->after('snapshot_id');
            $table->foreign('deletion_intent_id', 'monitoring_tombstone_deletion_intent_fk')
                ->references('id')->on('monitoring_retention_deletion_intents')->restrictOnDelete();
            $table->unique('deletion_intent_id', 'monitoring_tombstone_deletion_intent_unique');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_retention_tombstones', function (Blueprint $table): void {
            $table->dropUnique('monitoring_tombstone_deletion_intent_unique');
            $table->dropForeign('monitoring_tombstone_deletion_intent_fk');
            $table->dropColumn('deletion_intent_id');
        });
        Schema::dropIfExists('monitoring_retention_deletion_intents');
    }
};
