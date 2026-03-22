<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_bonus_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_profile_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('bonus_type'); // performance, signing, retention, spot, holiday, other
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('NZD');
            $table->text('reason')->nullable();
            $table->date('payment_date');
            $table->string('status')->default('pending'); // pending, approved, paid, cancelled
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained('hr_payroll_runs')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['employee_profile_id', 'bonus_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_bonus_payments');
    }
};
