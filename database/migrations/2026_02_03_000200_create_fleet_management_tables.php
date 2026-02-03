<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_telemetry_ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->string('vendor');
            $table->dateTime('received_at');
            $table->unsignedInteger('record_count')->default(0);
            $table->string('checksum')->nullable();
            $table->string('status')->default('received'); // received|processing|processed|failed
            $table->json('errors_json')->nullable();
            $table->timestamps();

            $table->index(['vendor', 'received_at']);
            $table->index(['status']);
        });

        Schema::create('fleet_telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_tracker_id')->constrained('asset_trackers')->cascadeOnDelete();
            $table->string('vendor');
            $table->string('vendor_message_id')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->decimal('speed_kph', 8, 2)->nullable();
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->integer('altitude_m')->nullable();
            $table->boolean('ignition')->nullable();
            $table->string('motion_status')->nullable();
            $table->unsignedSmallInteger('battery_pct')->nullable();
            $table->boolean('external_power')->nullable();
            $table->decimal('odometer_km', 10, 3)->nullable();
            $table->string('event_type')->nullable();
            $table->string('idempotency_key', 64);
            $table->json('raw_payload')->nullable();
            $table->boolean('consent_blocked')->default(false);
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['asset_id', 'occurred_at']);
            $table->index(['asset_tracker_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('fleet_driver_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('source')->default('manual'); // manual|qr|auto
            $table->string('status')->default('open'); // open|closed|voided
            $table->timestamps();

            $table->index(['asset_id', 'started_at']);
        });

        Schema::create('fleet_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_session_id')->nullable()->constrained('fleet_driver_sessions')->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->decimal('end_latitude', 10, 7)->nullable();
            $table->decimal('end_longitude', 10, 7)->nullable();
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->string('status')->default('open'); // open|closed|archived
            $table->boolean('consent_blocked')->default(false);
            $table->timestamps();

            $table->index(['asset_id', 'started_at']);
            $table->index(['status']);
        });

        Schema::create('fleet_vehicle_state_snapshots', function (Blueprint $table) {
            $table->foreignId('asset_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('last_event_id')->nullable()->constrained('fleet_telemetry_events')->nullOnDelete();
            $table->foreignId('last_trip_id')->nullable()->constrained('fleet_trips')->nullOnDelete();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_moving_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('speed_kph', 8, 2)->nullable();
            $table->unsignedSmallInteger('heading_deg')->nullable();
            $table->boolean('ignition')->nullable();
            $table->string('motion_status')->nullable();
            $table->unsignedSmallInteger('battery_pct')->nullable();
            $table->string('status')->default('offline'); // online|offline
            $table->boolean('consent_blocked')->default(false);
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('fleet_trip_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_trip_id')->constrained('fleet_trips')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->longText('polyline')->nullable();
            $table->timestamps();

            $table->unique(['fleet_trip_id', 'seq']);
        });

        Schema::create('fleet_driving_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('harsh_brake_count')->default(0);
            $table->unsignedInteger('accel_count')->default(0);
            $table->unsignedInteger('speeding_events')->default(0);
            $table->unsignedInteger('idle_minutes')->default(0);
            $table->unsignedInteger('score')->default(0);
            $table->timestamps();

            $table->unique(['asset_id', 'period_start', 'period_end']);
        });

        Schema::create('fleet_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_tracker_id')->nullable()->constrained('asset_trackers')->nullOnDelete();
            $table->foreignId('geofence_id')->nullable()->constrained('asset_geofences')->nullOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('fleet_trips')->nullOnDelete();
            $table->foreignId('driver_session_id')->nullable()->constrained('fleet_driver_sessions')->nullOnDelete();
            $table->string('signal_type');
            $table->string('severity_hint')->default('low'); // low|medium|high|critical
            $table->dateTime('occurred_at');
            $table->string('idempotency_key', 64);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['asset_id', 'occurred_at']);
            $table->index(['signal_type', 'occurred_at']);
        });

        Schema::create('fleet_integration_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor')->unique();
            $table->string('last_message_id')->nullable();
            $table->dateTime('last_received_at')->nullable();
            $table->string('status')->default('active'); // active|stalled
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('status')->default('generating'); // generating|ready|failed
            $table->string('storage_path')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['report_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_reports');
        Schema::dropIfExists('fleet_integration_cursors');
        Schema::dropIfExists('fleet_signals');
        Schema::dropIfExists('fleet_driving_metrics');
        Schema::dropIfExists('fleet_trip_segments');
        Schema::dropIfExists('fleet_trips');
        Schema::dropIfExists('fleet_driver_sessions');
        Schema::dropIfExists('fleet_vehicle_state_snapshots');
        Schema::dropIfExists('fleet_telemetry_events');
        Schema::dropIfExists('fleet_telemetry_ingest_batches');
    }
};
