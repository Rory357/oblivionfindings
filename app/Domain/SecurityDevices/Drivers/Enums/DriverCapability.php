<?php

namespace App\Domain\SecurityDevices\Drivers\Enums;

/**
 * Capabilities declared by Security & Devices drivers.
 */
enum DriverCapability: string
{
    case CameraSnapshot = 'camera.snapshot';
    case CameraStreamUri = 'camera.stream_uri';
    case CameraPtz = 'camera.ptz';
    case CameraDeviceInfo = 'camera.device_info';

    case AlarmEventsSiaDc09 = 'alarm.events_sia_dc09';
    case AlarmZoneStatus = 'alarm.zone_status';
    case AlarmArm = 'alarm.arm';
    case AlarmDisarm = 'alarm.disarm';

    case AccessDoorStatus = 'access.door_status';
    case AccessDoorUnlock = 'access.door_unlock';
    case AccessEvents = 'access.events';

    case SensorReadings = 'sensor.readings';
    case SensorBattery = 'sensor.battery';
    case SensorContactAlert = 'sensor.contact_alert';
    case SensorButtonAlert = 'sensor.button_alert';

    case TrackerLocation = 'tracker.location';
    case TrackerSosAlert = 'tracker.sos_alert';
    case TrackerBattery = 'tracker.battery';
    case TrackerGeofence = 'tracker.geofence';

    case NetworkDiscovery = 'network.discovery';
    case NetworkTopology = 'network.topology';
    case NetworkHealth = 'network.health';
    case NetworkConfigBackup = 'network.config_backup';
    case NetworkPoeCycle = 'network.poe_cycle';
    case NetworkReboot = 'network.reboot';

    case TelemetrySyslog = 'telemetry.syslog';
    case TelemetryNetflow = 'telemetry.netflow';

    public function label(): string
    {
        return match ($this) {
            self::CameraSnapshot => 'Snapshot',
            self::CameraStreamUri => 'Stream URL',
            self::CameraPtz => 'Pan, tilt & zoom',
            self::CameraDeviceInfo => 'Device details',
            self::AlarmEventsSiaDc09 => 'SIA DC-09 / Contact ID events',
            self::AlarmZoneStatus => 'Zone & area status',
            self::AlarmArm => 'Arm area',
            self::AlarmDisarm => 'Disarm area',
            self::AccessDoorStatus => 'Door status',
            self::AccessDoorUnlock => 'Unlock door',
            self::AccessEvents => 'Door & badge events',
            self::SensorReadings => 'Temperature, air & environment',
            self::SensorBattery => 'Battery level',
            self::SensorContactAlert => 'Door, leak & motion alerts',
            self::SensorButtonAlert => 'Call & panic button alerts',
            self::TrackerLocation => 'Live GPS location',
            self::TrackerSosAlert => 'SOS & fall alerts',
            self::TrackerBattery => 'Battery & signal',
            self::TrackerGeofence => 'Safe-zone alerts',
            self::NetworkDiscovery => 'Automatic LAN discovery',
            self::NetworkTopology => 'Switch & Wi-Fi map',
            self::NetworkHealth => 'Uptime & port health',
            self::NetworkConfigBackup => 'SSH configuration backup & diff',
            self::NetworkPoeCycle => 'PoE port power-cycle',
            self::NetworkReboot => 'Device restart',
            self::TelemetrySyslog => 'Syslog through Site hub',
            self::TelemetryNetflow => 'NetFlow & IPFIX through Site hub',
        };
    }

    public function requiresConfirmationAndAudit(): bool
    {
        return in_array($this, [
            self::AlarmArm,
            self::AlarmDisarm,
            self::AccessDoorUnlock,
            self::NetworkConfigBackup,
            self::NetworkPoeCycle,
            self::NetworkReboot,
        ], true);
    }
}
