<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Models\Device;
use App\Services\Queclink\QueclinkConfigurationProfileService;

final class DeviceCommandParameterPolicyService
{
    public function __construct(
        private readonly QueclinkConfigurationProfileService $queclinkProfiles,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function assertAllowed(Device $device, CommandCapabilityDefinition $capability, array $parameters): void
    {
        if ($capability->key !== 'configuration.apply') {
            return;
        }

        $this->queclinkProfiles->assertCompatible(
            $device,
            (int) ($parameters['configuration_profile_id'] ?? 0),
        );
    }
}
