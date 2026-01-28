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
        // Data retention policies
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->id();

            $table->string('model_type'); // Eloquent model class name
            $table->string('policy_name');
            $table->text('description')->nullable();

            // Retention rules
            $table->integer('retention_period_years')->nullable(); // null = indefinite
            $table->integer('archive_after_years')->nullable(); // Move to archive storage
            $table->integer('hard_delete_after_years')->nullable(); // Permanent deletion

            // Conditions for retention
            $table->json('retention_conditions')->nullable(); // e.g., status = closed, etc.
            $table->boolean('applies_to_soft_deleted')->default(true);

            // Exemptions
            $table->boolean('legal_hold_exemption')->default(true); // Don't delete if legal hold
            $table->boolean('active_case_exemption')->default(true); // Don't delete if related to active case

            // Legal basis
            $table->text('legal_basis')->nullable(); // Why we retain/delete this data
            $table->text('business_justification')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('last_applied_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['model_type', 'active']);
        });

        // Data subject requests (GDPR)
        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();

            $table->enum('request_type', [
                'access', // Right to access (Article 15)
                'rectification', // Right to rectification (Article 16)
                'erasure', // Right to erasure/be forgotten (Article 17)
                'restriction', // Right to restriction of processing (Article 18)
                'portability', // Right to data portability (Article 20)
                'objection', // Right to object (Article 21)
                'automated_decision' // Rights related to automated decision making (Article 22)
            ]);

            // Subject of request
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_name')->nullable(); // For non-registered subjects
            $table->string('subject_email')->nullable();

            // Request details
            $table->text('request_details');
            $table->json('specific_data_requested')->nullable(); // Specific fields/records requested

            // Verification
            $table->enum('identity_verified', ['pending', 'verified', 'failed'])->default('pending');
            $table->timestamp('identity_verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_method')->nullable();

            // Processing
            $table->enum('status', [
                'received',
                'under_review',
                'identity_verification',
                'in_progress',
                'completed',
                'rejected',
                'withdrawn'
            ])->default('received');

            $table->timestamp('received_at');
            $table->date('due_date'); // 30 days from receipt (GDPR requirement)
            $table->boolean('extension_requested')->default(false);
            $table->date('extended_due_date')->nullable();
            $table->text('extension_reason')->nullable();

            // Assignment
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Completion
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();

            // For access requests: export location
            $table->string('export_path')->nullable();
            $table->timestamp('export_generated_at')->nullable();
            $table->timestamp('export_accessed_at')->nullable();

            // For erasure requests: confirmation
            $table->boolean('erasure_confirmed')->default(false);
            $table->json('data_erased')->nullable(); // What was erased
            $table->json('data_retained')->nullable(); // What was retained and why

            // Rejection
            $table->text('rejection_reason')->nullable();
            $table->string('rejection_legal_basis')->nullable();

            // Communication log
            $table->json('communications')->nullable(); // Array of communication records

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('reference_number');
            $table->index('received_at');
        });

        // Data exports (for portability/access requests)
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_subject_request_id')->nullable()->constrained('data_subject_requests')->nullOnDelete();

            $table->string('export_type'); // full, partial, specific_records
            $table->json('included_models')->nullable(); // What was included

            $table->string('export_format'); // json, csv, pdf, zip
            $table->string('export_path');
            $table->integer('file_size_bytes')->nullable();

            $table->timestamp('generated_at');
            $table->foreignId('generated_by_user_id')->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at')->nullable(); // Auto-delete export after X days
            $table->boolean('password_protected')->default(false);

            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            $table->boolean('deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();

            $table->timestamps();

            $table->index(['data_subject_request_id', 'generated_at']);
            $table->index('expires_at');
        });

        // Legal holds (prevent deletion)
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();

            $table->string('hold_reference')->unique();
            $table->string('hold_type'); // litigation, investigation, regulatory, audit
            $table->text('reason');

            // Scope of hold
            $table->morphs('holdable'); // Can apply to any model
            $table->json('related_records')->nullable(); // Additional related record IDs

            $table->enum('status', ['active', 'released'])->default('active');

            $table->timestamp('imposed_at');
            $table->foreignId('imposed_by_user_id')->constrained('users')->nullOnDelete();

            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();

            $table->date('review_date')->nullable();
            $table->text('legal_authority')->nullable(); // Court order, regulatory requirement, etc.

            $table->timestamps();

            $table->index(['holdable_type', 'holdable_id', 'status']);
            $table->index(['status', 'review_date']);
        });

        // Anonymization log (for GDPR compliance)
        Schema::create('anonymization_logs', function (Blueprint $table) {
            $table->id();

            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('reason'); // retention_period_expired, erasure_request, policy_requirement

            $table->json('fields_anonymized')->nullable(); // Which fields were anonymized
            $table->json('anonymization_methods')->nullable(); // How they were anonymized

            $table->foreignId('data_subject_request_id')->nullable()->constrained('data_subject_requests')->nullOnDelete();

            $table->timestamp('anonymized_at');
            $table->foreignId('anonymized_by_user_id')->constrained('users')->nullOnDelete();

            $table->boolean('reversible')->default(false);
            $table->string('reversal_key_path')->nullable(); // If reversible, where is the key stored

            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('anonymized_at');
        });

        // Data breach log (GDPR Article 33)
        Schema::create('data_breach_logs', function (Blueprint $table) {
            $table->id();
            $table->string('breach_reference')->unique();

            $table->timestamp('discovered_at');
            $table->foreignId('discovered_by_user_id')->constrained('users')->nullOnDelete();

            $table->text('nature_of_breach'); // What happened
            $table->json('affected_data_categories')->nullable(); // What types of data
            $table->integer('approximate_individuals_affected')->nullable();

            $table->text('likely_consequences');
            $table->text('measures_taken');

            // Notification requirements
            $table->boolean('requires_authority_notification')->default(false);
            $table->timestamp('authority_notified_at')->nullable();
            $table->string('authority_reference')->nullable();

            $table->boolean('requires_subject_notification')->default(false);
            $table->timestamp('subjects_notified_at')->nullable();
            $table->text('notification_method')->nullable();

            $table->enum('status', [
                'discovered',
                'under_investigation',
                'contained',
                'notified',
                'resolved'
            ])->default('discovered');

            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'discovered_at']);
        });

        // Privacy impact assessments
        Schema::create('privacy_impact_assessments', function (Blueprint $table) {
            $table->id();

            $table->string('assessment_name');
            $table->string('project_or_process'); // What is being assessed
            $table->text('description');

            $table->enum('assessment_type', ['new_project', 'process_change', 'system_upgrade', 'periodic_review']);

            $table->foreignId('assessor_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assessment_date');

            // Data processing details
            $table->json('personal_data_types')->nullable();
            $table->json('data_subjects')->nullable();
            $table->text('processing_purpose');
            $table->text('legal_basis');

            // Risk assessment
            $table->json('identified_risks')->nullable();
            $table->enum('overall_risk_level', ['low', 'medium', 'high', 'very_high']);

            // Mitigation
            $table->json('mitigation_measures')->nullable();
            $table->enum('residual_risk_level', ['low', 'medium', 'high', 'very_high']);

            // Approval
            $table->enum('outcome', ['approved', 'approved_with_conditions', 'rejected', 'requires_dpo_review'])->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->date('review_date')->nullable();

            $table->timestamps();

            $table->index(['overall_risk_level', 'assessment_date']);
            $table->index('review_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_impact_assessments');
        Schema::dropIfExists('data_breach_logs');
        Schema::dropIfExists('anonymization_logs');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('data_retention_policies');
    }
};
