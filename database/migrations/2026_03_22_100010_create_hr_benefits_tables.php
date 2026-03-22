<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Benefit plans
        Schema::create('hr_benefit_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('type'); // kiwisaver, health_insurance, life_insurance, other
            $table->string('provider')->nullable();
            $table->text('description')->nullable();
            $table->decimal('employer_contribution_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });

        // Benefit enrollments
        Schema::create('hr_benefit_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('benefit_plan_id')->constrained('hr_benefit_plans')->cascadeOnDelete();
            $table->date('enrollment_date');
            $table->string('status')->default('active'); // active, opted_out, suspended, terminated
            $table->decimal('employee_contribution_rate', 5, 2)->default(0);
            $table->decimal('employer_contribution_rate', 5, 2)->default(0);
            $table->date('opt_out_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_profile_id'], 'hr_benefit_enroll_tenant_emp');
            $table->index(['tenant_id', 'benefit_plan_id'], 'hr_benefit_enroll_tenant_plan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_benefit_enrollments');
        Schema::dropIfExists('hr_benefit_plans');
    }
};
