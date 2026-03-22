<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'category']);
        });

        Schema::create('hr_employee_skills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('hr_skills')->cascadeOnDelete();
            $table->string('proficiency_level'); // beginner, intermediate, advanced, expert
            $table->boolean('self_assessed')->default(true);
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('assessed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_profile_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_skills');
        Schema::dropIfExists('hr_skills');
    }
};
