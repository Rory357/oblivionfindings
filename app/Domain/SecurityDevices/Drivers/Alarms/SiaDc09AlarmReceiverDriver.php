<?php

namespace App\Domain\SecurityDevices\Drivers\Alarms;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Standard ANSI/SIA DC-09-2013 & Ademco Contact ID (SIA DC-05) IP alarm receiver driver.
 *
 * Official specification:
 * - https://www.securityindustry.org/standards/dc-09/
 */
final class SiaDc09AlarmReceiverDriver implements DeviceDriver
{
    public const string KEY = 'alarm.sia_dc09_receiver';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'ANSI / SIA Standard',
            name: 'SIA DC-09 & Contact ID IP Receiver',
            deviceClass: DriverDeviceClass::AlarmPanel,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'SIA DC-09 over TCP/UDP, Ademco Contact ID (DC-05), SIA-DCS, optional AES-128/256-CBC',
            models: ['Arrowhead ESL-2 / Elite S', 'ICT Protege', 'Bosch Solution 2000/3000/6000', 'Ajax Hub', 'DSC PowerSeries Neo', 'Paradox IP150'],
            capabilities: [
                DriverCapability::AlarmEventsSiaDc09,
                DriverCapability::AlarmZoneStatus,
            ],
            summary: 'Receives live arm, disarm, burglary, fire, medical, panic, duress, tamper, bypass, and battery events from any NZ or international alarm panel supporting SIA DC-09 IP reporting.',
            officialDocs: [
                ['label' => 'ANSI/SIA DC-09 Standard', 'url' => 'https://www.securityindustry.org/standards/dc-09/'],
            ],
            limitationsNote: 'SIA DC-09 is a one-way panel-to-receiver reporting standard; remote arming and disarming require a brand-specific local API driver.',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        if ($device->domain !== 'security' || ! in_array($device->category, ['alarm', 'perimeter'], true)) {
            return false;
        }

        $account = trim((string) (
            data_get($device->config, 'alarm.sia_account')
            ?? data_get($device->config, 'sia_account')
            ?? data_get($device->meta, 'sia_account')
            ?? ''
        ));
        if ($account !== '') {
            return true;
        }

        $protocol = strtolower(trim((string) (
            data_get($device->config, 'alarm.protocol')
            ?? data_get($device->meta, 'protocol')
            ?? $device->provider
            ?? ''
        )));

        return in_array($protocol, ['sia_dc09', 'sia-dc09', 'contact_id', 'contact-id', 'adm_cid'], true);
    }
}
