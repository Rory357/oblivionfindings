<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_sessions', function (Blueprint $table): void {
            $table->dropIndex('hr_attendance_sessions_tenant_id_index');
            $table->dropIndex('hr_attendance_tenant_clock_in_idx');

            $table->index(
                ['status', 'clock_in_at'],
                'hr_attendance_status_clock_in_idx',
            );
        });

        Schema::table('hr_time_entries', function (Blueprint $table): void {
            $table->dropIndex('hr_time_entries_tenant_id_index');
            $table->dropIndex('hr_time_entries_tenant_id_user_id_entry_date_index');
            $table->dropIndex('hr_time_entries_tenant_id_status_index');

            $table->index(
                ['user_id', 'entry_date'],
                'hr_time_entries_user_date_idx',
            );
            $table->index(
                ['status', 'entry_date'],
                'hr_time_entries_status_date_idx',
            );
        });

        Schema::table('hr_time_entry_amendments', function (Blueprint $table): void {
            $table->dropIndex('hr_time_entry_amendments_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('hr_time_entry_amendments', function (Blueprint $table): void {
            $table->index('tenant_id', 'hr_time_entry_amendments_tenant_id_index');
        });

        Schema::table('hr_time_entries', function (Blueprint $table): void {
            $table->dropIndex('hr_time_entries_user_date_idx');
            $table->dropIndex('hr_time_entries_status_date_idx');

            $table->index('tenant_id', 'hr_time_entries_tenant_id_index');
            $table->index(
                ['tenant_id', 'user_id', 'entry_date'],
                'hr_time_entries_tenant_id_user_id_entry_date_index',
            );
            $table->index(
                ['tenant_id', 'status'],
                'hr_time_entries_tenant_id_status_index',
            );
        });

        Schema::table('hr_attendance_sessions', function (Blueprint $table): void {
            $table->dropIndex('hr_attendance_status_clock_in_idx');

            $table->index('tenant_id', 'hr_attendance_sessions_tenant_id_index');
            $table->index(
                ['tenant_id', 'clock_in_at'],
                'hr_attendance_tenant_clock_in_idx',
            );
        });
    }
};
