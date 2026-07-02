<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive payroll columns: approved leave hours + leave pay per run item.
 * Nullable so historical (already locked/exported) runs are untouched — only
 * newly created runs populate them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_payroll_run_items', function (Blueprint $table) {
            $table->decimal('leave_hours', 8, 2)->nullable()->after('public_holiday_hours');
            $table->decimal('leave_pay', 10, 2)->nullable()->after('leave_hours');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_run_items', function (Blueprint $table) {
            $table->dropColumn(['leave_hours', 'leave_pay']);
        });
    }
};
