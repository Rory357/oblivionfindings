<?php

namespace Database\Seeders;

use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Seeder;

/**
 * Mirrors the data-creating migrations
 *   2026_04_18_000001_seed_security_devices_signal_types_and_source
 *   2026_04_18_000002_seed_security_devices_signal_rules
 *
 * The test bootstrap loads `database/schema/mysql-schema.sql`, which captures
 * DDL but not data inserted by migrations. Tests that depend on the
 * security_devices signal source / types / rules call `$this->seed(...)`
 * with this seeder instead of relying on the migrations.
 */
class SecurityDevicesSignalSeeder extends Seeder
{
    public function run(): void
    {
        $source = SignalSource::firstOrCreate(
            ['slug' => 'security_devices'],
            [
                'name' => 'Security & Devices',
                'vendor' => 'oblivion',
                'status' => 'active',
                'config' => [],
                'capabilities' => ['device_events'],
            ],
        );

        $signalTypes = [
            ['code' => 'device_alarm_trigger', 'name' => 'Device Alarm Triggered', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'critical'],
            ['code' => 'device_tamper', 'name' => 'Device Tampered', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'high'],
            ['code' => 'device_motion_detected', 'name' => 'Motion Detected', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'medium'],
            ['code' => 'device_door_opened', 'name' => 'Door Opened', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'low'],
            ['code' => 'device_door_closed', 'name' => 'Door Closed', 'category' => SignalType::CATEGORY_SECURITY, 'default_severity' => 'info'],
            ['code' => 'device_battery_low', 'name' => 'Device Battery Low', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'medium'],
            ['code' => 'device_offline', 'name' => 'Device Offline', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'medium'],
            ['code' => 'device_online', 'name' => 'Device Online', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'info'],
            ['code' => 'device_heartbeat', 'name' => 'Device Heartbeat', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'info'],
            ['code' => 'device_firmware_updated', 'name' => 'Device Firmware Updated', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'info'],
            ['code' => 'device_maintenance_due', 'name' => 'Device Maintenance Due', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'low'],
            ['code' => 'device_config_changed', 'name' => 'Device Config Changed', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'info'],
            ['code' => 'device_signal_generic', 'name' => 'Device Signal (Generic)', 'category' => SignalType::CATEGORY_HOME_FACILITY, 'default_severity' => 'info'],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(['code' => $type['code']], $type);
        }

        $rules = [
            ['name' => 'Security & Devices: Alarm Triggered', 'signal_type_code' => 'device_alarm_trigger', 'priority' => 10, 'output_severity' => 'critical', 'output_escalation_level' => 1, 'output_tier' => 3, 'deduplicate' => true, 'dedup_window_minutes' => 5],
            ['name' => 'Security & Devices: Tamper Detected', 'signal_type_code' => 'device_tamper', 'priority' => 15, 'output_severity' => 'high', 'output_escalation_level' => 1, 'output_tier' => 3, 'deduplicate' => true, 'dedup_window_minutes' => 10],
            ['name' => 'Security & Devices: Motion Detected', 'signal_type_code' => 'device_motion_detected', 'priority' => 30, 'output_severity' => 'medium', 'output_tier' => 2, 'deduplicate' => true, 'dedup_window_minutes' => 5],
            ['name' => 'Security & Devices: Door Opened', 'signal_type_code' => 'device_door_opened', 'priority' => 40, 'output_severity' => 'low', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 5],
            ['name' => 'Security & Devices: Battery Low', 'signal_type_code' => 'device_battery_low', 'priority' => 50, 'output_severity' => 'medium', 'output_tier' => 2, 'deduplicate' => true, 'dedup_window_minutes' => 720],
            ['name' => 'Security & Devices: Device Offline', 'signal_type_code' => 'device_offline', 'priority' => 55, 'output_severity' => 'medium', 'output_tier' => 2, 'deduplicate' => true, 'dedup_window_minutes' => 30, 'suppress_in_maintenance' => true],
            ['name' => 'Security & Devices: Device Online', 'signal_type_code' => 'device_online', 'priority' => 80, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 30, 'is_active' => false],
            ['name' => 'Security & Devices: Maintenance Due', 'signal_type_code' => 'device_maintenance_due', 'priority' => 60, 'output_severity' => 'low', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 1440],
            ['name' => 'Security & Devices: Firmware Updated', 'signal_type_code' => 'device_firmware_updated', 'priority' => 70, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 60],
            ['name' => 'Security & Devices: Config Changed', 'signal_type_code' => 'device_config_changed', 'priority' => 75, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 60],
            ['name' => 'Security & Devices: Door Closed', 'signal_type_code' => 'device_door_closed', 'priority' => 85, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 5],
            ['name' => 'Security & Devices: Heartbeat (Suppressed)', 'signal_type_code' => 'device_heartbeat', 'priority' => 99, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 60, 'is_active' => false],
            ['name' => 'Security & Devices: Generic Catch-all', 'signal_type_code' => 'device_signal_generic', 'priority' => 100, 'output_severity' => 'info', 'output_tier' => 1, 'deduplicate' => true, 'dedup_window_minutes' => 30],
        ];

        foreach ($rules as $rule) {
            $signalTypeId = SignalType::where('code', $rule['signal_type_code'])->value('id');
            if (! $signalTypeId) {
                continue;
            }

            $payload = [
                'signal_type_id' => $signalTypeId,
                'signal_source_id' => $source->id,
                'priority' => $rule['priority'],
                'output_severity' => $rule['output_severity'],
                'output_escalation_level' => $rule['output_escalation_level'] ?? 0,
                'output_tier' => $rule['output_tier'] ?? null,
                'deduplicate' => $rule['deduplicate'] ?? false,
                'dedup_window_minutes' => $rule['dedup_window_minutes'] ?? null,
                'suppress_in_maintenance' => $rule['suppress_in_maintenance'] ?? false,
                'is_active' => $rule['is_active'] ?? true,
            ];

            SignalRule::firstOrCreate(['name' => $rule['name']], $payload);
        }
    }
}
