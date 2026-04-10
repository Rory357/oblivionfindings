<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR2: Seed default SignalRules for integration event signal types.
 *
 * These rules provide baseline routing, deduplication, and severity handling
 * for integration-originated events flowing through the signal pipeline.
 *
 * Without rules, signals fall through to createAlertFromSignal() with defaults.
 * These rules ensure:
 * - Deduplication within 30-minute windows (prevents alert floods)
 * - Correct severity output per event type
 * - Maintenance window suppression
 * - Named rules for auditability
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            [
                'name' => 'Integration: SOS Triggered',
                'signal_type_code' => 'integration_sos_triggered',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 3, // Emergency queue
                'deduplicate' => true,
                'dedup_window_minutes' => 5, // Short window — each SOS matters
                'suppress_in_maintenance' => false, // Never suppress safety events
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Integration: Panic Alarm',
                'signal_type_code' => 'integration_panic_alarm',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 3,
                'deduplicate' => true,
                'dedup_window_minutes' => 5,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Integration: Duress Alarm',
                'signal_type_code' => 'integration_duress_alarm',
                'priority' => 10,
                'output_severity' => 'critical',
                'output_escalation_level' => 1,
                'output_tier' => 3,
                'deduplicate' => true,
                'dedup_window_minutes' => 5,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core', 'coordinators'],
            ],
            [
                'name' => 'Integration: Door Forced',
                'signal_type_code' => 'integration_door_forced',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2, // Tier 2 (escalated)
                'deduplicate' => true,
                'dedup_window_minutes' => 15,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Integration: Tamper Detected',
                'signal_type_code' => 'integration_tamper_detected',
                'priority' => 20,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 15,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Integration: Power Failure',
                'signal_type_code' => 'integration_power_failure',
                'priority' => 25,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Integration: Device Offline',
                'signal_type_code' => 'integration_device_offline',
                'priority' => 30,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 1, // Tier 1 (default)
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Integration: Communication Failure',
                'signal_type_code' => 'integration_communication_failure',
                'priority' => 30,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Integration: Unknown Event (catch-all)',
                'signal_type_code' => 'integration_unknown',
                'priority' => 100, // Lowest priority — only matches if no specific rule exists
                'output_severity' => null, // Use signal severity_hint
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
        ];

        foreach ($rules as $ruleData) {
            // Resolve signal_type_id from code
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
        SignalRule::where('name', 'like', 'Integration:%')->delete();
    }
};
