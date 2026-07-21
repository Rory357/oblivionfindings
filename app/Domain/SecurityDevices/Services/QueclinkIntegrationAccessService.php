<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One deny-by-default provider and destination-policy boundary for every
 * route-bound Queclink mutation.
 */
class QueclinkIntegrationAccessService
{
    public function __construct(private readonly SecurityDevicesAccessService $devices) {}

    public function assertDevice(User $user, QueclinkDevice $device): void
    {
        abort_unless($user->canDo('securityDevices.integrations.manage'), 403);

        if ($device->device_id === null) {
            abort_unless($this->devices->canViewUnassigned($user), 404);
        } else {
            abort_unless(
                $this->devices->visibleDevices($user)->whereKey($device->device_id)->exists(),
                404,
            );
        }
    }

    public function assertDeviceForRelease(User $user, QueclinkDevice $device): void
    {
        abort_unless($user->canDo('securityDevices.integrations.manage'), 403);
        abort_unless($device->device_id !== null, 404);

        $canonicalDevice = $this->devices->releasableDevices($user)->find($device->device_id);
        abort_unless($canonicalDevice instanceof Device, 404);
        $this->devices->assertCanReleaseActiveAssignment($user, $canonicalDevice);
    }

    public function assertCommand(User $user, QueclinkPendingCommand $command): void
    {
        abort_unless(
            $command->device !== null,
            404,
        );
        $this->assertDevice($user, $command->device);
    }

    public function assertPreset(User $user, QueclinkPreset $preset): void
    {
        abort_unless(
            $preset->is_system || $user->canDo('securityDevices.integrations.manage'),
            404,
        );
    }

    public function vehicle(User $user, int $id, bool $lockForUpdate = false): Asset
    {
        $asset = $this->devices->assignableVehicle($user, $id, $lockForUpdate);
        abort_unless($asset instanceof Asset, 404);

        return $asset;
    }

    public function assertAsset(User $user, ?Asset $asset): void
    {
        abort_unless($asset !== null, 404);
        abort_unless($this->devices->assignableAsset($user, (int) $asset->id) !== null, 404);
    }

    public function assertHistoricalAsset(User $user, ?Asset $asset): void
    {
        $this->assertAsset($user, $asset);
    }

    public function staff(User $user, int $id, bool $lockForUpdate = false): User
    {
        $staff = $this->devices->assignableStaffMember($user, $id, $lockForUpdate);
        abort_unless($staff instanceof User, 404);

        return $staff;
    }

    public function client(User $user, int $id, bool $lockForUpdate = false): Client
    {
        $client = $this->devices->assignableClient($user, $id, $lockForUpdate);
        abort_unless($client instanceof Client, 404);

        return $client;
    }

    /** @param array<int, int> $ids @return Collection<int, QueclinkDevice> */
    public function devicesForBulk(User $user, array $ids): Collection
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $ids)));
        $devices = QueclinkDevice::query()
            ->whereIn('id', $uniqueIds)
            ->get()
            ->keyBy('id');

        if ($devices->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'device_ids' => 'One or more selected devices could not be found.',
            ]);
        }

        foreach ($devices as $device) {
            $this->assertDevice($user, $device);
        }

        return $devices;
    }
}
