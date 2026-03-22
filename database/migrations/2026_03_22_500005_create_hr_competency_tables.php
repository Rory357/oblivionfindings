<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('hr_competency_assessments');
        Schema::dropIfExists('hr_competencies');

        Schema::create('hr_competencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->json('proficiency_levels');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_competency_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('hr_competencies')->cascadeOnDelete();
            $table->integer('assessed_level');
            $table->foreignId('assessed_by')->constrained('users');
            $table->date('assessment_date');
            $table->text('notes')->nullable();
            $table->foreignId('performance_review_id')->nullable()->constrained('hr_performance_reviews')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_profile_id', 'competency_id'], 'hr_comp_assess_profile_comp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_competency_assessments');
        Schema::dropIfExists('hr_competencies');
    }
};
