<?php

namespace App\Domain\SecurityDevices\Drivers\Endpoints;

use App\Domain\SecurityDevices\Drivers\Contracts\DeviceDriver;
use App\Domain\SecurityDevices\Drivers\Data\DriverMetadata;
use App\Domain\SecurityDevices\Drivers\Enums\DriverCapability;
use App\Domain\SecurityDevices\Drivers\Enums\DriverDeviceClass;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Endpoints\Presenters\EndpointDevicePosturePresenter;
use App\Domain\SecurityDevices\Models\Device;

/**
 * Microsoft Intune read-only Windows computer discovery and compliance posture driver descriptor.
 *
 * Official Microsoft documentation:
 * - https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0
 * - https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow
 */
final class MicrosoftIntuneDriver implements DeviceDriver
{
    public const string KEY = 'computer.microsoft_intune_graph';

    public function key(): string
    {
        return self::KEY;
    }

    public function metadata(): DriverMetadata
    {
        return new DriverMetadata(
            key: self::KEY,
            brand: 'Microsoft Intune',
            name: 'Microsoft Intune Read-Only Discovery & Compliance',
            deviceClass: DriverDeviceClass::Computer,
            supportLevel: DriverSupportLevel::BuiltFromDocs,
            transportSummary: 'Microsoft Entra OAuth 2.0 client credentials · Microsoft Graph v1.0 HTTPS',
            models: [
                'Intune-enrolled Windows 10 / 11 PCs',
                'Intune-enrolled Windows Servers',
            ],
            capabilities: [
                DriverCapability::ComputerIntuneSync,
                DriverCapability::ComputerPosture,
            ],
            summary: 'Discovers Windows computers enrolled in Microsoft Intune using read-only Microsoft Graph permissions (DeviceManagementManagedDevices.Read.All), imports selected computers into Devices assigned to a Site, and refreshes compliance, OS version, and encryption posture every 30 minutes.',
            officialDocs: [
                [
                    'label' => 'Microsoft Graph Intune managedDevice List API',
                    'url' => 'https://learn.microsoft.com/en-us/graph/api/intune-devices-manageddevice-list?view=graph-rest-1.0',
                ],
                [
                    'label' => 'Microsoft Identity Platform Client Credentials Flow',
                    'url' => 'https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow',
                ],
            ],
            limitationsNote: 'Read-only visibility and bulk import only. Remote wipe, policy editing, and script push are not performed.',
            setupHref: '/security-devices/integrations/intune',
        );
    }

    public function matchesDevice(Device $device): bool
    {
        return EndpointDevicePosturePresenter::isComputer($device)
            && strtolower(trim((string) $device->provider)) === 'intune';
    }
}
