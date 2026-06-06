<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Main safeguarding concerns table
        Schema::create('safeguarding_concerns', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique(); // e.g., SG-2026-001

            // Subject of concern (polymorphic - can be Client, User/Staff, or Other)
            $table->nullableMorphs('subject', 'sg_concern_subject_idx');
            $table->string('subject_name')->nullable(); // For external/unnamed subjects

            // Concern details
            $table->string('concern_type'); // abuse, neglect, self-neglect, financial, organizational
            $table->enum('abuse_category', [
                'physical',
                'sexual',
                'emotional',
                'psychological',
                'financial',
                'discriminatory',
                'institutional',
                'neglect',
                'self-neglect',
                'domestic_violence',
                'modern_slavery',
                'other'
            ])->nullable();

            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->text('description');
            $table->timestamp('occurred_at')->nullable(); // When incident occurred
            $table->text('location')->nullable();

            // Alleged perpetrator (polymorphic or named)
            $table->nullableMorphs('alleged_perpetrator', 'sg_concern_perpetrator_idx');
            $table->string('alleged_perpetrator_name')->nullable();
            $table->text('alleged_perpetrator_details')->nullable();

            // Reporter information
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reported_by_name')->nullable(); // For external reporters
            $table->string('reported_by_role')->nullable();
            $table->timestamp('reported_at');
            $table->text('reporter_notes')->nullable();

            // Witnesses
            $table->json('witnesses')->nullable(); // Array of witness details

            // Status workflow
            $table->enum('status', [
                'reported',
                'triaged',
                'investigating',
                'action_plan',
                'monitoring',
                'closed',
                'referred_external'
            ])->default('reported');

            // Immediate actions taken
            $table->text('immediate_actions')->nullable();
            $table->boolean('subject_informed')->default(false);
            $table->timestamp('subject_informed_at')->nullable();
            $table->boolean('requires_external_referral')->default(false);

            // Risk assessment
            $table->enum('current_risk_level', ['low', 'medium', 'high', 'critical'])->nullable();
            $table->text('protective_measures')->nullable();

            // Assignment
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Closure
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_summary')->nullable();
            $table->text('lessons_learned')->nullable();

            // Related records
            $table->foreignId('related_incident_id')->nullable()->constrained('client_incidents')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('reference_number');
            $table->index(['status', 'severity']);
            $table->index('reported_at');
            $table->index('assigned_to_user_id');
        });

        // Safeguarding investigations
        Schema::create('safeguarding_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safeguarding_concern_id')->constrained('safeguarding_concerns')->cascadeOnDelete();

            $table->string('investigation_type'); // internal, external, joint
            $table->foreignId('lead_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('investigation_team')->nullable(); // Array of team member IDs

            $table->timestamp('started_at');
            $table->timestamp('target_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->enum('status', [
                'planned',
                'in_progress',
                'paused',
                'completed',
                'abandoned'
            ])->default('planned');

            // Investigation details
            $table->text('terms_of_reference')->nullable();
            $table->text('methodology')->nullable();
            $table->json('evidence_collected')->nullable(); // Array of evidence references
            $table->json('interviews_conducted')->nullable(); // Array of interview summaries

            // Findings
            $table->text('findings')->nullable();
            $table->enum('outcome', [
                'substantiated',
                'partially_substantiated',
                'unsubstantiated',
                'inconclusive',
                'malicious',
                'ongoing'
            ])->nullable();

            $table->text('recommendations')->nullable();
            $table->json('action_plan')->nullable();

            // Reporting
            $table->boolean('report_completed')->default(false);
            $table->string('report_path')->nullable(); // Path to investigation report

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['safeguarding_concern_id', 'status'], 'sg_investigation_concern_status_idx');
            $table->index('lead_investigator_id');
        });

        // External authority reports
        Schema::create('safeguarding_external_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safeguarding_concern_id')->constrained('safeguarding_concerns')->cascadeOnDelete();

            $table->string('authority_type'); // police, hdc, health_nz, whaikaha, oranga_tamariki, coroner, other
            $table->string('authority_name');
            $table->string('authority_contact')->nullable();
            $table->string('authority_reference')->nullable(); // Their case reference number

            $table->timestamp('reported_at');
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_method'); // phone, email, online_form, in_person

            $table->text('report_summary');
            $table->string('report_document_path')->nullable(); // Copy of report sent

            // Follow-up
            $table->boolean('acknowledgement_received')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_reference')->nullable();

            $table->enum('authority_action', [
                'investigating',
                'no_action',
                'advice_given',
                'referred_on',
                'ongoing',
                'closed'
            ])->nullable();

            $table->text('authority_feedback')->nullable();
            $table->timestamp('authority_feedback_at')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['safeguarding_concern_id', 'authority_type'], 'sg_ext_report_concern_auth_idx');
            $table->index('reported_at');
        });

        // Safeguarding risk assessments
        Schema::create('safeguarding_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safeguarding_concern_id')->constrained('safeguarding_concerns')->cascadeOnDelete();

            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');

            // Risk factors
            $table->json('risk_factors')->nullable(); // Array of identified risk factors
            $table->json('protective_factors')->nullable(); // Array of protective factors

            // Risk levels
            $table->enum('risk_to_self', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->enum('risk_to_others', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->enum('risk_from_others', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->enum('overall_risk_level', ['low', 'medium', 'high', 'critical']);

            // Mental capacity consideration
            $table->boolean('capacity_assessed')->default(false);
            $table->enum('mental_capacity', ['has_capacity', 'lacks_capacity', 'fluctuating', 'not_assessed'])->nullable();
            $table->text('capacity_notes')->nullable();

            // Immediate actions required
            $table->text('immediate_actions_required')->nullable();
            $table->json('protective_measures')->nullable(); // Array of protective measures

            // Multi-agency involvement
            $table->boolean('multi_agency_required')->default(false);
            $table->json('agencies_involved')->nullable(); // Array of agency details

            // Review
            $table->timestamp('next_review_date')->nullable();
            $table->text('assessment_notes')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['safeguarding_concern_id', 'assessed_at'], 'sg_risk_assess_concern_date_idx');
            $table->index('overall_risk_level');
            $table->index('next_review_date');
        });

        // Safeguarding action plans
        Schema::create('safeguarding_action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safeguarding_concern_id')->constrained('safeguarding_concerns')->cascadeOnDelete();

            $table->string('action_description');
            $table->enum('action_type', [
                'protective_measure',
                'support_service',
                'policy_change',
                'training',
                'supervision',
                'monitoring',
                'referral',
                'investigation',
                'other'
            ]);

            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_date')->nullable();

            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->integer('priority')->default(3); // 1=high, 2=medium, 3=low

            $table->text('completion_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['safeguarding_concern_id', 'status'], 'sg_action_plan_concern_status_idx');
            $table->index(['assigned_to_user_id', 'due_date']);
        });

        // Safeguarding alerts (on client/staff profiles)
        Schema::create('safeguarding_alerts', function (Blueprint $table) {
            $table->id();
            $table->morphs('alertable', 'sg_alert_alertable_idx'); // Client or User/Staff

            $table->foreignId('safeguarding_concern_id')->nullable()->constrained('safeguarding_concerns')->nullOnDelete();

            $table->enum('alert_type', [
                'vulnerable_adult',
                'perpetrator',
                'witness',
                'risk_to_self',
                'risk_to_others',
                'requires_monitoring',
                'capacity_concerns',
                'other'
            ]);

            $table->string('alert_summary');
            $table->text('alert_details')->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');

            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();

            // Review
            $table->timestamp('last_reviewed_at')->nullable();
            $table->foreignId('last_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_review_date')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['alertable_type', 'alertable_id', 'active']);
            $table->index('alert_type');
            $table->index('next_review_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('safeguarding_alerts');
        Schema::dropIfExists('safeguarding_action_plans');
        Schema::dropIfExists('safeguarding_risk_assessments');
        Schema::dropIfExists('safeguarding_external_reports');
        Schema::dropIfExists('safeguarding_investigations');
        Schema::dropIfExists('safeguarding_concerns');
    }
};
