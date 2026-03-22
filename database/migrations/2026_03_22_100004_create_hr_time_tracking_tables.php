<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->date('entry_date');
            $table->datetime('clock_in');
            $table->datetime('clock_out')->nullable();
            $table->unsignedInteger('break_minutes')->default(0);
            $table->decimal('total_hours', 8, 2)->nullable();
            $table->string('entry_type')->default('clock'); // clock, manual, timesheet
            $table->string('status')->default('active'); // active, submitted, approved, rejected
            $table->text('notes')->nullable();
            $table->string('project_code')->nullable();
            $table->string('cost_centre')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'entry_date']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_timesheets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->datetime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_timesheets');
        Schema::dropIfExists('hr_time_entries');
    }
};
