<?php

namespace App\Services\Queclink;

use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Validation\ValidationException;

final class LocateNowService
{
    public function __construct(
        private readonly DeclaredDeviceCommandCapabilities $declaredCapabilities,
    ) {}

    public function managementUrlForDevice(Device $device): string
    {
        if (! $this->declaredCapabilities->supports($device, 'tracking.location_refresh')) {
            throw ValidationException::withMessages([
                'tracker' => 'This tracker is not linked to a canonical paired Queclink Device.',
            ]);
        }

        return "/security-devices/devices/{$device->id}?section=management&action=tracking.location_refresh";
    }
}
