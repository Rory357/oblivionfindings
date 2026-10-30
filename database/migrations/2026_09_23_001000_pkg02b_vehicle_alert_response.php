<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle Alerts & Control Room.
 *
 * - `fleet_vehicle_alert_plans`: versioned draft response plans for a
 *   vehicle (owner, backup and review thresholds). A draft is not a live
 *   device setting and starts no monitoring.
 * - `fleet_vehicle_alert_actions`: the vehicle profile's ledger of what was
 *   done about the vehicle's Control Room responses: recorded events sent to
 *   Control Room, lifecycle actions, triage decisions, the linked Maintenance
 *   work and follow-up reminders. Control Room keeps its own record; this
 *   links the one source event, the one response and its follow-up.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_alert_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets', 'id', 'fleet_alert_plan_asset_fk')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('owner_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_alert_plan_owner_fk')->nullOnDelete();
            $table->foreignId('backup_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_alert_plan_backup_fk')->nullOnDelete();
            $table->unsignedSmallInteger('speed_threshold_kph');
            $table->unsignedTinyInteger('speed_tolerance_kph');
            $table->unsignedSmallInteger('speed_duration_s');
            $table->unsignedSmallInteger('speed_cooldown_s');
            $table->unsignedSmallInteger('offline_minutes');
            $table->decimal('low_voltage_v', 4, 1);
            $table->unsignedSmallInteger('low_voltage_minutes');
            $table->text('notes');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_alert_plan_actor_fk')->nullOnDelete();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['asset_id', 'version'], 'fleet_alert_plan_version_uq');
            $table->unique(['asset_id', 'request_key'], 'fleet_alert_plan_request_uq');
        });

        Schema::create('fleet_vehicle_alert_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('assets', 'id', 'fleet_alert_action_asset_fk')->cascadeOnDelete();
            $table->foreignId('control_room_alert_id')->nullable()
                ->constrained('control_room_alerts', 'id', 'fleet_alert_action_alert_fk')->nullOnDelete();
            $table->foreignId('fleet_signal_id')->nullable()
                ->constrained('fleet_signals', 'id', 'fleet_alert_action_signal_fk')->nullOnDelete();
            // routed | acknowledged | triaged | escalated | resolved | delivery_retried
            // | maintenance_created | maintenance_linked | follow_up_created
            $table->string('action', 32);
            $table->string('decision', 32)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->foreignId('work_order_id')->nullable()
                ->constrained('fleet_work_orders', 'id', 'fleet_alert_action_work_fk')->nullOnDelete();
            $table->foreignId('reminder_id')->nullable()
                ->constrained('fleet_vehicle_reminders', 'id', 'fleet_alert_action_reminder_fk')->nullOnDelete();
            $table->text('note')->nullable();
            // A snapshot of the recorded evidence a route was made from.
            $table->json('evidence')->nullable();
            // The recorded event a route was made from, e.g. overspeed:12:overspeed-345.
            $table->string('source_key', 160)->nullable();
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_alert_action_actor_fk')->nullOnDelete();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_alert_action_request_uq');
            $table->index(['control_room_alert_id', 'action'], 'fleet_alert_action_alert_idx');
            $table->index(['fleet_signal_id'], 'fleet_alert_action_signal_idx');
            $table->index(['asset_id', 'source_key'], 'fleet_alert_action_source_idx');
        });
    }

    public function down(): void
    {
        foreach (['fleet_vehicle_alert_plans', 'fleet_vehicle_alert_actions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('PKG-02B vehicle alert plans or response actions exist; preserve them instead of rolling back.');
            }
        }

        Schema::dropIfExists('fleet_vehicle_alert_actions');
        Schema::dropIfExists('fleet_vehicle_alert_plans');
    }
};
