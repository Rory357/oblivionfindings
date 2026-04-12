<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $signalTypes = [
            [
                'code' => 'medication_refused_dose',
                'name' => 'Medication Refused Dose',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'medium',
                'description' => 'A high-risk or controlled medication was refused by the client',
            ],
            [
                'code' => 'medication_refusal_escalation',
                'name' => 'Medication Refusal Escalation',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'Repeated refusals or withheld doses now require manager or clinical follow-up',
            ],
            [
                'code' => 'medication_unsafe_correction',
                'name' => 'Medication Unsafe Correction',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'medium',
                'description' => 'A medication administration was corrected outside the safe review window',
            ],
            [
                'code' => 'medication_controlled_loss',
                'name' => 'Controlled Drug Loss Report',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'critical',
                'description' => 'A controlled drug loss, theft, or unexplained disappearance was reported',
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
                'name' => 'Medication: Refused Dose',
                'signal_type_code' => 'medication_refused_dose',
                'priority' => 30,
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => false,
                'notify_roles' => [],
            ],
            [
                'name' => 'Medication: Refusal Escalation',
                'signal_type_code' => 'medication_refusal_escalation',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 240,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Medication: Unsafe Correction',
                'signal_type_code' => 'medication_unsafe_correction',
                'priority' => 20,
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 240,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Controlled Drug Loss',
                'signal_type_code' => 'medication_controlled_loss',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 240,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
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
        SignalRule::whereIn('signal_type_code', [
            'medication_refused_dose',
            'medication_refusal_escalation',
            'medication_unsafe_correction',
            'medication_controlled_loss',
        ])->delete();

        SignalType::whereIn('code', [
            'medication_refused_dose',
            'medication_refusal_escalation',
            'medication_unsafe_correction',
            'medication_controlled_loss',
        ])->delete();
    }
};
