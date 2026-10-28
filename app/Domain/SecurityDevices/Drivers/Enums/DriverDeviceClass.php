<?php

namespace App\Domain\SecurityDevices\Drivers\Enums;

/**
 * Home-Assistant-style device class taxonomy for Security & Devices drivers.
 */
enum DriverDeviceClass: string
{
    case Camera = 'camera';
    case AlarmPanel = 'alarm_panel';
    case AccessControl = 'access_control';
    case Sensor = 'sensor';
    case Tracker = 'tracker';
    case Network = 'network';
    case Telemetry = 'telemetry';
    case Computer = 'computer';

    public function label(): string
    {
        return match ($this) {
            self::Camera => 'Cameras & video',
            self::AlarmPanel => 'Alarms & intrusion',
            self::AccessControl => 'Doors & access control',
            self::Sensor => 'Sensors & environment',
            self::Tracker => 'GPS & personal trackers',
            self::Network => 'Network & Wi-Fi',
            self::Telemetry => 'Hub logs & traffic',
            self::Computer => 'Computers & endpoints',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Camera => 1,
            self::AlarmPanel => 2,
            self::AccessControl => 3,
            self::Sensor => 4,
            self::Tracker => 5,
            self::Network => 6,
            self::Telemetry => 7,
            self::Computer => 8,
        };
    }
}
