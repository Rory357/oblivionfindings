<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Salary bands
        Schema::create('hr_salary_bands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('position_role');
            $table->string('band_name');
            $table->text('min_salary')->comment('encrypted');
            $table->text('mid_salary')->comment('encrypted');
            $table->text('max_salary')->comment('encrypted');
            $table->text('min_hourly')->comment('encrypted');
            $table->text('max_hourly')->comment('encrypted');
            $table->string('currency')->default('NZD');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'position_role']);
        });

        // Compensation history
        Schema::create('hr_compensation_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('change_type'); // initial, review, promotion, adjustment, correction
            $table->text('previous_hourly_rate')->nullable()->comment('encrypted');
            $table->text('new_hourly_rate')->comment('encrypted');
            $table->text('previous_annual_salary')->nullable()->comment('encrypted');
            $table->text('new_annual_salary')->comment('encrypted');
            $table->decimal('change_percentage', 5, 2)->nullable();
            $table->text('reason')->nullable();
            $table->date('effective_date');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_profile_id', 'effective_date'], 'hr_comp_hist_tenant_emp_date');
        });

        // Compensation reviews (cycles)
        Schema::create('hr_compensation_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('review_cycle'); // annual, mid_year, ad_hoc
            $table->date('effective_date');
            $table->string('status')->default('planning'); // planning, in_progress, approved, applied
            $table->text('budget_amount')->nullable()->comment('encrypted');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Compensation review line items
        Schema::create('hr_compensation_review_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compensation_review_id')->constrained('hr_compensation_reviews')->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->text('current_salary')->comment('encrypted');
            $table->text('proposed_salary')->comment('encrypted');
            $table->decimal('change_percentage', 5, 2);
            $table->text('justification')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_compensation_review_items');
        Schema::dropIfExists('hr_compensation_reviews');
        Schema::dropIfExists('hr_compensation_history');
        Schema::dropIfExists('hr_salary_bands');
    }
};
