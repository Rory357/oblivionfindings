<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_room_signal_sources')
            || ! Schema::hasTable('control_room_signal_types')
            || ! Schema::hasTable('control_room_signal_rules')) {
            return;
        }

        $now = now();

        DB::table('control_room_signal_sources')->upsert([
            [
                'name' => 'Shift Operations',
                'slug' => 'shift_operations',
                'vendor' => 'internal',
                'status' => 'active',
                'config' => json_encode([]),
                'capabilities' => json_encode(['alerts', 'outbox', 'shift_context']),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ], ['slug'], ['name', 'vendor', 'status', 'config', 'capabilities', 'updated_at']);

        DB::table('control_room_signal_types')->upsert([
            [
                'code' => 'shift_no_show',
                'name' => 'Shift No Show',
                'category' => 'people_safety',
                'default_severity' => 'high',
                'default_escalation_minutes' => 15,
                'debounce_seconds' => 300,
                'description' => 'Assigned shift has not started and no attendance evidence exists.',
                'required_context' => json_encode(['shift_id', 'planned_start']),
                'correlation_keys' => json_encode(['shift_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'code' => 'shift_late_start',
                'name' => 'Shift Late Start',
                'category' => 'people_safety',
                'default_severity' => 'medium',
                'default_escalation_minutes' => 15,
                'debounce_seconds' => 300,
                'description' => 'Shift started materially after the planned start time.',
                'required_context' => json_encode(['shift_id', 'actual_start']),
                'correlation_keys' => json_encode(['shift_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'code' => 'shift_not_completed',
                'name' => 'Shift Not Completed',
                'category' => 'people_safety',
                'default_severity' => 'medium',
                'default_escalation_minutes' => 30,
                'debounce_seconds' => 300,
                'description' => 'In-progress shift has run past its planned end without completion evidence.',
                'required_context' => json_encode(['shift_id', 'planned_end']),
                'correlation_keys' => json_encode(['shift_id']),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'code' => 'shift_uncovered',
                'name' => 'Shift Uncovered',
                'category' => 'people_safety',
                'default_severity' => 'high',
                'default_escalation_minutes' => 15,
                'debounce_seconds' => 300,
                'description' => 'Upcoming or current shift coverage is unfilled or below requirement.',
                'required_context' => json_encode(['site_id']),
                'correlation_keys' => json_encode(['shift_id', 'coverage_window_key']),
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

        $typeIds = DB::table('control_room_signal_types')
            ->whereIn('code', ['shift_no_show', 'shift_late_start', 'shift_not_completed', 'shift_uncovered'])
            ->pluck('id', 'code');

        $rules = collect([
            'Shift No Show Rule' => 'shift_no_show',
            'Shift Late Start Rule' => 'shift_late_start',
            'Shift Not Completed Rule' => 'shift_not_completed',
            'Shift Uncovered Rule' => 'shift_uncovered',
        ])->map(function (string $code, string $name) use ($sourceId, $typeIds, $now) {
            return [
                'name' => $name,
                'signal_type_id' => $typeIds[$code] ?? null,
                'signal_type_code' => $code,
                'signal_source_id' => $sourceId,
                'priority' => 10,
                'is_active' => true,
                'conditions' => json_encode([]),
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => in_array($code, ['shift_no_show', 'shift_uncovered'], true) ? 2 : 1,
                'playbook_id' => null,
                'notify_roles' => json_encode([]),
                'notify_users' => json_encode([]),
                'deduplicate' => true,
                'dedup_window_minutes' => 180,
                'suppress_in_maintenance' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        })->values()->all();

        DB::table('control_room_signal_rules')->upsert(
            $rules,
            ['name'],
            [
                'signal_type_id',
                'signal_type_code',
                'signal_source_id',
                'priority',
                'is_active',
                'conditions',
                'output_severity',
                'output_escalation_level',
                'output_tier',
                'playbook_id',
                'notify_roles',
                'notify_users',
                'deduplicate',
                'dedup_window_minutes',
                'suppress_in_maintenance',
                'updated_at',
            ]
        );

        if (Schema::hasTable('control_room_triage_queues')) {
            $tierOne = DB::table('control_room_triage_queues')->where('code', 'tier_1')->first();
            if ($tierOne) {
                $sources = json_decode((string) ($tierOne->handle_sources ?? '[]'), true);
                $sources = is_array($sources) ? $sources : [];
                if (! in_array('shift_operations', $sources, true)) {
                    $sources[] = 'shift_operations';
                    DB::table('control_room_triage_queues')
                        ->where('id', $tierOne->id)
                        ->update([
                            'handle_sources' => json_encode(array_values($sources)),
                            'updated_at' => $now,
                        ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('control_room_signal_rules')
            || ! Schema::hasTable('control_room_signal_types')
            || ! Schema::hasTable('control_room_signal_sources')) {
            return;
        }

        DB::table('control_room_signal_rules')
            ->whereIn('name', [
                'Shift No Show Rule',
                'Shift Late Start Rule',
                'Shift Not Completed Rule',
                'Shift Uncovered Rule',
            ])
            ->delete();

        DB::table('control_room_signal_types')
            ->whereIn('code', [
                'shift_no_show',
                'shift_late_start',
                'shift_not_completed',
                'shift_uncovered',
            ])
            ->delete();

        DB::table('control_room_signal_sources')
            ->where('slug', 'shift_operations')
            ->delete();
    }
};
