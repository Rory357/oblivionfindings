<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_succession_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->string('role_title');
            $table->string('department')->nullable();
            $table->string('risk_level')->default('medium'); // low, medium, high, critical
            $table->foreignId('current_holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('hr_succession_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('succession_plan_id')->constrained('hr_succession_plans')->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('readiness')->default('developing'); // ready_now, ready_1_year, ready_2_years, developing
            $table->text('development_needs')->nullable();
            $table->text('strengths')->nullable();
            $table->integer('overall_rating')->nullable(); // 1-5
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assessed_at')->nullable();
            $table->timestamps();

            $table->index(['succession_plan_id', 'readiness']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_succession_candidates');
        Schema::dropIfExists('hr_succession_plans');
    }
};
