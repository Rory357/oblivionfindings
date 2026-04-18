<?php

use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use Illuminate\Database\Migrations\Migration;

/**
 * PR H: Seed signal types and signal source for Security & Devices DeviceEvents.
 *
 * These types match the `event_type` values emitted from
 * App\Domain\SecurityDevices\Models\DeviceEvent. They are bridged into
 * the Control Room signal pipeline by DeviceEventObserver (see
 * app/Observers/DeviceEventObserver.php).
 *
 * Signal types are deliberately kept distinct from the `integration_*`
 * family because:
 *   • integration_* types are external-provider normalised events
 *     (webhook payloads from Gallagher / Hikvision / etc).
 *   • device_* types are canonical Device registry events (our own
 *     Security & Devices ingestion of the UniFi / Queclink / Milesight
 *     adapter output and any manual insertion).
 *
 * Keeping them separate lets operators write different playbooks /
 * dedup windows / suppression rules for the two streams without
 * collisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        SignalSource::firstOrCreate(
            ['slug' => 'security_devices'],
            [
                'name' => 'Security & Devices',
                'vendor' => 'oblivion',
                'status' => 'active',
                'config' => [],
                'capabilities' => ['device_events'],
            ]
        );

        $signalTypes = [
            [
                'code' => 'device_alarm_trigger',
                'name' => 'Device Alarm Triggered',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'critical',
                'description' => 'Alarm-capable device fired its alarm (duress, panic, intrusion).',
            ],
            [
                'code' => 'device_tamper',
                'name' => 'Device Tampered',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'high',
                'description' => 'Device tamper switch or physical-attack indicator fired.',
            ],
            [
                'code' => 'device_motion_detected',
                'name' => 'Motion Detected',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'medium',
                'description' => 'Motion sensor or camera detected movement.',
            ],
            [
                'code' => 'device_door_opened',
                'name' => 'Door Opened',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'low',
                'description' => 'Access-controlled door opened.',
            ],
            [
                'code' => 'device_door_closed',
                'name' => 'Door Closed',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'info',
                'description' => 'Access-controlled door closed.',
            ],
            [
                'code' => 'device_battery_low',
                'name' => 'Device Battery Low',
                'category' => SignalType::CATEGORY_HOME_FACILITY,
                'default_severity' => 'medium',
                'description' => 'Device battery level fell below its profile threshold.',
            ],
            [
                'code' => 'device_offline',
                'name' => 'Device Offline',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'high',
                'description' => 'Device stopped reporting beyond its expected heartbeat window.',
            ],
            [
                'code' => 'device_online',
                'name' => 'Device Online',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'info',
                'description' => 'Device came back online after an offline period.',
            ],
            [
                'code' => 'device_heartbeat',
                'name' => 'Device Heartbeat',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'info',
                'description' => 'Routine heartbeat / keep-alive. Suppressed during maintenance by default.',
            ],
            [
                'code' => 'device_firmware_updated',
                'name' => 'Device Firmware Updated',
                'category' => SignalType::CATEGORY_HOME_FACILITY,
                'default_severity' => 'info',
                'description' => 'Device firmware version changed. Audit-log only.',
            ],
            [
                'code' => 'device_maintenance_due',
                'name' => 'Device Maintenance Due',
                'category' => SignalType::CATEGORY_HOME_FACILITY,
                'default_severity' => 'medium',
                'description' => 'Scheduled service date reached or passed.',
            ],
            [
                'code' => 'device_config_changed',
                'name' => 'Device Config Changed',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'low',
                'description' => 'Provider-reported configuration change on a device.',
            ],
            [
                'code' => 'device_signal_generic',
                'name' => 'Device Signal (generic)',
                'category' => SignalType::CATEGORY_SECURITY,
                'default_severity' => 'low',
                'description' => 'Catch-all for DeviceEvent event_types that lack a dedicated signal type.',
            ],
        ];

        foreach ($signalTypes as $type) {
            SignalType::firstOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }
    }

    public function down(): void
    {
        SignalType::whereIn('code', [
            'device_alarm_trigger',
            'device_tamper',
            'device_motion_detected',
            'device_door_opened',
            'device_door_closed',
            'device_battery_low',
            'device_offline',
            'device_online',
            'device_heartbeat',
            'device_firmware_updated',
            'device_maintenance_due',
            'device_config_changed',
            'device_signal_generic',
        ])->delete();

        SignalSource::where('slug', 'security_devices')->delete();
    }
};
