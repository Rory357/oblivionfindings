<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_metric_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->foreignId('device_id');
            $table->foreignId('monitor_id')->nullable();
            $table->string('metric', 128);
            $table->json('dimensions');
            $table->char('dimensions_hash', 64);
            $table->string('unit', 32);
            $table->string('source', 64);
            $table->string('data_class', 64)->default('operational');
            $table->string('privacy_class', 32)->default('standard');
            $table->string('retention_tier', 16)->default('raw');
            $table->string('external_key', 128)->unique();
            $table->timestamp('first_point_at', 6)->nullable();
            $table->timestamp('last_point_at', 6)->nullable();
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_metric_series_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_metric_series_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('monitor_id', 'monitoring_metric_series_monitor_fk')
                ->references('id')->on('monitors')->nullOnDelete();
            $table->index(
                ['site_id', 'device_id', 'metric', 'retention_tier'],
                'monitoring_metric_series_scope_idx',
            );
            $table->index(
                ['monitor_id', 'metric', 'dimensions_hash'],
                'monitoring_metric_series_identity_idx',
            );
        });

        Schema::create('monitoring_metric_current_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->unique();
            $table->decimal('value', 30, 6)->nullable();
            $table->json('statistics')->nullable();
            $table->unsignedBigInteger('sample_count')->default(0);
            $table->timestamp('observed_at', 6)->nullable();
            $table->string('last_idempotency_key', 64)->nullable();
            $table->string('storage_state', 16)->default('unknown');
            $table->timestamp('storage_checked_at', 6)->nullable();
            $table->timestamps();

            $table->foreign('series_id', 'monitoring_metric_summary_series_fk')
                ->references('id')->on('monitoring_metric_series')->cascadeOnDelete();
            $table->index(['storage_state', 'storage_checked_at'], 'monitoring_metric_summary_storage_idx');
        });

        Schema::create('monitoring_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope_kind', 32);
            $table->foreignId('site_id')->nullable();
            $table->foreignId('device_id')->nullable();
            $table->string('data_class', 64)->nullable();
            $table->string('privacy_class', 32)->nullable();
            $table->unsignedInteger('raw_days');
            $table->unsignedInteger('hourly_days');
            $table->unsignedInteger('daily_days');
            $table->boolean('legal_hold')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_retention_policy_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_retention_policy_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'monitoring_retention_policy_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['scope_kind', 'is_active'], 'monitoring_retention_policy_scope_idx');
        });

        DB::table('monitoring_retention_policies')->insert([
            'name' => 'Native monitoring default',
            'scope_kind' => 'application',
            'raw_days' => 14,
            'hourly_days' => 180,
            'daily_days' => 1825,
            'legal_hold' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('monitoring_retention_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tombstone_uuid')->unique();
            $table->foreignId('series_id')->nullable();
            $table->foreignId('snapshot_id')->nullable();
            $table->foreignId('site_id');
            $table->foreignId('device_id');
            $table->foreignId('monitor_id')->nullable();
            $table->string('data_class', 64);
            $table->string('retention_tier', 16);
            $table->timestamp('period_start', 6);
            $table->timestamp('period_end', 6);
            $table->foreignId('policy_id')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable();
            $table->string('job_reference', 128);
            $table->timestamp('deleted_at', 6);
            $table->timestamps();

            $table->foreign('series_id', 'monitoring_retention_tombstone_series_fk')
                ->references('id')->on('monitoring_metric_series')->nullOnDelete();
            $table->foreign('site_id', 'monitoring_retention_tombstone_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_retention_tombstone_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('monitor_id', 'monitoring_retention_tombstone_monitor_fk')
                ->references('id')->on('monitors')->nullOnDelete();
            $table->foreign('policy_id', 'monitoring_retention_tombstone_policy_fk')
                ->references('id')->on('monitoring_retention_policies')->nullOnDelete();
            $table->foreign('deleted_by_user_id', 'monitoring_retention_tombstone_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['site_id', 'deleted_at'], 'monitoring_retention_tombstone_site_idx');
            $table->index(['device_id', 'deleted_at'], 'monitoring_retention_tombstone_device_idx');
        });

        Schema::create('monitoring_configuration_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('snapshot_uuid')->unique();
            $table->foreignId('site_id');
            $table->foreignId('device_id');
            $table->string('source_kind', 32);
            $table->string('source', 64);
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->char('storage_path_hash', 64)->unique();
            $table->string('storage_state', 16)->default('available');
            $table->char('content_hash', 64);
            $table->char('configuration_hash', 64);
            $table->unsignedBigInteger('content_size');
            $table->string('mime_type', 128);
            $table->string('firmware_version', 128)->nullable();
            $table->timestamp('captured_at', 6);
            $table->timestamp('payload_deleted_at', 6)->nullable();
            $table->foreignId('retention_policy_id')->nullable();
            $table->foreignId('previous_snapshot_id')->nullable();
            $table->json('diff_summary')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_configuration_snapshot_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_configuration_snapshot_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->foreign('retention_policy_id', 'monitoring_configuration_snapshot_policy_fk')
                ->references('id')->on('monitoring_retention_policies')->nullOnDelete();
            $table->foreign('previous_snapshot_id', 'monitoring_configuration_snapshot_previous_fk')
                ->references('id')->on('monitoring_configuration_snapshots')->nullOnDelete();
            $table->foreign('created_by_user_id', 'monitoring_configuration_snapshot_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(
                ['device_id', 'captured_at'],
                'monitoring_configuration_snapshot_device_idx',
            );
            $table->index(
                ['site_id', 'captured_at'],
                'monitoring_configuration_snapshot_site_idx',
            );
        });

        Schema::table('monitoring_retention_tombstones', function (Blueprint $table): void {
            $table->foreign('snapshot_id', 'monitoring_retention_tombstone_snapshot_fk')
                ->references('id')->on('monitoring_configuration_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_retention_tombstones', function (Blueprint $table): void {
            $table->dropForeign('monitoring_retention_tombstone_snapshot_fk');
        });
        Schema::dropIfExists('monitoring_configuration_snapshots');
        Schema::dropIfExists('monitoring_retention_tombstones');
        Schema::dropIfExists('monitoring_retention_policies');
        Schema::dropIfExists('monitoring_metric_current_summaries');
        Schema::dropIfExists('monitoring_metric_series');
    }
};
