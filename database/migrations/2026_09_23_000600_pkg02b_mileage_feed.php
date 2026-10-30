<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B tracker distance feed: a vehicle's dashboard reading reconciled with
 * its tracker's distance, and whether that calibrated distance may be used for
 * service and RUC planning. Recorded readings stay the evidence; readiness
 * never substitutes the tracker. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_mileage_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->unique('fleet_mileage_feed_asset_uq')->constrained('assets')->restrictOnDelete();
            $table->boolean('automatic')->default(false);
            $table->unsignedInteger('tolerance_km')->default(25);
            // The dashboard reading and the tracker's own distance at the same sample.
            $table->foreignId('baseline_observation_id')->nullable()
                ->constrained('fleet_vehicle_odometer_observations', 'id', 'fleet_mileage_feed_baseline_fk')->restrictOnDelete();
            $table->decimal('baseline_tracker_km', 10, 1)->nullable();
            $table->unsignedBigInteger('baseline_event_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_mileage_feed_reconciler_fk')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('fleet_vehicle_mileage_feed_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')
                ->constrained('fleet_vehicle_mileage_feeds', 'id', 'fleet_mileage_feed_event_fk')->restrictOnDelete();
            // reconciled | paused
            $table->string('action', 24);
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_mileage_feed_actor_fk')->nullOnDelete();
            $table->decimal('dashboard_km', 10, 1)->nullable();
            $table->decimal('tracker_km', 10, 1)->nullable();
            $table->boolean('automatic')->default(false);
            $table->unsignedInteger('tolerance_km')->nullable();
            $table->string('note', 2000)->nullable();
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['feed_id', 'request_key'], 'fleet_mileage_feed_event_request_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_vehicle_mileage_feeds')->exists()) {
            throw new RuntimeException('PKG-02B mileage feed records exist; preserve their reconciliation history instead of rolling back.');
        }
        Schema::dropIfExists('fleet_vehicle_mileage_feed_events');
        Schema::dropIfExists('fleet_vehicle_mileage_feeds');
    }
};
