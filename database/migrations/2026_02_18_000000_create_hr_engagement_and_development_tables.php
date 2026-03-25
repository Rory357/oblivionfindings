<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_development_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('growth');
            $table->string('competency_area')->nullable();
            $table->unsignedTinyInteger('target_level')->nullable();
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->string('status')->default('not_started');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('review_frequency')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_user_id', 'status'], 'hr_dev_goals_employee_status_idx');
            $table->index(['tenant_id', 'due_date'], 'hr_dev_goals_tenant_due_idx');
        });

        Schema::create('hr_engagement_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('survey_type')->default('pulse'); // pulse, enps, engagement
            $table->string('status')->default('draft'); // draft, published, closed
            $table->boolean('is_anonymous')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'starts_at'], 'hr_eng_surveys_tenant_status_start_idx');
            $table->index(['survey_type', 'status'], 'hr_eng_surveys_type_status_idx');
        });

        Schema::create('hr_engagement_survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('hr_engagement_surveys')->cascadeOnDelete();
            $table->string('question_type')->default('scale'); // enps, scale, text, choice, boolean
            $table->text('question_text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['survey_id', 'sort_order'], 'hr_eng_survey_questions_order_idx');
        });

        Schema::create('hr_engagement_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('hr_engagement_surveys')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('respondent_hash', 120)->nullable();
            $table->json('answers');
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['survey_id', 'user_id'], 'hr_eng_survey_response_user_unique');
            $table->unique(['survey_id', 'respondent_hash'], 'hr_eng_survey_response_hash_unique');
            $table->index(['survey_id', 'submitted_at'], 'hr_eng_survey_response_submitted_idx');
        });

        Schema::create('hr_engagement_action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('hr_engagement_surveys')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium'); // low, medium, high
            $table->string('status')->default('open'); // open, in_progress, completed, cancelled
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['owner_user_id', 'status'], 'hr_eng_action_owner_status_idx');
            $table->index(['survey_id', 'priority'], 'hr_eng_action_survey_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_engagement_action_plans');
        Schema::dropIfExists('hr_engagement_survey_responses');
        Schema::dropIfExists('hr_engagement_survey_questions');
        Schema::dropIfExists('hr_engagement_surveys');
        Schema::dropIfExists('hr_development_goals');
    }
};
