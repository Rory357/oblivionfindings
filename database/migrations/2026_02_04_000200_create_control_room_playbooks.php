<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Response Playbooks - procedure templates
        Schema::create('control_room_playbooks', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Panic/SOS Response, Fire Alarm Protocol
            $table->string('code')->unique(); // panic_sos, fire_alarm
            $table->string('category'); // emergency, safety, compliance, maintenance
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            // Trigger conditions
            $table->json('trigger_alert_types')->nullable(); // ['panic_sos', 'fall_detected']
            $table->json('trigger_severities')->nullable(); // ['high', 'critical']
            $table->boolean('auto_attach')->default(false); // Auto-attach when alert matches

            // SLA targets
            $table->unsignedInteger('sla_acknowledge_minutes')->nullable();
            $table->unsignedInteger('sla_response_minutes')->nullable();
            $table->unsignedInteger('sla_resolution_minutes')->nullable();

            // Required evidence
            $table->json('required_evidence')->nullable(); // ['photo', 'cctv_bookmark', 'notes']

            // Approval requirements
            $table->boolean('requires_approval')->default(false);
            $table->json('approval_roles')->nullable(); // ['supervisor', 'manager']

            // Escalation
            $table->unsignedInteger('escalation_after_minutes')->nullable();
            $table->json('escalation_targets')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index(['is_active', 'auto_attach']);
        });

        Schema::table('control_room_signal_rules', function (Blueprint $table) {
            $table->foreign('playbook_id')
                ->references('id')
                ->on('control_room_playbooks')
                ->nullOnDelete();
        });

        // Playbook Steps - individual tasks in a playbook
        Schema::create('control_room_playbook_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('control_room_playbooks')->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->enum('type', ['task', 'decision', 'notification', 'escalation', 'evidence', 'approval'])->default('task');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_blocking')->default(false); // Must complete before proceeding

            // For decision type
            $table->json('decision_options')->nullable(); // [{"label": "Yes", "next_step": 3}, ...]

            // For notification type
            $table->json('notify_config')->nullable(); // {"channel": "sms", "template": "alert_escalation"}

            // For evidence type
            $table->json('evidence_config')->nullable(); // {"type": "photo", "required": true}

            // Time constraints
            $table->unsignedInteger('time_limit_minutes')->nullable();

            $table->timestamps();

            $table->index(['playbook_id', 'order']);
        });

        // Playbook Runs - execution of a playbook
        Schema::create('control_room_playbook_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_id')->constrained('control_room_playbooks')->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'cancelled'])->default('pending');

            // Progress tracking
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->unsignedSmallInteger('completed_steps')->default(0);
            $table->unsignedSmallInteger('total_steps')->default(0);

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // SLA tracking
            $table->boolean('sla_acknowledge_met')->nullable();
            $table->boolean('sla_response_met')->nullable();
            $table->boolean('sla_resolution_met')->nullable();

            // Users
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('context')->nullable(); // Execution context/notes
            $table->timestamps();

            $table->index(['alert_id', 'status']);
            $table->index(['playbook_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        // Playbook Run Steps - individual step execution
        Schema::create('control_room_playbook_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playbook_run_id')->constrained('control_room_playbook_runs')->cascadeOnDelete();
            $table->foreignId('playbook_step_id')->constrained('control_room_playbook_steps')->cascadeOnDelete();
            $table->unsignedSmallInteger('order')->default(0);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped', 'failed'])->default('pending');

            // Execution details
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // For decision steps
            $table->string('decision_taken')->nullable();

            // Notes/evidence
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable(); // {"photos": [...], "documents": [...]}

            $table->timestamps();

            $table->index(['playbook_run_id', 'order']);
            $table->index(['playbook_run_id', 'status']);
        });

        // Evidence Packs - collected evidence for audit
        Schema::create('control_room_evidence_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('control_room_alerts')->cascadeOnDelete();
            $table->foreignId('playbook_run_id')->nullable()->constrained('control_room_playbook_runs')->nullOnDelete();
            $table->string('title');
            $table->enum('status', ['collecting', 'complete', 'exported'])->default('collecting');

            // Evidence items
            $table->json('items')->nullable(); // Array of evidence references
            $table->unsignedInteger('item_count')->default(0);

            // Export info
            $table->string('export_path')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['alert_id', 'status']);
        });

        // Evidence Items
        Schema::create('control_room_evidence_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_pack_id')->constrained('control_room_evidence_packs')->cascadeOnDelete();
            $table->string('type'); // photo, document, cctv_bookmark, door_log, note, audio
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // Storage reference
            $table->string('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // External references
            $table->string('external_system')->nullable(); // unifi_protect, unifi_access
            $table->string('external_ref')->nullable();
            $table->json('metadata')->nullable();

            // Capture info
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['evidence_pack_id', 'type']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('control_room_signal_rules')) {
            Schema::table('control_room_signal_rules', function (Blueprint $table) {
                $table->dropForeign(['playbook_id']);
            });
        }

        Schema::dropIfExists('control_room_evidence_items');
        Schema::dropIfExists('control_room_evidence_packs');
        Schema::dropIfExists('control_room_playbook_run_steps');
        Schema::dropIfExists('control_room_playbook_runs');
        Schema::dropIfExists('control_room_playbook_steps');
        Schema::dropIfExists('control_room_playbooks');
    }
};
