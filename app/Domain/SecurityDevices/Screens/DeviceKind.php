<?php

namespace App\Domain\SecurityDevices\Screens;

use App\Domain\SecurityDevices\Models\Device;

/**
 * What a device is, in the rebuild's words (plan v10, "Every new page"): the kind decides its
 * Devices tab and which device page it gets. Worked out once, here, so the Devices list, the
 * device page and the Site page always agree.
 */
enum DeviceKind: string
{
    case Switch = 'switch';
    case Router = 'router';
    case WifiPoint = 'wifi_point';
    case NetworkDevice = 'network_device';
    case Camera = 'camera';
    case Recorder = 'recorder';
    case AlarmPanel = 'alarm_panel';
    case Door = 'door';
    case Sensor = 'sensor';
    case SensorGateway = 'sensor_gateway';
    case CareTracker = 'care_tracker';
    case VehicleTracker = 'vehicle_tracker';
    case Computer = 'computer';
    case Ups = 'ups';
    case Printer = 'printer';
    case Hub = 'hub';
    case Other = 'other';

    /** The Devices tab it belongs to. */
    public function tab(): string
    {
        return match ($this) {
            self::Switch, self::Router, self::WifiPoint, self::NetworkDevice, self::Hub => 'network',
            self::Camera, self::Recorder => 'cameras',
            self::AlarmPanel, self::Door => 'alarms_doors',
            self::Sensor, self::SensorGateway => 'sensors',
            self::CareTracker, self::VehicleTracker => 'trackers',
            self::Computer => 'computers',
            self::Ups, self::Printer, self::Other => 'other',
        };
    }

    /** Plain name for one of them, e.g. "Network switch". */
    public function label(): string
    {
        return match ($this) {
            self::Switch => 'Network switch',
            self::Router => 'Router',
            self::WifiPoint => 'Wi-Fi point',
            self::NetworkDevice => 'Network device',
            self::Camera => 'Camera',
            self::Recorder => 'Video recorder',
            self::AlarmPanel => 'Alarm panel',
            self::Door => 'Door',
            self::Sensor => 'Sensor',
            self::SensorGateway => 'Sensor gateway',
            self::CareTracker => 'Care tracker',
            self::VehicleTracker => 'Vehicle tracker',
            self::Computer => 'Computer',
            self::Ups => 'UPS (power)',
            self::Printer => 'Printer',
            self::Hub => 'Hub',
            self::Other => 'Other device',
        };
    }

    public static function of(Device $device): self
    {
        return self::from_(
            (string) ($device->domain instanceof \BackedEnum ? $device->domain->value : $device->domain),
            strtolower((string) $device->category),
            strtolower((string) $device->subcategory),
        );
    }

    public static function from_(string $domain, string $category, string $subcategory): self
    {
        $sub = $subcategory;

        return match (true) {
            $category === 'monitoring_collector' => self::Hub,
            in_array($sub, ['switch', 'poe_switch'], true) => self::Switch,
            in_array($sub, ['router', 'gateway', 'firewall'], true) => self::Router,
            in_array($sub, ['wireless_ap', 'access_point', 'wifi'], true) => self::WifiPoint,
            in_array($sub, ['nvr', 'dvr', 'recorder'], true) => self::Recorder,
            $category === 'cctv' || str_contains($sub, 'camera') => self::Camera,
            in_array($category, ['alarm', 'alarm_panel', 'intrusion'], true) || str_contains($sub, 'alarm') => self::AlarmPanel,
            in_array($category, ['access_control', 'door'], true) || str_contains($sub, 'door') || str_contains($sub, 'reader') => self::Door,
            in_array($sub, ['lorawan_gateway', 'sensor_gateway'], true) => self::SensorGateway,
            $category === 'personal_tracker' || in_array($sub, ['wearable_gps', 'wearable_fall'], true) => self::CareTracker,
            $category === 'vehicle_tracker' || $sub === 'hardwired_gps' => self::VehicleTracker,
            $category === 'power' || $sub === 'ups' => self::Ups,
            $category === 'printer' || $sub === 'printer' => self::Printer,
            in_array($category, ['endpoint', 'server'], true) => self::Computer,
            $category === 'network' => self::NetworkDevice,
            in_array($domain, ['iot_healthcare', 'facilities'], true)
                || in_array($category, ['sensor', 'cold_chain', 'fall_detection', 'environment'], true)
                || str_contains($sub, 'sensor') => self::Sensor,
            $domain === 'tracking' => self::CareTracker,
            default => self::Other,
        };
    }
}
