<?php

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR H: Seed baseline routing rules for device_* signal types.
 *
 * These rules govern how DeviceEvent-sourced signals surface as
 * Control Room alerts: severity overrides, tier (queue), dedup windows,
 * and maintenance-window suppression.
 *
 * Operators can add or override rules via the Control Room rule admin;
 * this migration only seeds the defaults so the pipeline behaves
 * sensibly the moment the observer starts bridging DeviceEvent → Signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            // ── Safety-critical (tier 3) ──────────────────────────
            [
                'name' => 'Security & Devices: Alarm Triggered',
                'signal_type_code' => 'device_alarm_trigger',
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
                'name' => 'Security & Devices: Tamper Detected',
                'signal_type_code' => 'device_tamper',
                'priority' => 15,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 15,
                'suppress_in_maintenance' => false,
                'notify_roles' => ['managers_core'],
            ],

            // ── Connectivity / health (tier 2) ────────────────────
            [
                'name' => 'Security & Devices: Device Offline',
                'signal_type_code' => 'device_offline',
                'priority' => 25,
                'output_severity' => 'high',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => ['managers_core'],
            ],
            [
                'name' => 'Security & Devices: Battery Low',
                'signal_type_code' => 'device_battery_low',
                'priority' => 40,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 360,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Maintenance Due',
                'signal_type_code' => 'device_maintenance_due',
                'priority' => 45,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 2,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],

            // ── Operational (tier 1) ──────────────────────────────
            [
                'name' => 'Security & Devices: Motion Detected',
                'signal_type_code' => 'device_motion_detected',
                'priority' => 50,
                'output_severity' => 'medium',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 10,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Door Opened',
                'signal_type_code' => 'device_door_opened',
                'priority' => 60,
                'output_severity' => 'low',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 5,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Device Online',
                'signal_type_code' => 'device_online',
                'priority' => 70,
                'output_severity' => 'low',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],

            // ── Audit-log-only (info severity) ────────────────────
            [
                'name' => 'Security & Devices: Firmware Updated',
                'signal_type_code' => 'device_firmware_updated',
                'priority' => 80,
                'output_severity' => 'info',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Door Closed',
                'signal_type_code' => 'device_door_closed',
                'priority' => 85,
                'output_severity' => 'info',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Config Changed',
                'signal_type_code' => 'device_config_changed',
                'priority' => 85,
                'output_severity' => 'low',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 60,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
            [
                'name' => 'Security & Devices: Heartbeat',
                'signal_type_code' => 'device_heartbeat',
                'priority' => 95,
                'output_severity' => 'info',
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 1440,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],

            // ── Catch-all (lowest priority) ───────────────────────
            [
                'name' => 'Security & Devices: Generic device event (catch-all)',
                'signal_type_code' => 'device_signal_generic',
                'priority' => 100,
                'output_severity' => null,
                'output_escalation_level' => 0,
                'output_tier' => 1,
                'deduplicate' => true,
                'dedup_window_minutes' => 30,
                'suppress_in_maintenance' => true,
                'notify_roles' => [],
            ],
        ];

        foreach ($rules as $rule) {
            $signalType = SignalType::where('code', $rule['signal_type_code'])->first();

            SignalRule::firstOrCreate(
                [
                    'signal_type_code' => $rule['signal_type_code'],
                    'name' => $rule['name'],
                ],
                array_merge($rule, [
                    'signal_type_id' => $signalType?->id,
                    'is_active' => true,
                    'conditions' => [],
                ])
            );
        }
    }

    public function down(): void
    {
        SignalRule::where('name', 'like', 'Security & Devices:%')->delete();
    }
};
