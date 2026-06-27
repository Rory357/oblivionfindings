<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bind HR calendar events to HrDepartment (the Time-Off filter already used
     * the FK). The free-text `department` column is kept for back-compat and
     * backfilled into the new FK by name match within the same tenant.
     */
    public function up(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('department')
                ->constrained('hr_departments')
                ->nullOnDelete();
        });

        // Backfill: match the free-text department name to an HrDepartment in the
        // same tenant (or a global department). Best-effort — unmatched strings
        // simply stay department_id = null and keep their text label.
        $events = DB::table('hr_calendar_events')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->get(['id', 'tenant_id', 'department']);

        foreach ($events as $event) {
            $departmentId = DB::table('hr_departments')
                ->where('name', $event->department)
                ->where(function ($q) use ($event) {
                    $q->where('tenant_id', $event->tenant_id)->orWhereNull('tenant_id');
                })
                ->value('id');

            if ($departmentId) {
                DB::table('hr_calendar_events')
                    ->where('id', $event->id)
                    ->update(['department_id' => $departmentId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
