<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-142 / EA-143: what the daily backup job last did for each house, so the
 * page stops saying "Daily schedule enabled" while nothing is produced. No
 * person, medicine, mailbox or secret is stored here — only a state and a
 * neutral code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_backup_schedules') || Schema::hasColumn('medication_backup_schedules', 'last_run_state')) {
            return;
        }
        Schema::table('medication_backup_schedules', function (Blueprint $table): void {
            $table->date('last_run_nz_date')->nullable()->after('approved_by');
            $table->string('last_run_state', 32)->nullable()->after('last_run_nz_date');
            $table->string('last_run_code', 64)->nullable()->after('last_run_state');
            $table->timestamp('last_run_at')->nullable()->after('last_run_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('medication_backup_schedules') || ! Schema::hasColumn('medication_backup_schedules', 'last_run_state')) {
            return;
        }
        Schema::table('medication_backup_schedules', function (Blueprint $table): void {
            $table->dropColumn(['last_run_nz_date', 'last_run_state', 'last_run_code', 'last_run_at']);
        });
    }
};
