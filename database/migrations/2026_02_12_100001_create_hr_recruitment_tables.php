<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Candidate profiles
        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('preferred_name')->nullable();
            $table->string('personal_email');
            $table->string('personal_phone')->nullable();
            $table->string('source'); // website|referral|agency|internal|other
            $table->string('source_detail')->nullable();
            $table->string('status')->default('website_submission');
            $table->datetime('current_stage_entered_at')->nullable();
            $table->datetime('privacy_consent_given_at')->nullable();
            $table->string('privacy_consent_ip')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['personal_email']);
        });

        // Job applications
        Schema::create('hr_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->string('position_title');
            $table->string('position_role');
            $table->foreignId('target_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('cv_storage_path')->nullable();
            $table->string('cv_original_name')->nullable();
            $table->text('cover_letter')->nullable();
            $table->json('answers')->nullable();
            $table->string('status')->default('active');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'status']);
            $table->index(['tenant_id', 'position_role']);
        });

        // Interview scheduling and outcomes
        Schema::create('hr_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            $table->datetime('scheduled_at');
            $table->integer('duration_minutes')->default(60);
            $table->string('location')->nullable();
            $table->string('interview_type')->default('in_person');
            $table->json('interviewers')->nullable();
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->string('outcome')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'scheduled_at']);
        });

        // Reference checks
        Schema::create('hr_reference_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            $table->string('referee_name');
            $table->string('referee_email')->nullable();
            $table->string('referee_phone')->nullable();
            $table->string('referee_relationship');
            $table->string('status')->default('requested');
            $table->datetime('requested_at')->nullable();
            $table->datetime('received_at')->nullable();
            $table->datetime('verified_at')->nullable();
            $table->text('reference_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Employment offers
        Schema::create('hr_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('position_title');
            $table->string('position_role');
            $table->date('proposed_start_date');
            $table->string('employment_type');
            $table->decimal('hours_per_week', 8, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('annual_salary', 12, 2)->nullable();
            $table->foreignId('primary_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->text('conditions')->nullable();
            $table->string('approval_status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->datetime('sent_at')->nullable();
            $table->string('response')->nullable();
            $table->datetime('response_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->boolean('work_email_provisioned')->default(false);
            $table->string('work_email')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['application_id']);
            $table->index(['approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offers');
        Schema::dropIfExists('hr_reference_checks');
        Schema::dropIfExists('hr_interviews');
        Schema::dropIfExists('hr_applications');
        Schema::dropIfExists('hr_candidates');
    }
};
