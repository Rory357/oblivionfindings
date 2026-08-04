<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('failure_duration_seconds')->default(0)->after('failure_confirmations');
            $table->unsignedInteger('recovery_duration_seconds')->default(0)->after('recovery_confirmations');
            $table->decimal('rising_threshold', 20, 6)->nullable()->after('stale_after_seconds');
            $table->decimal('falling_threshold', 20, 6)->nullable()->after('rising_threshold');
            $table->unsignedInteger('baseline_window_seconds')->default(3600)->after('falling_threshold');
            $table->unsignedSmallInteger('baseline_minimum_samples')->default(10)->after('baseline_window_seconds');
            $table->decimal('baseline_deviation_multiplier', 8, 3)->nullable()->after('baseline_minimum_samples');
            $table->string('maintenance_policy', 64)->default('suppress_notifications_and_ticketing')->after('baseline_deviation_multiplier');
            $table->string('rollup_policy', 32)->default('worst_applicable')->after('maintenance_policy');
            $table->foreignId('retention_policy_id')->nullable()->after('rollup_policy');

            $table->foreign('retention_policy_id', 'monitoring_profiles_retention_policy_fk')
                ->references('id')->on('data_retention_policies')->nullOnDelete();
        });

        Schema::table('monitors', function (Blueprint $table): void {
            $table->string('effective_state', 32)->default('unknown')->after('current_state');
            $table->timestamp('pending_since_at')->nullable()->after('pending_count');
            $table->foreignId('root_cause_monitor_id')->nullable()->after('pending_since_at');
            $table->string('suppression_reason', 64)->nullable()->after('root_cause_monitor_id');
            $table->timestamp('suppressed_at')->nullable()->after('suppression_reason');

            $table->foreign('root_cause_monitor_id', 'monitors_root_cause_monitor_fk')
                ->references('id')->on('monitors')->nullOnDelete();
            $table->index(['effective_state', 'is_enabled'], 'monitors_effective_enabled_idx');
            $table->index(['root_cause_monitor_id', 'effective_state'], 'monitors_root_cause_state_idx');
        });

        DB::table('monitors')->update(['effective_state' => DB::raw('current_state')]);

        Schema::create('monitor_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->foreignId('upstream_monitor_id');
            $table->foreignId('downstream_monitor_id');
            $table->string('policy', 64);
            $table->string('source', 32)->default('manual');
            $table->decimal('confidence', 5, 4)->default(1);
            $table->foreignId('topology_edge_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('site_id', 'monitor_dependencies_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('upstream_monitor_id', 'monitor_dependencies_upstream_fk')
                ->references('id')->on('monitors')->restrictOnDelete();
            $table->foreign('downstream_monitor_id', 'monitor_dependencies_downstream_fk')
                ->references('id')->on('monitors')->restrictOnDelete();
            $table->foreign('topology_edge_id', 'monitor_dependencies_topology_edge_fk')
                ->references('id')->on('monitoring_topology_edges')->restrictOnDelete();
            $table->unique(
                ['upstream_monitor_id', 'downstream_monitor_id', 'policy'],
                'monitor_dependencies_pair_policy_uq',
            );
            $table->index(['site_id', 'is_active'], 'monitor_dependencies_site_active_idx');
            $table->index(['downstream_monitor_id', 'is_active'], 'monitor_dependencies_downstream_active_idx');
        });

        Schema::create('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->foreignId('monitor_id')->nullable();
            $table->foreignId('device_id')->nullable();
            $table->string('name', 128);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('recurrence', 16)->nullable();
            $table->timestamp('recurrence_until')->nullable();
            $table->string('policy', 64)->default('suppress_notifications_and_ticketing');
            $table->string('status', 16)->default('active');
            $table->string('reason', 128);
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_maintenance_window_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('monitor_id', 'monitoring_maintenance_window_monitor_fk')
                ->references('id')->on('monitors')->restrictOnDelete();
            $table->foreign('device_id', 'monitoring_maintenance_window_device_fk')
                ->references('id')->on('devices')->restrictOnDelete();
            $table->index(
                ['site_id', 'status', 'starts_at', 'ends_at'],
                'monitoring_maintenance_window_site_time_idx',
            );
            $table->index(['monitor_id', 'status'], 'monitoring_maintenance_window_monitor_idx');
            $table->index(['device_id', 'status'], 'monitoring_maintenance_window_device_idx');
        });

        Schema::create('monitoring_coverage_expectations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable();
            $table->string('device_domain', 64);
            $table->string('device_category', 64)->nullable();
            $table->string('capability', 64);
            $table->string('monitor_kind', 32);
            $table->unsignedSmallInteger('minimum_count')->default(1);
            $table->string('support_status', 16)->default('supported');
            $table->json('support_evidence');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_coverage_expectation_site_fk')
                ->references('id')->on('sites')->restrictOnDelete();
            $table->index(
                ['site_id', 'device_domain', 'device_category', 'is_active'],
                'monitoring_coverage_expectation_scope_idx',
            );
            $table->unique(
                ['site_id', 'device_domain', 'device_category', 'capability'],
                'monitoring_coverage_expectation_identity_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_coverage_expectations');
        Schema::dropIfExists('monitoring_maintenance_windows');
        Schema::dropIfExists('monitor_dependencies');

        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropForeign('monitors_root_cause_monitor_fk');
            $table->dropIndex('monitors_root_cause_state_idx');
            $table->dropIndex('monitors_effective_enabled_idx');
            $table->dropColumn([
                'effective_state',
                'pending_since_at',
                'root_cause_monitor_id',
                'suppression_reason',
                'suppressed_at',
            ]);
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropForeign('monitoring_profiles_retention_policy_fk');
            $table->dropColumn([
                'failure_duration_seconds',
                'recovery_duration_seconds',
                'rising_threshold',
                'falling_threshold',
                'baseline_window_seconds',
                'baseline_minimum_samples',
                'baseline_deviation_multiplier',
                'maintenance_policy',
                'rollup_policy',
                'retention_policy_id',
            ]);
        });
    }
};
