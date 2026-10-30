<?php

namespace App\Domain\SecurityDevices\Drivers\Trackers;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Queclink Wireless `@Track` personal & vehicle GPS tracker driver.
 *
 * Verified live against the `oblivion-queclink.service` TCP listener.
 */
final class QueclinkTrackerDriver implements DeviceDriver
{
    public const string KEY = 'tracker.queclink_at_track';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Queclink Wireless',
            name: 'Queclink @Track GPS & SOS Telemetry',
            deviceClass: DriverDeviceClass::Tracker,
            supportLevel: DriverSupportLevel::TestedOnHardware,
            transportSummary: 'Queclink @Track ASCII / HEX over persistent TCP daemon',
            models: ['GL300 / GL320MG', 'GL520MG / GL521MG', 'GV58LAU / GV350MG'],
            capabilities: [
                DriverCapability::TrackerLocation,
                DriverCapability::TrackerSosAlert,
                DriverCapability::TrackerBattery,
                DriverCapability::TrackerGeofence,
            ],
            summary: 'Receives live GPS position fixes, SOS / panic button alerts, battery percentage, geofence events, and over-the-air configuration readbacks from paired Queclink personal and fleet trackers.',
            officialDocs: [
                ['label' => 'Queclink Wireless Telematics', 'url' => 'https://www.queclink.com/'],
            ],
            hardwareEvidence: 'Tested live against the production `oblivion-queclink.service` TCP socket daemon.',
            setupHref: '/security-devices/devices',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'tracking') {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'queclink') || str_starts_with(strtolower(trim((string) $device->model)), 'gl') || str_starts_with(strtolower(trim((string) $device->model)), 'gv');
    }
}
