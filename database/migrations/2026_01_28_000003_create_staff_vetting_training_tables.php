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
        // Staff background checks (DBS, police checks, references)
        Schema::create('staff_background_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('check_type', [
                'dbs_basic',
                'dbs_standard',
                'dbs_enhanced',
                'dbs_enhanced_barred',
                'police_check',
                'reference_check',
                'employment_history',
                'qualification_verification',
                'right_to_work',
                'other'
            ]);

            $table->enum('status', [
                'pending',
                'requested',
                'in_progress',
                'clear',
                'conditional',
                'flagged',
                'failed',
                'expired',
                'renewal_due'
            ])->default('pending');

            // Check details
            $table->string('reference_number')->nullable(); // DBS certificate number
            $table->string('provider')->nullable(); // DBS online, ACRO, etc.
            $table->date('check_date')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expires_at')->nullable();

            // Results
            $table->boolean('disclosures_present')->default(false);
            $table->text('disclosure_details')->nullable();
            $table->text('conditions')->nullable(); // Any conditions on the clearance

            // Risk assessment (if disclosures present)
            $table->boolean('risk_assessed')->default(false);
            $table->foreignId('risk_assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('risk_assessed_at')->nullable();
            $table->text('risk_assessment')->nullable();
            $table->enum('risk_decision', ['approved', 'approved_with_conditions', 'declined'])->nullable();

            // Document storage
            $table->string('certificate_path')->nullable();
            $table->string('supporting_docs_path')->nullable();

            // Update service
            $table->boolean('enrolled_in_update_service')->default(false);
            $table->string('update_service_reference')->nullable();

            // Verification
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            // Renewal tracking
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->integer('renewal_reminder_days_before')->default(60);

            // Notes
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'check_type', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('expires_at');
        });

        // Training courses catalog
        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique()->nullable(); // Course code/reference
            $table->string('category'); // Safeguarding, Clinical Skills, Health and Safety, etc.

            $table->text('description')->nullable();
            $table->text('learning_outcomes')->nullable();
            $table->integer('duration_minutes')->nullable();

            // Validity and renewal
            $table->boolean('requires_renewal')->default(true);
            $table->integer('validity_period_months')->nullable(); // null = lifetime validity
            $table->integer('renewal_reminder_months')->default(1);

            // Requirements
            $table->boolean('mandatory_for_all')->default(false);
            $table->json('mandatory_for_roles')->nullable(); // Array of role names
            $table->json('prerequisites')->nullable(); // Array of course IDs that must be completed first

            // Assessment
            $table->boolean('requires_assessment')->default(false);
            $table->integer('pass_mark_percentage')->nullable();

            // Provider
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('delivery_method')->nullable(); // classroom, elearning, blended, practical, etc.

            // Cost
            $table->decimal('cost_per_person', 8, 2)->nullable();

            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'active']);
            $table->index('mandatory_for_all');
        });

        // Staff training records
        Schema::create('staff_training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained('training_courses')->cascadeOnDelete();

            $table->enum('status', [
                'not_started',
                'in_progress',
                'completed',
                'passed',
                'failed',
                'expired',
                'exempted'
            ])->default('not_started');

            // Enrollment
            $table->timestamp('enrolled_at')->nullable();
            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Completion
            $table->timestamp('completed_at')->nullable();
            $table->date('completion_date')->nullable(); // Official completion date from provider
            $table->timestamp('expires_at')->nullable();

            // Assessment
            $table->integer('assessment_score')->nullable(); // Percentage
            $table->boolean('assessment_passed')->nullable();
            $table->text('assessment_notes')->nullable();

            // Certificate
            $table->string('certificate_number')->nullable();
            $table->string('certificate_path')->nullable();

            // Provider details
            $table->string('provider')->nullable();
            $table->string('trainer_name')->nullable();
            $table->string('venue')->nullable();

            // Exemption (if status = exempted)
            $table->text('exemption_reason')->nullable();
            $table->foreignId('exempted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('exempted_at')->nullable();

            // Renewal tracking
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->foreignId('renewed_by_record_id')->nullable()->constrained('staff_training_records')->nullOnDelete();

            // CPD points (if applicable)
            $table->decimal('cpd_points', 5, 2)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'training_course_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('expires_at');
        });

        // Competency framework
        Schema::create('competency_frameworks', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('role')->nullable(); // support_worker, team_leader, etc.
            $table->text('description')->nullable();
            $table->integer('version')->default(1);
            $table->timestamp('effective_from')->nullable();

            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['role', 'active']);
        });

        // Competency items within frameworks
        Schema::create('competency_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('competency_frameworks')->cascadeOnDelete();

            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description');
            $table->string('category')->nullable();
            $table->string('required_proficiency')->default('competent'); // developing, competent, proficient, expert
            $table->json('assessment_criteria')->nullable();

            $table->integer('order')->default(0);

            $table->timestamps();

            $table->index(['framework_id', 'order']);
        });

        // Staff competency assessments
        Schema::create('staff_competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('competency_framework_id')->constrained('competency_frameworks')->cascadeOnDelete();

            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessment_date');

            $table->enum('overall_outcome', [
                'competent',
                'competent_with_development',
                'developing',
                'not_competent'
            ]);

            $table->json('item_assessments')->nullable(); // Array of {competency_item_id, achieved, proficiency_level, notes}

            $table->text('strengths')->nullable();
            $table->text('development_areas')->nullable();
            $table->text('action_plan')->nullable();

            $table->timestamp('next_review_date')->nullable();
            $table->text('assessor_notes')->nullable();

            // Sign-off
            $table->boolean('staff_acknowledged')->default(false);
            $table->timestamp('staff_acknowledged_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'assessment_date']);
            $table->index(['user_id', 'next_review_date']);
            $table->index('next_review_date');
        });

        // Staff inductions
        Schema::create('staff_inductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->foreignId('induction_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date');
            $table->date('expected_completion_date')->nullable();
            $table->date('actual_completion_date')->nullable();

            $table->enum('status', [
                'not_started',
                'in_progress',
                'completed',
                'extended',
                'incomplete'
            ])->default('not_started');

            // Checklist (JSON array of checklist items with completion status)
            $table->json('checklist_data')->nullable();

            // Sections
            $table->boolean('organization_intro_completed')->default(false);
            $table->boolean('policies_reviewed')->default(false);
            $table->boolean('systems_training_completed')->default(false);
            $table->boolean('shadowing_completed')->default(false);
            $table->boolean('competency_assessed')->default(false);
            $table->boolean('mandatory_training_completed')->default(false);

            // Probation
            $table->integer('probation_period_days')->default(90);
            $table->date('probation_end_date')->nullable();
            $table->enum('probation_outcome', [
                'passed',
                'extended',
                'failed',
                'pending'
            ])->nullable();
            $table->text('probation_notes')->nullable();

            $table->text('completion_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index('expected_completion_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_inductions');
        Schema::dropIfExists('staff_competency_assessments');
        Schema::dropIfExists('competency_items');
        Schema::dropIfExists('competency_frameworks');
        Schema::dropIfExists('staff_training_records');
        Schema::dropIfExists('training_courses');
        Schema::dropIfExists('staff_background_checks');
    }
};
