<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SLA Definitions
        Schema::create('control_room_sla_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();

            // Applicable to
            $table->json('alert_types')->nullable(); // Alert types this SLA applies to
            $table->json('severities')->nullable(); // Severities this applies to
            $table->json('sources')->nullable(); // Sources this applies to

            // Time targets (minutes)
            $table->unsignedInteger('acknowledge_target_minutes')->nullable();
            $table->unsignedInteger('response_target_minutes')->nullable();
            $table->unsignedInteger('resolution_target_minutes')->nullable();

            // Escalation on breach
            $table->boolean('escalate_on_acknowledge_breach')->default(false);
            $table->boolean('escalate_on_response_breach')->default(false);
            $table->boolean('escalate_on_resolution_breach')->default(false);
            $table->json('breach_notify_roles')->nullable();

            // Business hours
            $table->boolean('business_hours_only')->default(false);
            $table->json('business_hours')->nullable(); // {"start": "08:00", "end": "18:00", "days": [1,2,3,4,5]}

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active']);
        });

        // SLA Tracking per Alert
        Schema::create('control_room_alert_sla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('sla_definition_id')->nullable()->constrained('control_room_sla_definitions')->nullOnDelete();

            // Targets (copied at time of assignment)
            $table->unsignedInteger('acknowledge_target_minutes')->nullable();
            $table->unsignedInteger('response_target_minutes')->nullable();
            $table->unsignedInteger('resolution_target_minutes')->nullable();

            // Deadlines
            $table->timestamp('acknowledge_deadline')->nullable();
            $table->timestamp('response_deadline')->nullable();
            $table->timestamp('resolution_deadline')->nullable();

            // Actual times
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Performance
            $table->integer('acknowledge_variance_minutes')->nullable(); // Negative = early, positive = late
            $table->integer('response_variance_minutes')->nullable();
            $table->integer('resolution_variance_minutes')->nullable();

            // Breach tracking
            $table->boolean('acknowledge_breached')->default(false);
            $table->boolean('response_breached')->default(false);
            $table->boolean('resolution_breached')->default(false);
            $table->timestamp('first_breach_at')->nullable();

            $table->timestamps();

            $table->unique('alert_id');
            $table->index(['acknowledge_breached', 'acknowledge_deadline']);
            $table->index(['response_breached', 'response_deadline']);
            $table->index(['resolution_breached', 'resolution_deadline']);
        });

        // Communication Log
        Schema::create('control_room_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('playbook_run_id')->nullable()->constrained('control_room_playbook_runs')->nullOnDelete();

            // Communication type
            $table->enum('channel', ['in_app', 'push', 'sms', 'email', 'phone_call', 'radio'])->default('in_app');
            $table->enum('direction', ['outbound', 'inbound'])->default('outbound');
            $table->string('purpose')->nullable(); // notification, escalation, update, response

            // Target
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_phone')->nullable();
            $table->string('target_email')->nullable();
            $table->string('target_external')->nullable(); // External contact name

            // Content
            $table->string('subject')->nullable();
            $table->text('content')->nullable();
            $table->string('template_used')->nullable();

            // Status
            $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'answered', 'no_answer'])->default('pending');
            $table->text('status_detail')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);

            // For phone calls
            $table->unsignedInteger('call_duration_seconds')->nullable();
            $table->string('call_recording_path')->nullable();

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['alert_id', 'channel', 'created_at']);
            $table->index(['status', 'channel']);
        });

        // Shift Handovers
        Schema::create('control_room_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(); // Night Shift, Day Shift
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');

            // Team
            $table->foreignId('shift_lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('team_members')->nullable(); // Array of user_ids

            // Metrics at shift start/end
            $table->unsignedInteger('open_alerts_at_start')->default(0);
            $table->unsignedInteger('open_alerts_at_end')->nullable();
            $table->unsignedInteger('alerts_created')->default(0);
            $table->unsignedInteger('alerts_resolved')->default(0);
            $table->unsignedInteger('alerts_escalated')->default(0);

            // Handover
            $table->text('handover_notes')->nullable();
            $table->json('priority_items')->nullable(); // Items needing attention
            $table->foreignId('handed_over_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['shift_lead_user_id', 'status']);
        });

        // Operator Notes (separate from alert notes for shift context)
        Schema::create('control_room_operator_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->nullable()->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('control_room_shifts')->nullOnDelete();
            $table->enum('type', ['note', 'action', 'escalation', 'decision', 'handover'])->default('note');
            $table->text('content');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('requires_followup')->default(false);
            $table->timestamp('followup_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['alert_id', 'created_at']);
            $table->index(['shift_id', 'is_pinned']);
            $table->index(['requires_followup', 'followup_at']);
        });

        // Triage Queue Configuration
        Schema::create('control_room_triage_queues', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Tier 1, Tier 2, Emergency
            $table->string('code')->unique(); // tier_1, tier_2, emergency
            $table->unsignedSmallInteger('tier')->default(1);
            $table->text('description')->nullable();

            // Routing
            $table->json('handle_severities')->nullable(); // ['medium', 'high']
            $table->json('handle_sources')->nullable();
            $table->json('handle_alert_types')->nullable();

            // Assignment
            $table->json('assigned_roles')->nullable(); // Roles that can work this queue
            $table->json('assigned_users')->nullable(); // Specific users

            // Escalation
            $table->foreignId('escalate_to_queue_id')->nullable(); // Next tier queue
            $table->unsignedInteger('auto_escalate_after_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tier', 'is_active']);
        });

        // Alert Queue Assignment
        Schema::create('control_room_alert_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained('control_room_triage_queues')->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->string('exit_reason')->nullable(); // resolved, escalated, moved
            $table->timestamps();

            $table->index(['queue_id', 'exited_at']);
            $table->index(['alert_id', 'entered_at']);
        });

        // Device Registry - IoT devices, sensors, cameras, etc.
        Schema::create('control_room_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('device_uid')->unique(); // Unique device identifier
            $table->string('type'); // camera, door, sensor, alarm_panel, bed_sensor, tracker
            $table->string('vendor')->nullable(); // unifi, hikvision, tunstall
            $table->string('model')->nullable();

            // Location
            $table->foreignId('site_id')->nullable();
            $table->string('location_description')->nullable(); // "Front Door", "Bedroom 2"
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Association
            $table->foreignId('client_id')->nullable();
            $table->foreignId('asset_id')->nullable();

            // Integration
            $table->foreignId('signal_source_id')->nullable()->constrained('control_room_signal_sources')->nullOnDelete();
            $table->string('external_ref')->nullable(); // ID in external system
            $table->json('config')->nullable();

            // Status
            $table->enum('status', ['online', 'offline', 'maintenance', 'retired'])->default('online');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_signal_at')->nullable();

            // Health
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->timestamp('battery_updated_at')->nullable();
            $table->boolean('low_battery_alert_sent')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['site_id', 'type']);
            $table->index(['signal_source_id', 'status']);
            $table->index(['status', 'last_seen_at']);
        });

        // Add device_id to control_room_alerts
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('fleet_signal_id')
                ->constrained('control_room_devices')->nullOnDelete();
            $table->foreignId('queue_id')->nullable()->after('device_id')
                ->constrained('control_room_triage_queues')->nullOnDelete();
            $table->foreignId('playbook_run_id')->nullable()->after('queue_id');
            $table->foreignId('site_id')->nullable()->after('playbook_run_id');
            $table->foreignId('client_id')->nullable()->after('site_id');

            $table->index(['queue_id', 'status']);
            $table->index(['site_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropForeign(['queue_id']);
            $table->dropColumn(['device_id', 'queue_id', 'playbook_run_id', 'site_id', 'client_id']);
        });

        Schema::dropIfExists('control_room_devices');
        Schema::dropIfExists('control_room_alert_queue');
        Schema::dropIfExists('control_room_triage_queues');
        Schema::dropIfExists('control_room_operator_notes');
        Schema::dropIfExists('control_room_shifts');
        Schema::dropIfExists('control_room_communications');
        Schema::dropIfExists('control_room_alert_sla');
        Schema::dropIfExists('control_room_sla_definitions');
    }
};
