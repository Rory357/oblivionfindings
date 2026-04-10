<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR13: Seed signal types and rules for H&S monitoring layer.
 *
 * Covers overdue investigations, corrective actions, risk assessment reviews,
 * and emergency drill failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $signalTypes = [
            [
                'code' => 'hs_investigation_overdue',
                'name' => 'H&S Investigation Overdue',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'medium',
                'description' => 'Health & Safety investigation has exceeded its target completion date',
            ],
            [
                'code' => 'hs_corrective_action_overdue',
                'name' => 'H&S Corrective Action Overdue',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'medium',
                'description' => 'Health & Safety corrective action has exceeded its due date',
            ],
            [
                'code' => 'hs_risk_review_overdue',
                'name' => 'Risk Assessment Review Overdue',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'medium',
                'description' => 'Risk assessment is past its scheduled review date',
            ],
            [
                'code' => 'hs_drill_failure',
                'name' => 'Emergency Drill Failed',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'medium',
                'description' => 'Emergency drill completed with a non-passing outcome',
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
                'name' => 'H&S: Investigation Overdue',
                'signal_type_code' => 'hs_investigation_overdue',
                'priority' => 25,
                'output_severity' => null, // Uses signal severity_hint (MEDIUM or HIGH based on days)
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440, // One alert per day per investigation
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'H&S: Corrective Action Overdue',
                'signal_type_code' => 'hs_corrective_action_overdue',
                'priority' => 25,
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'H&S: Risk Assessment Review Overdue',
                'signal_type_code' => 'hs_risk_review_overdue',
                'priority' => 35,
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => false,
                'notify_roles' => [],
            ],
            [
                'name' => 'H&S: Emergency Drill Failed',
                'signal_type_code' => 'hs_drill_failure',
                'priority' => 25,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
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
        SignalRule::where('name', 'like', 'H&S:%')->delete();
        SignalType::whereIn('code', [
            'hs_investigation_overdue',
            'hs_corrective_action_overdue',
            'hs_risk_review_overdue',
            'hs_drill_failure',
        ])->delete();
    }
};
