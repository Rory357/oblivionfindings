<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('user_id')->constrained('shifts')->nullOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->after('shift_id')->constrained('hr_attendance_sessions')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('attendance_session_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->after('site_id')->constrained('clients')->nullOnDelete();
            $table->string('source_type', 30)->nullable()->after('cost_centre');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('pay_type', 30)->default('standard')->after('source_id');
            $table->boolean('is_sleepover')->default(false)->after('pay_type');
            $table->boolean('is_on_call')->default(false)->after('is_sleepover');
            $table->boolean('is_public_holiday')->default(false)->after('is_on_call');
            $table->decimal('mileage_km', 8, 2)->nullable()->after('is_public_holiday');
            $table->boolean('break_compliance_met')->nullable()->after('mileage_km');
            $table->foreignId('hr_timesheet_id')->nullable()->after('break_compliance_met')->constrained('hr_timesheets')->nullOnDelete();

            $table->index(['source_type', 'source_id']);
            $table->index('shift_id');
            $table->index('pay_type');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->string('shift_type', 30)->default('standard')->after('status');
            $table->boolean('is_sleepover')->default(false)->after('shift_type');
            $table->boolean('is_on_call')->default(false)->after('is_sleepover');
            $table->unsignedSmallInteger('expected_break_minutes')->nullable()->after('is_on_call');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['shift_type', 'is_sleepover', 'is_on_call', 'expected_break_minutes']);
        });

        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropIndex(['shift_id']);
            $table->dropIndex(['pay_type']);
            $table->dropConstrainedForeignId('hr_timesheet_id');
            $table->dropColumn(['break_compliance_met', 'mileage_km', 'is_public_holiday', 'is_on_call', 'is_sleepover', 'pay_type', 'source_id', 'source_type']);
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('attendance_session_id');
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
