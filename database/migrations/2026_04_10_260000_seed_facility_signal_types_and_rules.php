<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR5: Seed signal types and rules for facility operational alerts.
 *
 * Covers: inspection overdue, device offline (non-fleet CR devices).
 *
 * Asset faults (SOS, tamper, geofence) already flow through FleetSignalService
 * and do not need separate signal types here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $signalTypes = [
            [
                'code' => 'inspection_overdue',
                'name' => 'Inspection Overdue',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'medium',
                'description' => 'Site inspection is past its due date and has not been completed',
            ],
            [
                'code' => 'inspection_failed',
                'name' => 'Inspection Failed',
                'category' => SignalType::CATEGORY_COMPLIANCE,
                'default_severity' => 'high',
                'description' => 'Site inspection completed with a failing result',
            ],
            [
                'code' => 'cr_device_offline',
                'name' => 'Device Offline',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'medium',
                'description' => 'Control Room registered device has gone offline (non-fleet)',
            ],
            [
                'code' => 'cr_device_low_battery',
                'name' => 'Device Low Battery',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'low',
                'description' => 'Control Room device battery is critically low',
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
                'name' => 'Facility: Inspection Overdue',
                'signal_type_code' => 'inspection_overdue',
                'priority' => 30,
                'output_severity' => null, // Uses signal severity_hint (medium or high based on days)
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440, // One alert per day per inspection
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Facility: Inspection Failed',
                'signal_type_code' => 'inspection_failed',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Facility: Device Offline',
                'signal_type_code' => 'cr_device_offline',
                'priority' => 30,
                'output_severity' => null, // Uses signal severity_hint (medium or high based on device type)
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Facility: Device Low Battery',
                'signal_type_code' => 'cr_device_low_battery',
                'priority' => 40,
                'output_severity' => 'low',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => true,
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
        SignalRule::where('name', 'like', 'Facility:%')->delete();
        SignalType::whereIn('code', [
            'inspection_overdue',
            'inspection_failed',
            'cr_device_offline',
            'cr_device_low_battery',
        ])->delete();
    }
};
