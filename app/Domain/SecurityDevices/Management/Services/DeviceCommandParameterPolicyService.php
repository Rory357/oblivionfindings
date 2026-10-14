<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use App\Domain\SecurityDevices\Models\Device;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Illuminate\Validation\ValidationException;

final class DeviceCommandParameterPolicyService
{
    public function __construct(
        private readonly QueclinkConfigurationProfileService $queclinkProfiles,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function assertAllowed(Device $device, CommandCapabilityDefinition $capability, array $parameters): void
    {
        if ($capability->key === 'endpoint.software.install') {
            EndpointSoftwareContract::parameters($parameters);
            app(EndpointSoftwarePackages::class)->published($parameters['package_uuid']);

            return;
        }
        if ($capability->key === 'endpoint.computer.restart') {
            EndpointRestartContract::parameters($parameters);

            return;
        }
        if ($capability->key === 'endpoint.service.manage') {
            if (! app(EndpointServiceCatalogue::class)->allows((string) ($parameters['service_name'] ?? ''), (string) ($parameters['operation'] ?? ''))) {
                throw ValidationException::withMessages(['parameters' => 'This service operation is not in the approved deployment catalogue.']);
            }

            return;
        }
        if ($capability->key !== 'configuration.apply') {
            return;
        }

        $this->queclinkProfiles->assertCompatible(
            $device,
            (int) ($parameters['configuration_profile_id'] ?? 0),
        );
    }
}
