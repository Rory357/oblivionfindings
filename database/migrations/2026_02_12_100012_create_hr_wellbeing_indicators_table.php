<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_wellbeing_indicators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->integer('consecutive_days_worked')->default(0);
            $table->integer('sick_leave_days_30d')->default(0);
            $table->integer('sick_leave_days_90d')->default(0);
            $table->integer('shifts_worked_7d')->default(0);
            $table->decimal('average_shift_length_hours', 4, 2)->default(0);
            $table->string('flag_level')->default('none');
            $table->datetime('calculated_at');
            $table->timestamps();

            $table->index(['tenant_id', 'flag_level']);
            $table->index(['user_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_wellbeing_indicators');
    }
};
