<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source-of-truth reconciliation: make StaffTimeOff a faithful, tenant-scoped,
 * back-linked projection of HrLeaveRequest.
 *
 *  M1  staff_time_offs.tenant_id          (nullable, indexed) + backfill
 *  M2  staff_time_offs.hr_leave_request_id(FK nullable, nullOnDelete) + backfill
 *  M3  hr_leave_requests.time_off_id      → real FK (nullOnDelete); orphans nulled first
 *  M4  staff_time_offs.period             (part-day projection; default full_day)
 *
 * All additive/nullable/constraint-only. Backfill runs BEFORE the M3 FK add so an
 * existing dangling time_off_id can never block the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- M1 / M2 / M4: add columns to the projection table ---
        Schema::table('staff_time_offs', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_time_offs', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('staff_time_offs', 'hr_leave_request_id')) {
                $table->foreignId('hr_leave_request_id')->nullable()->after('user_id')
                    ->constrained('hr_leave_requests')->nullOnDelete();
            }
            if (! Schema::hasColumn('staff_time_offs', 'period')) {
                $table->string('period')->default('full_day')->after('type'); // full_day|half_day_am|half_day_pm
            }
        });

        // --- Backfill the back-link + tenant from the parent request (MySQL multi-table UPDATE) ---
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE staff_time_offs s
                JOIN hr_leave_requests r ON r.time_off_id = s.id
                SET s.hr_leave_request_id = r.id,
                    s.tenant_id = COALESCE(s.tenant_id, r.tenant_id)
            SQL);
        } else {
            // Portable fallback (sqlite/pgsql)
            DB::table('hr_leave_requests')->whereNotNull('time_off_id')->orderBy('id')
                ->chunkById(500, function ($requests) {
                    foreach ($requests as $r) {
                        DB::table('staff_time_offs')->where('id', $r->time_off_id)->update([
                            'hr_leave_request_id' => $r->id,
                            'tenant_id' => DB::raw('COALESCE(tenant_id, '.(int) $r->tenant_id.')'),
                        ]);
                    }
                });
        }

        // Any remaining unlinked projection rows belong to the single tenant.
        DB::table('staff_time_offs')->whereNull('tenant_id')->update(['tenant_id' => 1]);

        // --- M3: null orphan back-pointers, THEN add the real FK constraint ---
        DB::statement(
            'UPDATE hr_leave_requests SET time_off_id = NULL '.
            'WHERE time_off_id IS NOT NULL '.
            'AND time_off_id NOT IN (SELECT id FROM (SELECT id FROM staff_time_offs) AS s)'
        );

        if (! $this->timeOffForeignKeyExists()) {
            Schema::table('hr_leave_requests', function (Blueprint $table) {
                $table->foreign('time_off_id')->references('id')->on('staff_time_offs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->timeOffForeignKeyExists()) {
            Schema::table('hr_leave_requests', function (Blueprint $table) {
                $table->dropForeign(['time_off_id']);
            });
        }

        Schema::table('staff_time_offs', function (Blueprint $table) {
            if (Schema::hasColumn('staff_time_offs', 'hr_leave_request_id')) {
                $table->dropForeign(['hr_leave_request_id']);
                $table->dropColumn('hr_leave_request_id');
            }
            if (Schema::hasColumn('staff_time_offs', 'tenant_id')) {
                $table->dropColumn('tenant_id');
            }
            if (Schema::hasColumn('staff_time_offs', 'period')) {
                $table->dropColumn('period');
            }
        });
    }

    private function timeOffForeignKeyExists(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'hr_leave_requests')
            ->where('COLUMN_NAME', 'time_off_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
