<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('default_risk_level')->nullable();
            $table->boolean('requires_inspection_default')->default(false);
            $table->boolean('requires_maintenance_default')->default(false);
            $table->unsignedBigInteger('policy_profile_id')->nullable();
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('asset_category_id')
                ->nullable()
                ->after('category')
                ->constrained('asset_categories')
                ->nullOnDelete();
            $table->index('asset_category_id');
        });

        Schema::create('asset_ownerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type'); // organisation|site|client
            $table->unsignedBigInteger('owner_id');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'effective_to']);
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('assignee_type'); // staff|client|whanau
            $table->unsignedBigInteger('assignee_id');
            $table->string('purpose')->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'released_at']);
            $table->index(['assignee_type', 'assignee_id']);
        });

        Schema::create('asset_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->decimal('current_value', 12, 2)->nullable();
            $table->decimal('replacement_value', 12, 2)->nullable();
            $table->string('depreciation_model')->nullable(); // straight_line|declining
            $table->decimal('depreciation_rate', 6, 3)->nullable();
            $table->unsignedBigInteger('insurance_policy_id')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_qr_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('qr_token', 64)->unique();
            $table->string('status')->default('active'); // active|revoked|rotated
            $table->dateTime('issued_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });

        Schema::create('asset_trackers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('vendor'); // quicklink|queclink|teltonika|other
            $table->string('device_uid');
            $table->string('imei')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('status')->default('paired'); // paired|suspended|unpaired
            $table->dateTime('paired_at');
            $table->dateTime('unpaired_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->foreignId('consent_id')->nullable()->constrained('client_consents')->nullOnDelete();
            $table->json('vendor_metadata')->nullable();
            $table->timestamps();

            $table->unique(['vendor', 'device_uid']);
            $table->index(['asset_id', 'status']);
        });

        Schema::create('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_tracker_id')->constrained('asset_trackers')->cascadeOnDelete();
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->decimal('speed_kph', 8, 2)->nullable();
            $table->string('movement_status')->nullable(); // moving|stationary|unknown
            $table->unsignedSmallInteger('battery_pct')->nullable();
            $table->string('power_source')->nullable(); // external|battery|unknown
            $table->boolean('tamper_flag')->default(false);
            $table->boolean('sos_flag')->default(false);
            $table->string('vendor_payload_hash', 64);
            $table->json('vendor_metadata')->nullable();
            $table->boolean('consent_blocked')->default(false);
            $table->timestamps();

            $table->unique('vendor_payload_hash');
            $table->index(['asset_id', 'occurred_at']);
            $table->index(['asset_tracker_id', 'occurred_at']);
        });

        Schema::create('asset_telemetry_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->decimal('distance_km', 10, 3)->default(0);
            $table->unsignedInteger('time_moving_minutes')->default(0);
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('battery_min')->nullable();
            $table->unsignedSmallInteger('battery_max')->nullable();
            $table->unsignedInteger('alerts_count')->default(0);
            $table->timestamps();

            $table->unique(['asset_id', 'summary_date']);
        });

        Schema::create('asset_alert_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('policy_type'); // geofence|sos|tamper|movement
            $table->string('severity')->default('medium'); // low|medium|high|critical
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('asset_geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('circle'); // circle|polygon
            $table->json('shape'); // {lat,lon,radius_m} or polygon points
            $table->string('breach_type')->default('soft'); // soft|hard
            $table->json('time_rules')->nullable(); // e.g. allowed windows
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('asset_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_tracker_id')->nullable()->constrained('asset_trackers')->nullOnDelete();
            $table->foreignId('asset_alert_policy_id')->nullable()->constrained('asset_alert_policies')->nullOnDelete();
            $table->string('alert_type');
            $table->string('severity')->default('medium');
            $table->string('status')->default('open'); // open|ack|resolved
            $table->dateTime('triggered_at');
            $table->dateTime('resolved_at')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
            $table->index(['triggered_at']);
        });

        Schema::create('asset_scan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('qr_token', 64);
            $table->string('scanned_by_type');
            $table->unsignedBigInteger('scanned_by_id');
            $table->dateTime('scanned_at');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'scanned_at']);
            $table->index(['qr_token']);
        });

        Schema::create('asset_incident_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('client_incidents')->cascadeOnDelete();
            $table->string('relation')->default('affected'); // cause|affected|evidence
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'incident_id']);
        });

        Schema::create('asset_procedure_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_run_id')->constrained('procedure_runs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'procedure_run_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['asset_category_id']);
            $table->dropIndex(['asset_category_id']);
            $table->dropColumn('asset_category_id');
        });

        Schema::dropIfExists('asset_procedure_runs');
        Schema::dropIfExists('asset_incident_links');
        Schema::dropIfExists('asset_scan_events');
        Schema::dropIfExists('asset_alerts');
        Schema::dropIfExists('asset_geofences');
        Schema::dropIfExists('asset_alert_policies');
        Schema::dropIfExists('asset_telemetry_histories');
        Schema::dropIfExists('asset_telemetry_snapshots');
        Schema::dropIfExists('asset_trackers');
        Schema::dropIfExists('asset_qr_tags');
        Schema::dropIfExists('asset_values');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('asset_ownerships');
        Schema::dropIfExists('asset_categories');
    }
};
