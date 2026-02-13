<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Supervision / one-on-one session notes
        Schema::create('hr_supervision_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('session_date');
            $table->string('session_type')->default('one_on_one');
            $table->integer('duration_minutes')->nullable();
            $table->text('topics_discussed');
            $table->json('actions_agreed')->nullable();
            $table->text('employee_comments')->nullable();
            $table->boolean('employee_acknowledged')->default(false);
            $table->datetime('employee_acknowledged_at')->nullable();
            $table->date('next_session_date')->nullable();
            $table->boolean('is_visible_to_employee')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_user_id', 'session_date']);
            $table->index(['supervisor_user_id', 'session_date']);
        });

        // Annual / periodic performance reviews
        Schema::create('hr_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('review_type')->default('annual');
            $table->date('review_period_start');
            $table->date('review_period_end');
            $table->string('status')->default('draft');
            $table->tinyInteger('overall_rating')->nullable();
            $table->text('strengths')->nullable();
            $table->text('development_areas')->nullable();
            $table->json('goals')->nullable();
            $table->json('training_recommendations')->nullable();
            $table->text('employee_comments')->nullable();
            $table->boolean('employee_signed_off')->default(false);
            $table->datetime('employee_signed_off_at')->nullable();
            $table->boolean('manager_signed_off')->default(false);
            $table->datetime('manager_signed_off_at')->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_user_id', 'review_type']);
            $table->index(['tenant_id', 'status']);
        });

        // Probation reviews
        Schema::create('hr_probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('review_number');
            $table->date('review_date');
            $table->string('status')->default('scheduled');
            $table->json('areas_assessed')->nullable();
            $table->text('concerns')->nullable();
            $table->string('recommendation')->nullable();
            $table->integer('extension_weeks')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('employee_acknowledged')->default(false);
            $table->datetime('employee_acknowledged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_user_id', 'review_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_probation_reviews');
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_supervision_notes');
    }
};
