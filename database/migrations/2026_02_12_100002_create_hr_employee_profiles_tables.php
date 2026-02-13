<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Employee profiles
        Schema::create('hr_employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_number')->unique();
            $table->text('date_of_birth')->nullable()->comment('encrypted');
            $table->string('gender')->nullable();
            $table->string('ethnicity')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('personal_phone')->nullable();
            $table->text('home_address')->nullable()->comment('encrypted');
            $table->string('work_email');
            $table->string('work_phone')->nullable();
            $table->string('position_title');
            $table->string('position_role');
            $table->string('employment_type');
            $table->string('contract_type')->default('individual');
            $table->decimal('hours_per_week', 8, 2)->nullable();
            $table->text('hourly_rate')->nullable()->comment('encrypted');
            $table->text('annual_salary')->nullable()->comment('encrypted');
            $table->string('pay_frequency')->default('fortnightly');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->string('termination_reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('primary_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->json('secondary_site_ids')->nullable();
            $table->json('emergency_contacts')->nullable();
            $table->text('bank_account')->nullable()->comment('encrypted');
            $table->text('ird_number')->nullable()->comment('encrypted');
            $table->string('tax_code')->nullable();
            $table->decimal('kiwisaver_rate', 4, 2)->nullable();
            $table->boolean('can_drive_clients')->default(false);
            $table->datetime('driver_eligibility_reviewed_at')->nullable();
            $table->boolean('is_first_aider')->default(false);
            $table->boolean('is_fire_warden')->default(false);
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('restricted_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['primary_site_id']);
        });

        // Profile change audit trail
        Schema::create('hr_employee_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('field_name');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->date('effective_from');
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_profile_id', 'field_name'], 'hr_profile_versions_profile_field_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_profile_versions');
        Schema::dropIfExists('hr_employee_profiles');
    }
};
