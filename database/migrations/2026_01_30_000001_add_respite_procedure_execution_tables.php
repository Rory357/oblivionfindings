<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds procedure execution tracking tables for the Respite module.
 *
 * This migration adds:
 * - respite_procedure_runs: Tracks execution of procedure templates
 * - respite_tasks: Individual tasks generated from procedures
 * - respite_audit_logs: Comprehensive audit trail for all respite actions
 * - respite_daily_notes: Daily wellbeing and support notes
 * - respite_risk_plans: Risk/behaviour plan activations during stays
 */
return new class extends Migration
{
    public function up(): void
    {
        // Procedure Runs - tracks execution of procedure templates
        Schema::create('respite_procedure_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_template_id')->constrained('procedure_templates')->cascadeOnDelete();

            // Polymorphic subject (booking, stay, referral, etc.)
            $table->morphs('subject', 'respite_proc_run_subject_idx');

            $table->enum('status', [
                'pending',      // Not yet started
                'in_progress',  // Currently executing
                'blocked',      // Waiting on stop-gate or external dependency
                'completed',    // Successfully completed all steps
                'failed',       // Failed with error
                'cancelled',    // Manually cancelled
                'expired',      // SLA exceeded without completion
            ])->default('pending');

            $table->integer('current_step')->default(0);
            $table->integer('total_steps')->default(0);
            $table->json('step_states')->nullable(); // Tracks state of each step
            $table->json('collected_evidence')->nullable(); // Evidence gathered during run
            $table->json('variables')->nullable(); // Runtime variables

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // SLA tracking
            $table->timestamp('sla_deadline')->nullable();
            $table->boolean('sla_breached')->default(false);
            $table->timestamp('sla_breached_at')->nullable();

            // Escalation tracking
            $table->integer('escalation_level')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->foreignId('escalated_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id', 'status'], 'respite_proc_run_lookup_idx');
            $table->index('status');
            $table->index('sla_deadline');
        });

        // Tasks - individual actionable items from procedures
        Schema::create('respite_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_run_id')->nullable()->constrained('respite_procedure_runs')->cascadeOnDelete();

            // Can also be standalone tasks not from procedures
            $table->morphs('subject', 'respite_task_subject_idx');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type')->default('action'); // action, checklist, approval, evidence, notification

            $table->enum('status', [
                'pending',
                'in_progress',
                'awaiting_approval',
                'approved',
                'rejected',
                'completed',
                'skipped',
                'blocked',
            ])->default('pending');

            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');

            // Assignment
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Completion
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            // Approval workflow
            $table->boolean('requires_approval')->default(false);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();

            // Evidence requirements
            $table->json('required_evidence')->nullable(); // List of evidence types needed
            $table->json('collected_evidence')->nullable(); // Actual evidence collected
            $table->boolean('evidence_complete')->default(false);

            // SLA
            $table->timestamp('due_at')->nullable();
            $table->boolean('overdue')->default(false);
            $table->integer('sla_minutes')->nullable();

            // Stop-gate (blocks procedure progression if not completed)
            $table->boolean('is_stop_gate')->default(false);

            // Checklist items (for checklist-type tasks)
            $table->json('checklist_items')->nullable();

            $table->integer('step_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assigned_to_user_id', 'status'], 'respite_task_assignment_idx');
            $table->index('due_at');
            $table->index('status');
        });

        // Comprehensive Audit Log
        Schema::create('respite_audit_logs', function (Blueprint $table) {
            $table->id();

            // What was affected
            $table->morphs('auditable', 'respite_audit_subject_idx');

            // Action details
            $table->string('action'); // created, updated, deleted, status_changed, approved, etc.
            $table->string('action_category')->nullable(); // booking, stay, procedure, admin, etc.

            // Who did it
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable(); // Denormalized for historical record
            $table->string('user_role')->nullable();

            // What changed
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable(); // Why the action was taken

            // Context
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();

            // Break-glass access tracking
            $table->boolean('break_glass_access')->default(false);
            $table->text('break_glass_justification')->nullable();
            $table->foreignId('break_glass_approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Linked evidence
            $table->json('evidence_refs')->nullable();

            // Idempotency for event processing
            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id', 'created_at'], 'respite_audit_lookup_idx');
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });

        // Daily Notes & Wellbeing tracking
        Schema::create('respite_daily_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->date('note_date');
            $table->enum('shift_period', ['morning', 'afternoon', 'evening', 'night', 'all_day'])->default('all_day');

            // Wellbeing indicators
            $table->enum('mood', ['very_low', 'low', 'neutral', 'good', 'excellent'])->nullable();
            $table->enum('appetite', ['none', 'poor', 'fair', 'good', 'excellent'])->nullable();
            $table->enum('sleep_quality', ['very_poor', 'poor', 'fair', 'good', 'excellent'])->nullable();
            $table->enum('engagement', ['none', 'minimal', 'moderate', 'good', 'excellent'])->nullable();
            $table->enum('mobility', ['bedbound', 'limited', 'assisted', 'independent'])->nullable();

            // Activities and observations
            $table->text('activities')->nullable();
            $table->text('observations')->nullable();
            $table->text('concerns')->nullable();
            $table->text('goals_progress')->nullable();

            // Medical/care notes
            $table->text('medication_notes')->nullable();
            $table->text('personal_care_notes')->nullable();
            $table->text('nutrition_notes')->nullable();

            // Incidents/events
            $table->boolean('incident_occurred')->default(false);
            $table->unsignedBigInteger('linked_incident_id')->nullable();

            // Sensitive flag for restricted access
            $table->boolean('sensitive_flag')->default(false);

            // Evidence attachments
            $table->json('attachments')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['stay_id', 'note_date', 'shift_period'], 'respite_daily_unique_idx');
            $table->index(['client_id', 'note_date']);
        });

        // Risk/Behaviour Plan Activations
        Schema::create('respite_risk_plan_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            // Link to the actual risk assessment (from client module)
            $table->unsignedBigInteger('risk_assessment_id')->nullable();

            $table->string('plan_type'); // behaviour, safety, medical, mobility, communication
            $table->string('plan_name');

            $table->enum('status', [
                'pending_review',
                'active',
                'modified',
                'suspended',
                'completed',
            ])->default('pending_review');

            // Plan details (copied from source at activation time for audit)
            $table->json('plan_details')->nullable();
            $table->json('triggers')->nullable();
            $table->json('interventions')->nullable();
            $table->json('escalation_steps')->nullable();

            // Review and acknowledgment
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Staff acknowledgment (all staff must acknowledge)
            $table->json('staff_acknowledgments')->nullable();

            // Activation period
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stay_id', 'status']);
            $table->index(['client_id', 'plan_type']);
        });

        // Add missing fields to existing tables
        Schema::table('respite_bookings', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('assigned_coordinator_id')->constrained('sites')->nullOnDelete();
            $table->json('eligibility_checks')->nullable()->after('approvals');
            $table->json('consent_records')->nullable()->after('eligibility_checks');
            $table->json('funding_verification')->nullable()->after('consent_records');
            $table->json('pre_arrival_checklist')->nullable()->after('funding_verification');
            $table->boolean('medications_reconciled')->default(false)->after('pre_arrival_checklist');
            $table->timestamp('medications_reconciled_at')->nullable()->after('medications_reconciled');
            $table->foreignId('medications_reconciled_by')->nullable()->after('medications_reconciled_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('respite_stays', function (Blueprint $table) {
            $table->json('arrival_checklist')->nullable()->after('actual_start');
            $table->boolean('arrival_checklist_complete')->default(false)->after('arrival_checklist');
            $table->json('discharge_checklist')->nullable()->after('discharge_summary');
            $table->boolean('discharge_checklist_complete')->default(false)->after('discharge_checklist');
            $table->text('post_respite_summary')->nullable()->after('discharge_checklist_complete');
            $table->json('transport_arrangements')->nullable()->after('post_respite_summary');
        });

        Schema::table('respite_calendar_events', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('stay_id')->constrained('clients')->nullOnDelete();
            $table->string('title')->nullable()->after('event_type');
            $table->text('description')->nullable()->after('title');
            $table->boolean('all_day')->default(false)->after('end_at');
            $table->string('recurrence_rule')->nullable()->after('all_day');
            $table->integer('buffer_before_minutes')->default(0)->after('recurrence_rule');
            $table->integer('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
            $table->boolean('conflicts_detected')->default(false)->after('projection_status');
            $table->json('conflict_details')->nullable()->after('conflicts_detected');
        });

        Schema::table('respite_handover_notes', function (Blueprint $table) {
            $table->foreignId('from_shift_id')->nullable()->after('stay_id')->constrained('shifts')->nullOnDelete();
            $table->foreignId('to_shift_id')->nullable()->after('from_shift_id')->constrained('shifts')->nullOnDelete();
            $table->json('key_points')->nullable()->after('notes');
            $table->json('pending_tasks')->nullable()->after('key_points');
            $table->json('medication_status')->nullable()->after('pending_tasks');
            $table->json('incident_refs')->nullable()->after('medication_status');
            $table->boolean('requires_acknowledgment')->default(true)->after('sensitive_flag');
        });

        Schema::table('respite_evidence_packs', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('stay_id')->constrained('respite_bookings')->nullOnDelete();
            $table->string('pack_type')->default('post_respite')->after('status');
            $table->json('included_documents')->nullable()->after('items');
            $table->json('included_incidents')->nullable()->after('included_documents');
            $table->json('included_medications')->nullable()->after('included_incidents');
            $table->json('included_daily_notes')->nullable()->after('included_medications');
            $table->json('included_handovers')->nullable()->after('included_daily_notes');
            $table->text('coordinator_notes')->nullable()->after('summary');
            $table->text('family_feedback')->nullable()->after('coordinator_notes');
            $table->boolean('exported')->default(false)->after('sealed_at');
            $table->timestamp('exported_at')->nullable()->after('exported');
        });
    }

    public function down(): void
    {
        // Remove added columns from existing tables
        Schema::table('respite_evidence_packs', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropColumn([
                'booking_id', 'pack_type', 'included_documents', 'included_incidents',
                'included_medications', 'included_daily_notes', 'included_handovers',
                'coordinator_notes', 'family_feedback', 'exported', 'exported_at',
            ]);
        });

        Schema::table('respite_handover_notes', function (Blueprint $table) {
            $table->dropForeign(['from_shift_id']);
            $table->dropForeign(['to_shift_id']);
            $table->dropColumn([
                'from_shift_id', 'to_shift_id', 'key_points', 'pending_tasks',
                'medication_status', 'incident_refs', 'requires_acknowledgment',
            ]);
        });

        Schema::table('respite_calendar_events', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn([
                'client_id', 'title', 'description', 'all_day', 'recurrence_rule',
                'buffer_before_minutes', 'buffer_after_minutes', 'conflicts_detected', 'conflict_details',
            ]);
        });

        Schema::table('respite_stays', function (Blueprint $table) {
            $table->dropColumn([
                'arrival_checklist', 'arrival_checklist_complete', 'discharge_checklist',
                'discharge_checklist_complete', 'post_respite_summary', 'transport_arrangements',
            ]);
        });

        Schema::table('respite_bookings', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['medications_reconciled_by']);
            $table->dropColumn([
                'location_id', 'eligibility_checks', 'consent_records', 'funding_verification',
                'pre_arrival_checklist', 'medications_reconciled', 'medications_reconciled_at',
                'medications_reconciled_by',
            ]);
        });

        Schema::dropIfExists('respite_risk_plan_activations');
        Schema::dropIfExists('respite_daily_notes');
        Schema::dropIfExists('respite_audit_logs');
        Schema::dropIfExists('respite_tasks');
        Schema::dropIfExists('respite_procedure_runs');
    }
};
