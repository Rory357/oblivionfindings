<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backend handoff §7 — one HrTimeEntry per attendance session.
 *
 * The clock paths self-heal around a duplicate-open-entry race; this adds the
 * unique constraint that prevents it at the database level so the new idempotent
 * TimeTrackingService::syncEntryFromSession() can updateOrCreate safely. NULL is
 * still allowed many times (manual entries have no session), per SQL's
 * multiple-NULLs-in-a-unique-index behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive dedupe: physically remove any stray rows that already share a
        // non-null attendance_session_id, keeping the highest id, so the unique
        // index can be created. (Includes soft-deleted rows, which still occupy
        // the index slot.)
        DB::statement(
            'DELETE t1 FROM hr_time_entries t1 '.
            'INNER JOIN hr_time_entries t2 '.
            'ON t1.attendance_session_id = t2.attendance_session_id '.
            'AND t1.attendance_session_id IS NOT NULL '.
            'AND t1.id < t2.id'
        );

        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->unique('attendance_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->dropUnique(['attendance_session_id']);
        });
    }
};
