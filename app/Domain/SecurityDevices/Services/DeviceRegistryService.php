<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class DeviceRegistryService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceAssignmentService $deviceAssignments,
        private readonly DeviceLinkService $deviceLinks,
        private readonly NativeMonitoringDefinitionService $monitoringDefinitions,
    ) {}

    /** Base query for the single application registry. */
    public function query(): Builder
    {
        return Device::query();
    }

    /**
     * Register a reviewed discovery result in the one canonical Device registry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerDiscoveredDevice(array $attributes, int $siteId, int $actorId): Device
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->first();
        $actor = User::query()->whereKey($actorId)->whereNotNull('approved_at')->first();
        $name = trim((string) ($attributes['name'] ?? ''));
        $domain = DeviceDomain::tryFrom((string) ($attributes['domain'] ?? ''));
        $category = trim((string) ($attributes['category'] ?? ''));
        if ($site === null || $actor === null || $name === '' || $domain === null || $category === '') {
            throw new UnexpectedValueException('Reviewed discovery target is unavailable.');
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'name',
            'domain',
            'category',
            'subcategory',
            'manufacturer',
            'model',
            'serial_number',
            'mac_address',
            'firmware_version',
            'ip_address',
            'provider',
            'external_ref',
        ]));
        $allowed['name'] = $name;
        $allowed['domain'] = $domain->value;
        $allowed['category'] = $category;
        $allowed['status'] = DeviceStatus::Active->value;
        $allowed['created_by_user_id'] = $actor->id;

        return DB::transaction(function () use ($allowed, $site, $actor): Device {
            $device = Device::query()->create($allowed);
            $this->deviceAssignments->assign(
                device: $device,
                assignableType: DeviceAssignment::TARGET_SITE,
                assignableId: (int) $site->id,
                assignedByUserId: (int) $actor->id,
                assignmentType: AssignmentType::Permanent,
            );

            return $device;
        }, 3);
    }

    /**
     * Decommission the canonical Device without leaving live ownership,
     * monitoring or provider execution behind.
     */
    public function decommission(Device $device, User $actor): Device
    {
        return DB::transaction(function () use ($device, $actor): Device {
            $lockedActor = User::query()
                ->whereKey($actor->getKey())
                ->whereNotNull('approved_at')
                ->first();
            abort_unless($lockedActor?->canDo('securityDevices.devices.delete'), 403);

            $lockedDevice = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-evaluate visibility after the Device lock. A concurrent Site
            // move must not turn an earlier authorised lookup into a write.
            $this->access->assertCanViewDevice($lockedActor, $lockedDevice);
            $this->assertProviderLifecycleClosed($lockedDevice);
            $this->monitoringDefinitions->assertDeviceCanBeDecommissioned($lockedDevice);

            // Write the integrity-sensitive audit while canonical assignment
            // scope still exists. Any later failure rolls this row back too.
            AuditLogger::logOrFail('device.decommissioned', $lockedDevice, [
                'actor_id' => (int) $lockedActor->id,
                'fields' => ['status', 'released_at', 'unlinked_at', 'deleted_at'],
                'before' => ['status' => $lockedDevice->getRawOriginal('status')],
                'after' => ['status' => DeviceStatus::Decommissioned->value],
            ]);

            $this->deviceAssignments->releaseAllForDecommission($lockedDevice, (int) $lockedActor->id);
            $this->deviceLinks->unlinkAllForDevice($lockedDevice, 'device_decommissioned');

            $lockedDevice->forceFill(['status' => DeviceStatus::Decommissioned->value])->save();
            $lockedDevice->delete();

            return $lockedDevice;
        }, 3);
    }

    private function assertProviderLifecycleClosed(Device $device): void
    {
        $provider = strtolower(trim((string) $device->provider));
        $providerEntityId = data_get($device->external_ref, 'provider_entity_id');
        $hasExternalIdentity = $provider !== 'queclink'
            && is_scalar($providerEntityId)
            && trim((string) $providerEntityId) !== '';
        $hasQueclinkBinding = QueclinkDevice::query()
            ->where('device_id', $device->id)
            ->lockForUpdate()
            ->get(['id'])
            ->isNotEmpty();
        // Queclink's existing release lifecycle deliberately retains provider
        // provenance on the canonical Device while clearing its live binding.
        $isExternallyManaged = ! in_array($provider, ['', 'manual', 'native', 'oblivion', 'queclink'], true);

        if ($hasQueclinkBinding || $hasExternalIdentity || $isExternallyManaged) {
            throw ValidationException::withMessages([
                'device' => 'Release this Device from its provider integration before decommissioning it.',
            ]);
        }
    }

    /**
     * Devices with an active assignment to a given site (including room-level assignments
     * within that site).
     */
    public function forSite(int $siteId): Builder
    {
        $roomIds = SiteRoom::where('site_id', $siteId)->pluck('id');

        return $this->applySiteScope($this->query(), $siteId, $roomIds);
    }

    public function visibleForSite(User $user, int $siteId): Builder
    {
        $roomIds = SiteRoom::where('site_id', $siteId)->pluck('id');

        return $this->applySiteScope($this->access->visibleDevices($user), $siteId, $roomIds);
    }

    /**
     * Move a canonical Device between a Site and one of its rooms.
     *
     * This is the sole generic Site Profile placement path. It preserves the
     * released assignment as history and fails closed if the Device moved to
     * another Site after the caller performed its permission-scoped lookup.
     */
    public function placeWithinSite(
        Device $device,
        int $expectedSiteId,
        ?int $roomId,
        int $actorId,
    ): DeviceAssignment {
        return DB::transaction(function () use ($device, $expectedSiteId, $roomId, $actorId): DeviceAssignment {
            $siteExists = Site::query()
                ->whereKey($expectedSiteId)
                ->lockForUpdate()
                ->exists();
            $actorExists = User::query()
                ->whereKey($actorId)
                ->whereNotNull('approved_at')
                ->exists();
            $lockedDevice = Device::query()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->first();

            if (! $siteExists || ! $actorExists || $lockedDevice === null) {
                throw new UnexpectedValueException('Canonical Site placement is unavailable.');
            }

            $activeAssignments = $lockedDevice->assignments()
                ->active()
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            if ($activeAssignments->count() !== 1
                || $this->assignmentSiteId($activeAssignments->first()) !== $expectedSiteId) {
                throw new UnexpectedValueException('Canonical Device no longer belongs to the expected Site.');
            }

            $room = $roomId === null
                ? null
                : SiteRoom::query()
                    ->whereKey($roomId)
                    ->where('site_id', $expectedSiteId)
                    ->lockForUpdate()
                    ->first();
            if ($roomId !== null && $room === null) {
                throw new UnexpectedValueException('Canonical room placement is unavailable.');
            }

            $targetType = $room === null
                ? DeviceAssignment::TARGET_SITE
                : DeviceAssignment::TARGET_ROOM;
            $targetId = $room?->getKey() ?? $expectedSiteId;
            $active = $activeAssignments->first();
            if ($active->assignable_type === $targetType
                && (int) $active->assignable_id === (int) $targetId) {
                return $active;
            }

            return $this->deviceAssignments->assign(
                device: $lockedDevice,
                assignableType: $targetType,
                assignableId: (int) $targetId,
                assignedByUserId: $actorId,
                assignmentType: AssignmentType::Permanent,
            );
        }, 3);
    }

    private function applySiteScope(Builder $query, int $siteId, $roomIds): Builder
    {
        return $query
            ->whereHas('assignments', function (Builder $q) use ($siteId, $roomIds) {
                $q->active()->where(function (Builder $q) use ($siteId, $roomIds) {
                    $q->where(function ($q) use ($siteId) {
                        $q->where('assignable_type', DeviceAssignment::TARGET_SITE)
                            ->where('assignable_id', $siteId);
                    });

                    if ($roomIds->isNotEmpty()) {
                        $q->orWhere(function ($q) use ($roomIds) {
                            $q->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                                ->whereIn('assignable_id', $roomIds);
                        });
                    }
                });
            })
            ->whereDoesntHave('assignments', function (Builder $q) use ($siteId, $roomIds): void {
                $q->active()->where(function (Builder $outside) use ($siteId, $roomIds): void {
                    $outside->where(function (Builder $site) use ($siteId): void {
                        $site->where('assignable_type', DeviceAssignment::TARGET_SITE)
                            ->where('assignable_id', '!=', $siteId);
                    })->orWhere(function (Builder $room) use ($roomIds): void {
                        $room->where('assignable_type', DeviceAssignment::TARGET_ROOM);
                        if ($roomIds->isEmpty()) {
                            return;
                        }
                        $room->whereNotIn('assignable_id', $roomIds);
                    });
                });
            });
    }

    private function assignmentSiteId(DeviceAssignment $assignment): ?int
    {
        if ($assignment->assignable_type === DeviceAssignment::TARGET_SITE) {
            $siteId = Site::query()->whereKey($assignment->assignable_id)->value('id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $siteId = SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        return null;
    }

    /**
     * Devices with an active assignment to a given client.
     */
    public function forClient(int $clientId): Builder
    {
        return $this->query()
            ->whereHas('assignments', function (Builder $q) use ($clientId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_CLIENT, $clientId);
            });
    }

    /**
     * Devices with an active asset link to a given vehicle/asset (e.g. trackers installed in a vehicle).
     */
    public function forVehicle(int $assetId): Builder
    {
        return $this->query()
            ->whereHas('assetLinks', function (Builder $q) use ($assetId) {
                $q->active()->forAsset($assetId);
            });
    }

    /**
     * Devices with an active assignment to a given staff member.
     */
    public function forStaff(int $userId): Builder
    {
        return $this->query()
            ->whereHas('assignments', function (Builder $q) use ($userId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_STAFF, $userId);
            });
    }

    /**
     * Devices with no active assignment (pooled stock / available for checkout).
     */
    public function unassigned(): Builder
    {
        return $this->query()
            ->whereDoesntHave('assignments', function (Builder $q) {
                $q->active();
            });
    }

    /**
     * Devices filtered by domain.
     */
    public function byDomain(string|DeviceDomain $domain): Builder
    {
        return $this->query()->byDomain($domain);
    }

    /**
     * Devices filtered by category.
     */
    public function byCategory(string $category): Builder
    {
        return $this->query()->byCategory($category);
    }

    /**
     * Devices that belong to a specific group.
     */
    public function forGroup(int $groupId): Builder
    {
        return $this->query()
            ->whereHas('groups', function (Builder $q) use ($groupId) {
                $q->where('device_groups.id', $groupId);
            });
    }

    /**
     * Devices linked to a specific asset (via device_asset_links).
     */
    public function linkedToAsset(int $assetId): Builder
    {
        return Device::query()
            ->whereHas('activeAssetLinks', function (Builder $q) use ($assetId) {
                $q->forAsset($assetId);
            });
    }

    /**
     * Assets linked to a specific device.
     */
    public function assetsForDevice(int $deviceId): Builder
    {
        return Asset::query()
            ->whereHas('activeDeviceLinks', function (Builder $q) use ($deviceId) {
                $q->forDevice($deviceId);
            });
    }
}
