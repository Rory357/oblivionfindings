<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_room_signal_types')
            || ! Schema::hasTable('control_room_signal_sources')
            || ! Schema::hasTable('control_room_signal_rules')) {
            return;
        }

        $now = now();

        DB::table('control_room_signal_types')->upsert([
            [
                'code' => 'orphan_completed_shift_no_timesheet',
                'name' => 'Completed Shift Missing Timesheet',
                'category' => 'compliance',
                'default_severity' => 'high',
                'default_escalation_minutes' => 60,
                'debounce_seconds' => 86400,
                'description' => 'A completed shift has no corresponding timesheet — staff may not be paid.',
                'required_context' => json_encode(['shift_id']),
                'correlation_keys' => json_encode(['shift_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'code' => 'orphan_attendance_no_timesheet',
                'name' => 'Attendance Session Missing Timesheet',
                'category' => 'compliance',
                'default_severity' => 'medium',
                'default_escalation_minutes' => 120,
                'debounce_seconds' => 86400,
                'description' => 'A closed attendance session has no linked timesheet — work performed but not captured for payroll.',
                'required_context' => json_encode(['attendance_session_id']),
                'correlation_keys' => json_encode(['attendance_session_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'code' => 'orphan_timesheet_no_shift',
                'name' => 'Timesheet Without Valid Shift',
                'category' => 'compliance',
                'default_severity' => 'medium',
                'default_escalation_minutes' => 120,
                'debounce_seconds' => 86400,
                'description' => 'A timesheet references a deleted shift or has no shift/attendance linkage.',
                'required_context' => json_encode(['timesheet_id']),
                'correlation_keys' => json_encode(['timesheet_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['code'], [
            'name',
            'category',
            'default_severity',
            'default_escalation_minutes',
            'debounce_seconds',
            'description',
            'required_context',
            'correlation_keys',
            'is_active',
            'updated_at',
        ]);

        $sourceId = DB::table('control_room_signal_sources')
            ->where('slug', 'shift_operations')
            ->value('id');

        if (! $sourceId) {
            return;
        }

        $typeIds = DB::table('control_room_signal_types')
            ->whereIn('code', [
                'orphan_completed_shift_no_timesheet',
                'orphan_attendance_no_timesheet',
                'orphan_timesheet_no_shift',
            ])
            ->pluck('id', 'code');

        $rules = [
            [
                'name' => 'Orphan: Completed Shift No Timesheet',
                'code' => 'orphan_completed_shift_no_timesheet',
                'tier' => 2,
            ],
            [
                'name' => 'Orphan: Attendance No Timesheet',
                'code' => 'orphan_attendance_no_timesheet',
                'tier' => 1,
            ],
            [
                'name' => 'Orphan: Timesheet No Shift',
                'code' => 'orphan_timesheet_no_shift',
                'tier' => 1,
            ],
        ];

        foreach ($rules as $rule) {
            $typeId = $typeIds[$rule['code']] ?? null;
            if (! $typeId) {
                continue;
            }

            DB::table('control_room_signal_rules')->upsert([
                [
                    'name' => $rule['name'],
                    'signal_type_id' => $typeId,
                    'signal_type_code' => $rule['code'],
                    'signal_source_id' => $sourceId,
                    'priority' => 20,
                    'is_active' => true,
                    'conditions' => json_encode([]),
                    'output_severity' => null,
                    'output_escalation_level' => 0,
                    'output_tier' => $rule['tier'],
                    'playbook_id' => null,
                    'notify_roles' => json_encode([]),
                    'notify_users' => json_encode([]),
                    'deduplicate' => true,
                    'dedup_window_minutes' => 1440,
                    'suppress_in_maintenance' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            ], ['name'], [
                'signal_type_id',
                'signal_type_code',
                'signal_source_id',
                'priority',
                'is_active',
                'conditions',
                'output_severity',
                'output_escalation_level',
                'output_tier',
                'deduplicate',
                'dedup_window_minutes',
                'suppress_in_maintenance',
                'updated_at',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_room_signal_rules')
            || ! Schema::hasTable('control_room_signal_types')) {
            return;
        }

        DB::table('control_room_signal_rules')
            ->whereIn('name', [
                'Orphan: Completed Shift No Timesheet',
                'Orphan: Attendance No Timesheet',
                'Orphan: Timesheet No Shift',
            ])
            ->delete();

        DB::table('control_room_signal_types')
            ->whereIn('code', [
                'orphan_completed_shift_no_timesheet',
                'orphan_attendance_no_timesheet',
                'orphan_timesheet_no_shift',
            ])
            ->delete();
    }
};
