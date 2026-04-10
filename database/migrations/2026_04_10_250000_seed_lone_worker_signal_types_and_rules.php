<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR4: Seed signal types and rules for lone worker safety alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $signalTypes = [
            [
                'code' => 'lone_worker_emergency',
                'name' => 'Lone Worker Emergency',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'critical',
                'description' => 'Lone worker has triggered an emergency / SOS / distress signal',
            ],
            [
                'code' => 'lone_worker_overdue_checkin',
                'name' => 'Lone Worker Overdue Check-in',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'high',
                'description' => 'Lone worker has not checked in within the expected interval',
            ],
            [
                'code' => 'lone_worker_session_overrun',
                'name' => 'Lone Worker Session Overrun',
                'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
                'default_severity' => 'medium',
                'description' => 'Lone worker session has exceeded the expected end time',
            ],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }

        $rules = [
            [
                'name' => 'Lone Worker: Emergency',
                'signal_type_code' => 'lone_worker_emergency',
                'priority' => 5, // Highest priority — life safety
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 3, // Emergency queue
                'deduplicate' => true,
                'dedup_window_minutes' => 5,
                'suppress_in_maintenance' => false, // Never suppress life safety
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Lone Worker: Overdue Check-in',
                'signal_type_code' => 'lone_worker_overdue_checkin',
                'priority' => 15,
                'output_severity' => null, // Uses signal severity_hint (escalates with time)
                'output_escalation_level' => 0,
                'output_tier' => 2, // Tier 2 — needs prompt attention
                'deduplicate' => true,
                'dedup_window_minutes' => 15,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Lone Worker: Session Overrun',
                'signal_type_code' => 'lone_worker_session_overrun',
                'priority' => 25,
                'output_severity' => null, // Uses signal severity_hint
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => false,
                'notify_roles' => [],
            ],
        ];

        foreach ($rules as $ruleData) {
            $signalType = SignalType::where('code', $ruleData['signal_type_code'])->first();

            SignalRule::firstOrCreate(
                [
                    'signal_type_code' => $ruleData['signal_type_code'],
                    'name' => $ruleData['name'],
                ],
                array_merge($ruleData, [
                    'signal_type_id' => $signalType?->id,
                    'is_active' => true,
                    'conditions' => [],
                ])
            );
        }
    }

    public function down(): void
    {
        SignalRule::where('name', 'like', 'Lone Worker:%')->delete();
        SignalType::whereIn('code', [
            'lone_worker_emergency',
            'lone_worker_overdue_checkin',
            'lone_worker_session_overrun',
        ])->delete();
    }
};
