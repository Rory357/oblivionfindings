<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('payroll_run_id')->nullable()->constrained('hr_payroll_runs')->nullOnDelete();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Pay period
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->date('payment_date')->nullable();

            // Earnings
            $table->decimal('gross_pay', 10, 2);
            $table->decimal('regular_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->text('hourly_rate')->nullable(); // encrypted

            // Statutory deductions
            $table->decimal('paye', 10, 2)->default(0);
            $table->decimal('acc_levy', 10, 2)->default(0);
            $table->decimal('kiwisaver_employee', 10, 2)->default(0);
            $table->decimal('kiwisaver_employer', 10, 2)->default(0);
            $table->decimal('student_loan', 10, 2)->default(0);
            $table->decimal('holiday_pay', 10, 2)->default(0);

            // Totals
            $table->decimal('total_deductions', 10, 2)->default(0);
            $table->decimal('net_pay', 10, 2)->default(0);

            // Additional components
            $table->json('allowances')->nullable();
            $table->json('other_deductions')->nullable();

            // Tax / KiwiSaver info
            $table->string('tax_code')->default('M');
            $table->decimal('kiwisaver_rate', 4, 2)->default(3.00);

            // Status & PDF
            $table->string('status')->default('draft'); // draft, approved, paid
            $table->string('pdf_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Composite indexes
            $table->index(['tenant_id', 'user_id', 'pay_period_start']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
