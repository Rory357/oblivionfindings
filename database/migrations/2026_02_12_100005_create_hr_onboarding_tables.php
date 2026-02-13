<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Onboarding templates per role/site type
        Schema::create('hr_onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('role');
            $table->string('site_type')->default('all');
            $table->json('tasks');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'role', 'site_type']);
        });

        // Onboarding checklists assigned to employees
        Schema::create('hr_onboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('template_key');
            $table->string('status')->default('not_started');
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_profile_id', 'status']);
        });

        // Individual onboarding tasks
        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('hr_onboarding_checklists')->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_to_role')->nullable();
            $table->string('status')->default('pending');
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evidence_path')->nullable();
            $table->boolean('sign_off_required')->default(false);
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('signed_off_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['checklist_id', 'category', 'sort_order']);
        });

        // Offboarding checklists (same structure as onboarding checklists)
        Schema::create('hr_offboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('template_key');
            $table->string('status')->default('not_started');
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['employee_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_checklists');
        Schema::dropIfExists('hr_onboarding_tasks');
        Schema::dropIfExists('hr_onboarding_checklists');
        Schema::dropIfExists('hr_onboarding_templates');
    }
};
