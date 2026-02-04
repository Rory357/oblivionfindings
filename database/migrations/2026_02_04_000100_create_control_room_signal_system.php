<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Signal Sources - registry of all signal-emitting integrations
        Schema::create('control_room_signal_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // UniFi Protect, Queclink Fleet, Personal Tracker
            $table->string('slug')->unique(); // unifi_protect, queclink, personal_tracker
            $table->string('vendor')->nullable(); // Ubiquiti, Queclink, etc
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->json('config')->nullable(); // Connection config (encrypted sensitive fields)
            $table->json('capabilities')->nullable(); // What signals this source can emit
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_signal_at')->nullable();
            $table->unsignedInteger('signal_count_24h')->default(0);
            $table->timestamps();

            $table->index(['status', 'last_heartbeat_at']);
        });

        // Signal Types Catalog - all possible signal types
        Schema::create('control_room_signal_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // panic_sos, fire_alarm, geofence_breach
            $table->string('name'); // Panic/SOS, Fire Alarm, Geofence Breach
            $table->string('category'); // people_safety, home_facility, fleet, assets, security
            $table->enum('default_severity', ['info', 'low', 'medium', 'high', 'critical'])->default('medium');
            $table->unsignedInteger('default_escalation_minutes')->default(15);
            $table->unsignedInteger('debounce_seconds')->default(60);
            $table->text('description')->nullable();
            $table->json('required_context')->nullable(); // ['site_id', 'client_id']
            $table->json('correlation_keys')->nullable(); // Keys for correlating signals
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        // Signal Routing Rules - converts signals to alerts
        Schema::create('control_room_signal_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('signal_type_id')->nullable()->constrained('control_room_signal_types')->nullOnDelete();
            $table->string('signal_type_code')->nullable(); // For matching by code
            $table->foreignId('signal_source_id')->nullable()->constrained('control_room_signal_sources')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(100); // Lower = higher priority
            $table->boolean('is_active')->default(true);

            // Matching conditions (JSON)
            $table->json('conditions')->nullable(); // {"severity_hint": "critical", "time_of_day": "night"}

            // Output configuration
            $table->enum('output_severity', ['info', 'low', 'medium', 'high', 'critical'])->nullable();
            $table->unsignedTinyInteger('output_escalation_level')->default(0);
            $table->unsignedTinyInteger('output_tier')->default(1); // Triage tier 1, 2, 3
            $table->unsignedBigInteger('playbook_id')->nullable();

            // Notification targets
            $table->json('notify_roles')->nullable(); // ['coordinator', 'on_call_manager']
            $table->json('notify_users')->nullable(); // [user_id, user_id]

            // Suppression/dedup
            $table->boolean('deduplicate')->default(true);
            $table->unsignedInteger('dedup_window_minutes')->default(30);
            $table->boolean('suppress_in_maintenance')->default(true);

            $table->timestamps();

            $table->index(['signal_type_code', 'is_active', 'priority'], 'cr_signal_rules_type_active_pri_idx');
            $table->index(['is_active', 'priority'], 'cr_signal_rules_active_pri_idx');
        });

        // Maintenance Windows - suppress alerts during planned downtime
        Schema::create('control_room_maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('signal_source_id')->nullable()->constrained('control_room_signal_sources')->nullOnDelete();
            $table->foreignId('site_id')->nullable(); // If null, applies globally
            $table->string('asset_type')->nullable(); // camera, door, sensor
            $table->foreignId('asset_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['scheduled', 'active', 'completed', 'cancelled'])->default('scheduled');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        // Raw Signals Log - all incoming signals before processing
        Schema::create('control_room_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_source_id')->nullable()->constrained('control_room_signal_sources')->nullOnDelete();
            $table->foreignId('signal_type_id')->nullable()->constrained('control_room_signal_types')->nullOnDelete();
            $table->string('signal_type_code');
            $table->string('idempotency_key', 128)->nullable()->unique();

            // Context references
            $table->foreignId('site_id')->nullable();
            $table->foreignId('client_id')->nullable();
            $table->foreignId('asset_id')->nullable();
            $table->foreignId('device_id')->nullable(); // Device registry
            $table->string('external_ref')->nullable(); // External system reference

            // Signal data
            $table->enum('severity_hint', ['info', 'low', 'medium', 'high', 'critical'])->default('medium');
            $table->timestamp('occurred_at');
            $table->json('payload')->nullable(); // Raw signal data
            $table->json('normalized_data')->nullable(); // Normalized fields

            // Processing status
            $table->enum('status', ['pending', 'processed', 'suppressed', 'failed'])->default('pending');
            $table->foreignId('alert_id')->nullable()->constrained('control_room_alerts')->nullOnDelete();
            $table->foreignId('correlated_alert_id')->nullable(); // If merged into existing alert
            $table->text('processing_notes')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'occurred_at']);
            $table->index(['signal_type_code', 'occurred_at']);
            $table->index(['site_id', 'occurred_at']);
            $table->index(['asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_room_signals');
        Schema::dropIfExists('control_room_maintenance_windows');
        Schema::dropIfExists('control_room_signal_rules');
        Schema::dropIfExists('control_room_signal_types');
        Schema::dropIfExists('control_room_signal_sources');
    }
};
