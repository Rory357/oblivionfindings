<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backend handoff §7 — one HrTimeEntry per attendance session.
 *
 * This adds the database identity that serializes the attendance-backed ledger.
 * Existing duplicates are governed evidence and must be reconciled explicitly;
 * this migration refuses to guess or delete either row. NULL remains allowed
 * many times (manual entries have no session), per SQL's multiple-NULLs-in-a-
 * unique-index behaviour.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'hr_time_entries_attendance_session_id_unique';

    private const SUPPORTING_INDEX = 'hr_time_entries_attendance_session_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('hr_time_entries', self::UNIQUE_INDEX)) {
            $this->ensureForeignKeySupportingIndex();

            return;
        }

        // Never guess which duplicate is canonical: either row can carry
        // approval, payroll, amendment, audit, or Timesheet references. Halt
        // before DDL and leave every byte intact for explicit reconciliation.
        $duplicates = DB::table('hr_time_entries')
            ->whereNotNull('attendance_session_id')
            ->select('attendance_session_id')
            ->groupBy('attendance_session_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('attendance_session_id')
            ->pluck('attendance_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add the attendance time-entry unique index until duplicate session evidence is reconciled. '.
                'Conflicting attendance_session_id values: '.implode(', ', $duplicates),
            );
        }

        $this->ensureForeignKeySupportingIndex();

        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->unique('attendance_session_id', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('hr_time_entries', self::UNIQUE_INDEX)) {
            return;
        }

        // InnoDB may discard its implicit FK index once the unique index can
        // support the constraint. Recreate an explicit support index before
        // removing uniqueness so rollback also works on already-deployed DBs.
        $this->ensureForeignKeySupportingIndex();

        Schema::table('hr_time_entries', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    private function ensureForeignKeySupportingIndex(): void
    {
        if (Schema::hasIndex('hr_time_entries', self::SUPPORTING_INDEX)) {
            return;
        }

        Schema::table('hr_time_entries', function (Blueprint $table): void {
            $table->index('attendance_session_id', self::SUPPORTING_INDEX);
        });
    }
};
