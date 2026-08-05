<?php

namespace App\Services\Clients;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Client;
use App\Models\ClientPersonalAsset;
use App\Models\LocationHardware;
use App\Services\ConsentValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientPersonalAssetTrackerService
{
    private const TERMINAL_STATUSES = ['disposed', 'returned'];

    public function __construct(
        private readonly DeviceAssignmentService $deviceAssignments,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    public function replaceTracker(
        ClientPersonalAsset $asset,
        Client $client,
        ?int $newDeviceId,
        int $actorId,
    ): ClientPersonalAsset {
        return DB::transaction(function () use ($asset, $client, $newDeviceId, $actorId): ClientPersonalAsset {
            $lockedAsset = ClientPersonalAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $currentDeviceId = $this->canonicalDeviceId($lockedAsset);
            $deviceIds = collect([$currentDeviceId, $newDeviceId])
                ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            $devices = Device::query()
                ->whereKey($deviceIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $currentDevice = $currentDeviceId ? $devices->get($currentDeviceId) : null;
            $newDevice = $newDeviceId ? $devices->get($newDeviceId) : null;

            if ($newDeviceId !== null) {
                $this->assertEligibleDevice($newDevice, $client, $lockedAsset);
            }

            $activeAssignment = $newDevice
                ? DeviceAssignment::query()
                    ->where('device_id', $newDevice->id)
                    ->active()
                    ->orderByDesc('assigned_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first()
                : null;
            $assignmentMatchesClient = $activeAssignment !== null
                && $activeAssignment->assignable_type === DeviceAssignment::TARGET_CLIENT
                && (int) $activeAssignment->assignable_id === (int) $client->id;

            if ($activeAssignment !== null && ! $assignmentMatchesClient) {
                throw ValidationException::withMessages([
                    'tracker_device_id' => 'That tracking device is no longer unassigned.',
                ]);
            }

            if ($currentDevice !== null && (int) $currentDevice->id !== (int) $newDeviceId) {
                $this->deviceAssignments->releaseForTarget(
                    $currentDevice,
                    DeviceAssignment::TARGET_CLIENT,
                    (int) $client->id,
                    $actorId,
                    'assignment_replaced',
                );
            }

            $assignmentAuthorised = $assignmentMatchesClient
                && $this->trackingPrivacy->assignmentAuthorisesClient($activeAssignment, $client);
            if ($newDevice !== null && ! $assignmentAuthorised) {
                $consent = ConsentValidationService::latestValidTrackingConsentForClient($client);
                if ($consent === null) {
                    throw ValidationException::withMessages([
                        'tracker_device_id' => 'Assigning a personal tracker requires an active location tracking consent.',
                    ]);
                }

                try {
                    $this->deviceAssignments->assign(
                        device: $newDevice,
                        assignableType: DeviceAssignment::TARGET_CLIENT,
                        assignableId: (int) $client->id,
                        assignedByUserId: $actorId,
                        consentId: (int) $consent->id,
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'tracker_device_id' => $exception->getMessage(),
                    ]);
                }
            }

            $lockedAsset->forceFill(['tracker_device_id' => $newDeviceId])->save();

            return $lockedAsset->fresh();
        });
    }

    public function releaseTracker(
        ClientPersonalAsset $asset,
        Client $client,
        int $actorId,
    ): ?DeviceAssignment {
        return DB::transaction(function () use ($asset, $client, $actorId): ?DeviceAssignment {
            $lockedAsset = ClientPersonalAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $deviceId = $this->canonicalDeviceId($lockedAsset);
            if ($deviceId === null) {
                return null;
            }

            $device = Device::query()
                ->whereKey($deviceId)
                ->lockForUpdate()
                ->first();
            if ($device === null) {
                return null;
            }

            return $this->deviceAssignments->releaseForTarget(
                $device,
                DeviceAssignment::TARGET_CLIENT,
                (int) $client->id,
                $actorId,
            );
        });
    }

    private function canonicalDeviceId(ClientPersonalAsset $asset): ?int
    {
        if (is_numeric($asset->tracker_device_id) && (int) $asset->tracker_device_id > 0) {
            return (int) $asset->tracker_device_id;
        }
        if (! is_numeric($asset->tracker_hardware_id)) {
            return null;
        }

        $matches = Device::query()
            ->where('legacy_location_hardware_id', (int) $asset->tracker_hardware_id)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    private function assertEligibleDevice(
        ?Device $device,
        Client $client,
        ClientPersonalAsset $asset,
    ): void {
        $eligible = $device !== null
            && $device->domain === 'tracking'
            && ! in_array($device->getRawOriginal('status'), ['decommissioned', 'lost'], true)
            && is_numeric($client->site_id)
            && is_numeric($device->legacy_location_hardware_id)
            && LocationHardware::query()
                ->whereKey((int) $device->legacy_location_hardware_id)
                ->where('site_id', (int) $client->site_id)
                ->where('category', LocationHardware::CATEGORY_TRACKER)
                ->where('status', '!=', LocationHardware::STATUS_RETIRED)
                ->whereNull('deleted_at')
                ->exists()
            && $device->activeAssetLinks()
                ->select('id')
                ->first() === null;

        if (! $eligible) {
            throw ValidationException::withMessages([
                'tracker_device_id' => "Choose an eligible tracking device for the client's Site.",
            ]);
        }

        $usedByAnotherAsset = ClientPersonalAsset::query()
            ->where('tracker_device_id', $device->id)
            ->whereKeyNot($asset->id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->select('id')
            ->first() !== null;
        if ($usedByAnotherAsset) {
            throw ValidationException::withMessages([
                'tracker_device_id' => 'That tracking device is already linked to another active personal asset.',
            ]);
        }
    }
}
