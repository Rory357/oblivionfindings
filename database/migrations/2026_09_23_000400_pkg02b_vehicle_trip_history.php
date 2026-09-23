<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle trip history.
 *
 * - `fleet_trips.max_speed_kph`: the trips list and CSV already read this
 *   column; the trip builder now records it and existing closed trips are
 *   filled from their recorded telemetry.
 * - A confirmed driver on each trip, with the confirmations kept as history.
 *   A checked-out booking is shown as the booked driver until someone
 *   confirms who actually drove; it is never written here on its own.
 * - `fleet_driving_metrics.harsh_other_count`: Queclink harsh reports that
 *   are cornering or carry no type, which were previously not counted.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_trips', function (Blueprint $table): void {
            $table->decimal('max_speed_kph', 8, 2)->nullable()->after('duration_s');
            $table->foreignId('driver_user_id')->nullable()->after('driver_session_id')
                ->constrained('users', 'id', 'fleet_trips_driver_user_fk')->nullOnDelete();
            // booking = the booked driver was confirmed; handover = someone
            // other than the booked driver drove; manual = no booking covered it.
            $table->string('driver_attribution_source', 24)->nullable()->after('driver_user_id');
            $table->foreignId('driver_confirmed_by')->nullable()->after('driver_attribution_source')
                ->constrained('users', 'id', 'fleet_trips_driver_confirmer_fk')->nullOnDelete();
            $table->timestamp('driver_confirmed_at')->nullable()->after('driver_confirmed_by');
            $table->index(['asset_id', 'driver_user_id'], 'fleet_trips_asset_driver_idx');
        });

        Schema::create('fleet_trip_driver_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fleet_trip_id')
                ->constrained('fleet_trips', 'id', 'fleet_trip_driver_conf_trip_fk')->cascadeOnDelete();
            $table->foreignId('asset_id')
                ->constrained('assets', 'id', 'fleet_trip_driver_conf_asset_fk')->cascadeOnDelete();
            $table->foreignId('driver_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_trip_driver_conf_driver_fk')->nullOnDelete();
            $table->foreignId('previous_driver_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_trip_driver_conf_prev_fk')->nullOnDelete();
            $table->string('previous_source', 24)->nullable();
            $table->string('source', 24);
            $table->foreignId('booking_id')->nullable()
                ->constrained('fleet_vehicle_bookings', 'id', 'fleet_trip_driver_conf_booking_fk')->nullOnDelete();
            $table->text('reason');
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_trip_driver_conf_actor_fk')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->string('request_key', 100);
            $table->char('request_fingerprint', 64);
            $table->timestamps();
            $table->unique(['fleet_trip_id', 'request_key'], 'fleet_trip_driver_conf_request_uq');
            $table->index(['fleet_trip_id', 'confirmed_at'], 'fleet_trip_driver_conf_trip_idx');
        });

        Schema::table('fleet_driving_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('harsh_other_count')->default(0)->after('accel_count');
        });

        // Fill the maximum speed of finished trips from the telemetry recorded
        // during them. Open trips are kept up to date by the trip builder.
        DB::table('fleet_trips')->select('id')
            ->whereNull('max_speed_kph')->whereNotNull('ended_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                DB::table('fleet_trips')->whereIn('id', $rows->pluck('id')->all())->update([
                    'max_speed_kph' => DB::raw('(SELECT MAX(e.speed_kph) FROM fleet_telemetry_events e'
                        .' WHERE e.asset_id = fleet_trips.asset_id AND e.consent_blocked = 0'
                        .' AND e.occurred_at >= fleet_trips.started_at AND e.occurred_at <= fleet_trips.ended_at)'),
                ]);
            });
    }

    public function down(): void
    {
        if (DB::table('fleet_trip_driver_confirmations')->exists()
            || DB::table('fleet_trips')->whereNotNull('driver_user_id')->exists()) {
            throw new RuntimeException('PKG-02B confirmed trip drivers exist; preserve them instead of rolling back trip history.');
        }

        Schema::table('fleet_driving_metrics', function (Blueprint $table): void {
            $table->dropColumn('harsh_other_count');
        });
        Schema::dropIfExists('fleet_trip_driver_confirmations');
        Schema::table('fleet_trips', function (Blueprint $table): void {
            $table->dropForeign('fleet_trips_driver_user_fk');
            $table->dropForeign('fleet_trips_driver_confirmer_fk');
            $table->dropIndex('fleet_trips_asset_driver_idx');
            $table->dropColumn(['max_speed_kph', 'driver_user_id', 'driver_attribution_source',
                'driver_confirmed_by', 'driver_confirmed_at']);
        });
    }
};
