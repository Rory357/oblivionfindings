<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR3: Seed signal types and rules for medication operational alerts.
 *
 * Only operationally significant medication events get signal types.
 * Routine informational items (PRN near limit, stock low, expiring soon)
 * remain as dashboard convenience items and do NOT enter Control Room.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Signal Types ---
        $signalTypes = [
            [
                'code' => 'medication_overdue',
                'name' => 'Medication Overdue',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'Scheduled medication has not been administered within the expected window',
            ],
            [
                'code' => 'medication_missed_dose',
                'name' => 'Medication Missed Dose',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'medium',
                'description' => 'Confirmed missed dose recorded for a client medication',
            ],
            [
                'code' => 'medication_late_dose',
                'name' => 'Medication Late Dose',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'medium',
                'description' => 'Medication administered significantly late (2+ hours)',
            ],
            [
                'code' => 'medication_prn_over_limit',
                'name' => 'PRN Over Limit',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'critical',
                'description' => 'PRN medication administered at or above daily maximum',
            ],
            [
                'code' => 'medication_controlled_discrepancy',
                'name' => 'Controlled Drug Discrepancy',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'critical',
                'description' => 'Stock discrepancy detected for a controlled drug',
            ],
            [
                'code' => 'medication_expired',
                'name' => 'Medication Expired',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'Medication stock has expired and must not be administered',
            ],
            [
                'code' => 'medication_stock_out',
                'name' => 'Medication Out of Stock',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'Medication stock is zero — client cannot receive scheduled doses',
            ],
            [
                'code' => 'medication_error',
                'name' => 'Medication Error',
                'category' => SignalType::CATEGORY_MEDICAL_WELLBEING,
                'default_severity' => 'high',
                'description' => 'Medication error reported (wrong dose, wrong medication, etc.)',
            ],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }

        // --- Signal Rules ---
        $rules = [
            [
                'name' => 'Medication: Controlled Drug Discrepancy',
                'signal_type_code' => 'medication_controlled_discrepancy',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Medication: PRN Over Limit',
                'signal_type_code' => 'medication_prn_over_limit',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Overdue Doses',
                'signal_type_code' => 'medication_overdue',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Expired Stock',
                'signal_type_code' => 'medication_expired',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440, // 24 hours — one alert per day per medication
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Out of Stock',
                'signal_type_code' => 'medication_stock_out',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Error Reported',
                'signal_type_code' => 'medication_error',
                'priority' => 15,
                'output_severity' => null, // Uses signal severity_hint (varies by error type)
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Medication: Missed Dose',
                'signal_type_code' => 'medication_missed_dose',
                'priority' => 30,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => false,
                'notify_roles' => [],
            ],
            [
                'name' => 'Medication: Late Dose',
                'signal_type_code' => 'medication_late_dose',
                'priority' => 30,
                'output_severity' => null, // Uses signal severity_hint (varies by lateness)
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
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
        SignalRule::where('name', 'like', 'Medication:%')->delete();

        SignalType::whereIn('code', [
            'medication_overdue',
            'medication_missed_dose',
            'medication_late_dose',
            'medication_prn_over_limit',
            'medication_controlled_discrepancy',
            'medication_expired',
            'medication_stock_out',
            'medication_error',
        ])->delete();
    }
};
