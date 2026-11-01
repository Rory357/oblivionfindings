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
 * Not proven on real hardware: the only live tracker has been offline since 20 May 2026, and the
 * console added in Gemini's phase 1 has never talked to a real unit. The earlier "tested live"
 * claim was removed in Phase A (docs/security-devices/GOAL.md, review item R13).
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
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Queclink @Track ASCII / HEX over persistent TCP daemon',
            models: ['GL300 / GL320MG', 'GL520MG / GL521MG', 'GV58LAU / GV350MG'],
            capabilities: [
                DriverCapability::TrackerLocation,
                DriverCapability::TrackerSosAlert,
                DriverCapability::TrackerBattery,
                DriverCapability::TrackerGeofence,
            ],
            summary: 'Receives GPS positions, SOS and panic alerts, battery level, safe-zone events and settings read back from paired Queclink trackers. Not tested on real hardware yet.',
            officialDocs: [
                ['label' => 'Queclink Wireless Telematics', 'url' => 'https://www.queclink.com/'],
            ],
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
