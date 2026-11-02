<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Ajax Systems Hub / Hub 2 / Hub Plus descriptor.
 *
 * Official SIA DC-09 setup guide:
 * - https://support.ajax.systems/en/manuals/hub/connect-to-cms-via-sia/
 */
final class AjaxHubAlarmDriver implements DeviceDriver
{
    public const string KEY = 'alarm.ajax_hub_native';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Ajax Systems',
            name: 'Ajax Hub (alarm reports)',
            deviceClass: DriverDeviceClass::AlarmPanel,
            // Phase A: reports come in through the fixed alarm receiver (checked against reference frames),
            // so this is built; it stays view only and isn't walked on Stephan's Ajax (review item R7).
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Alarm reports (SIA DC-09, can be encrypted) to Oblivion’s alarm receiver · no direct control',
            models: ['Ajax Hub 2 (4G)', 'Ajax Hub 2 Plus', 'Ajax Hub Hybrid'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'An Ajax hub can send its armed state, zones and events to Oblivion’s alarm receiver; you set it up in the Ajax PRO app. View only: arm and disarm in the Ajax app or at the keypad. Not tested on real hardware.',
            officialDocs: [
                ['label' => 'Ajax Hub SIA DC-09 Receiver Setup', 'url' => 'https://support.ajax.systems/en/manuals/hub/connect-to-cms-via-sia/'],
            ],
            limitationsNote: 'Ajax hubs have no local control API. Arming and disarming from Oblivion would need Ajax’s partner API, which Oblivion doesn’t have.',
            viewOnly: true,
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || ! in_array($device->category, ['alarm', 'perimeter'], true)) {
            return false;
        }

        $haystack = strtolower(trim(implode(' ', [
            (string) $device->manufacturer,
            (string) $device->model,
            (string) $device->provider,
        ])));

        return str_contains($haystack, 'ajax');
    }
}
