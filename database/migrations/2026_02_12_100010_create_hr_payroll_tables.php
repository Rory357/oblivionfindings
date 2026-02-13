<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Payroll runs
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft');
            $table->datetime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('export_format')->default('csv');
            $table->string('export_path')->nullable();
            $table->decimal('total_hours', 10, 2)->default(0);
            $table->integer('total_staff')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'period_start', 'status']);
        });

        // Payroll run line items
        Schema::create('hr_payroll_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('timesheet_ids')->nullable();
            $table->decimal('regular_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->integer('sleepover_count')->default(0);
            $table->decimal('on_call_hours', 8, 2)->default(0);
            $table->decimal('mileage_km', 8, 2)->default(0);
            $table->decimal('public_holiday_hours', 8, 2)->default(0);
            $table->decimal('gross_pay', 10, 2)->nullable();
            $table->json('allowances')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_run_items');
        Schema::dropIfExists('hr_payroll_runs');
    }
};
