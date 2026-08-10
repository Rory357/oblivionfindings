<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Security & Devices authorization boundary.
 *
 * Oblivion Findings is one application. Device visibility comes from current
 * operational Site access plus the Client, staff, Fleet/Asset and privacy
 * policies that own the assigned record. Legacy partition columns are never
 * consulted here.
 */
class SecurityDevicesAccessService
{
    private const ASSIGNMENT_PICKER_LIMIT = 500;

    /** @return list<int> */
    public function accessibleSiteIds(User $user): array
    {
        if ($this->canViewAllSites($user)) {
            return $this->operationalSites()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        }

        $profile = HrEmployeeProfile::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', today());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', today());
            })
            ->first(['primary_site_id', 'secondary_site_ids']);

        $ids = collect([
            $profile?->primary_site_id,
            ...($profile?->secondary_site_ids ?? []),
        ]);

        // Historic schemas may carry a direct pointer. It is accepted only as
        // a Site identifier; Site remains the canonical authorization record.
        if (Schema::hasColumn('users', 'site_id')) {
            $ids->push(User::query()->whereKey($user->getKey())->value('site_id'));
        }

        $ids = $ids
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->operationalSites()
            ->whereKey($ids->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function canViewAllSites(User $user): bool
    {
        return $user->canDo('securityDevices.devices.viewAllSites');
    }

    public function canViewUnassigned(User $user): bool
    {
        return $user->canDo('securityDevices.devices.viewUnassigned')
            || $user->canDo('assets.trackers.manage');
    }

    public function unassignedTrackingDevicesForClient(User $user, Client $client): Builder
    {
        $query = $this->visibleDevices($user)
            ->where('domain', 'tracking')
            ->whereNotIn('status', ['decommissioned', 'lost'])
            ->whereNotNull('legacy_location_hardware_id')
            ->whereDoesntHave('assignments', fn (Builder $assignment): Builder => $assignment->active())
            ->whereDoesntHave('activeAssetLinks');

        if (! $this->canViewUnassigned($user) || ! is_numeric($client->site_id)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($hardware) use ($client): void {
            $hardware->selectRaw('1')
                ->from('location_hardware')
                ->whereColumn('location_hardware.id', 'devices.legacy_location_hardware_id')
                ->where('location_hardware.site_id', (int) $client->site_id)
                ->where('location_hardware.category', LocationHardware::CATEGORY_TRACKER)
                ->where('location_hardware.status', '!=', LocationHardware::STATUS_RETIRED)
                ->whereNull('location_hardware.deleted_at');
        });
    }

    public function unassignedTrackingDeviceForClient(
        User $user,
        Client $client,
        int $deviceId,
        bool $lockForUpdate = false,
    ): ?Device {
        $query = $this->unassignedTrackingDevicesForClient($user, $client)->whereKey($deviceId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function assignableStaff(User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        $query = User::query()
            ->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', fn (Builder $profile): Builder => $this->applyCurrentStaffSiteScope($profile, $siteIds));

        if (! $user->canDo('staff.viewAny') || ! $user->canDo('hazards.view')) {
            $query->whereKey($user->getKey());
        }

        return $query;
    }

    public function assignableStaffTargets(User $user, ?string $search = null, ?int $selectedId = null): Collection
    {
        $search = trim((string) $search);
        $staff = $this->assignableStaff($user)
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::ASSIGNMENT_PICKER_LIMIT)
            ->get(['id', 'name']);

        if ($selectedId !== null && ! $staff->contains('id', $selectedId)) {
            $selected = $this->assignableStaffMember($user, $selectedId);
            if ($selected) {
                $staff->prepend($selected);
            }
        }

        return $staff->values();
    }

    public function assignableStaffMember(User $user, int $id, bool $lockForUpdate = false): ?User
    {
        $query = $this->assignableStaff($user)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function assignableClients(User $user, ?string $search = null, ?int $selectedId = null): Collection
    {
        $search = trim((string) $search);
        $clients = $this->assignableClientQuery($user)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $name) use ($search): void {
                $name->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->limit(self::ASSIGNMENT_PICKER_LIMIT)
            ->get()
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->values();

        if ($selectedId !== null && ! $clients->contains('id', $selectedId)) {
            $selected = $this->assignableClient($user, $selectedId);
            if ($selected) {
                $clients->prepend($selected);
            }
        }

        return $clients->values();
    }

    public function assignableClient(User $user, int $id, bool $lockForUpdate = false): ?Client
    {
        $query = $this->assignableClientQuery($user)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $client = $query->first();

        return $client instanceof Client && Gate::forUser($user)->allows('view', $client)
            ? $client
            : null;
    }

    /** @return list<int> */
    public function authorizedClientIds(User $user): array
    {
        return $this->assignableClientQuery($user)
            ->get()
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function assignableVehicles(User $user, ?string $search = null, ?int $selectedId = null): Collection
    {
        $search = trim((string) $search);
        $assets = $this->assetCandidateQuery($user, true)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $name) use ($search): void {
                $name->where('name', 'like', "%{$search}%")
                    ->orWhere('registration_number', 'like', "%{$search}%");
            }))
            ->with(['site:id', 'homeSite:id', 'client:id,site_id', 'categoryRef:id,slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::ASSIGNMENT_PICKER_LIMIT)
            ->get()
            ->filter(fn (Asset $asset): bool => $user->canDo('fleet.viewAny') || Gate::forUser($user)->allows('view', $asset))
            ->values();

        if ($selectedId !== null && ! $assets->contains('id', $selectedId)) {
            $selected = $this->assignableVehicle($user, $selectedId);
            if ($selected) {
                $assets->prepend($selected);
            }
        }

        return $assets;
    }

    public function assignableVehicle(User $user, int $id, bool $lockForUpdate = false): ?Asset
    {
        $query = $this->assetCandidateQuery($user, true)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $asset = $query->first();

        return $asset instanceof Asset
            && ($user->canDo('fleet.viewAny') || Gate::forUser($user)->allows('view', $asset))
                ? $asset
                : null;
    }

    /**
     * Canonical Asset register scope for application modules that need a
     * pageable query rather than a bounded assignment picker.
     */
    public function accessibleAssets(User $user, bool $vehiclesOnly = false): Builder
    {
        $query = $this->assetCandidateQuery($user, $vehiclesOnly);

        if ($user->canDo('assets.viewAny')) {
            return $query;
        }

        return $this->applyAssignedAssetPolicyScope($query, $user);
    }

    /** Canonical operational Sites available to the current actor. */
    public function accessibleSites(User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);

        return $this->operationalSites()
            ->when($siteIds === [], fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn (Builder $query): Builder => $query->whereKey($siteIds));
    }

    /** @return Collection<int, Asset> */
    public function assignableAssets(User $user, ?string $search = null): Collection
    {
        $search = trim((string) $search);

        return $this->assetCandidateQuery($user)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $name) use ($search): void {
                $name->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(self::ASSIGNMENT_PICKER_LIMIT)
            ->get()
            ->filter(fn (Asset $asset): bool => Gate::forUser($user)->allows('view', $asset))
            ->values();
    }

    public function assignableAsset(User $user, int $id, bool $lockForUpdate = false): ?Asset
    {
        $query = $this->assetCandidateQuery($user)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $asset = $query->first();

        return $asset instanceof Asset && Gate::forUser($user)->allows('view', $asset)
            ? $asset
            : null;
    }

    /** @return list<int> */
    public function authorizedAssetIds(User $user): array
    {
        return $this->assetCandidateQuery($user)
            ->with('categoryRef:id,slug')
            ->get()
            ->filter(function (Asset $asset) use ($user): bool {
                $isVehicle = strcasecmp((string) $asset->category, 'vehicle') === 0
                    || $asset->categoryRef?->slug === 'vehicle';

                return ($isVehicle && $user->canDo('fleet.viewAny'))
                    || Gate::forUser($user)->allows('view', $asset);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function visibleDevices(User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        $roomIds = $siteIds === []
            ? []
            : SiteRoom::query()->whereIn('site_id', $siteIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $clientIds = $this->accessibleAssignedClientIds($user);
        $staffIds = array_values(array_unique([(int) $user->getKey(), ...$this->accessibleAssignedStaffIds($user)]));
        $assetIds = $this->accessibleAssetIds($user);

        $query = Device::query()->where(function (Builder $visibility) use (
            $user,
            $siteIds,
            $roomIds,
            $clientIds,
            $staffIds,
            $assetIds,
        ): void {
            $visibility->whereHas('assignments', function (Builder $assignment) use (
                $siteIds,
                $roomIds,
                $clientIds,
                $staffIds,
                $assetIds,
            ): void {
                $assignment->active()->where(function (Builder $target) use (
                    $siteIds,
                    $roomIds,
                    $clientIds,
                    $staffIds,
                    $assetIds,
                ): void {
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_SITE, $siteIds, false);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_ROOM, $roomIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_CLIENT, $clientIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_STAFF, $staffIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_VEHICLE, $assetIds);
                });
            });

            if ($assetIds !== []) {
                $visibility->orWhereHas('activeAssetLinks', fn (Builder $link): Builder => $link->whereIn('asset_id', $assetIds));
            }

            if ($this->canViewUnassigned($user)) {
                $visibility->orWhere(function (Builder $stock): void {
                    $stock->whereDoesntHave('assignments', fn (Builder $assignment) => $assignment->active())
                        ->whereDoesntHave('activeAssetLinks');
                });
            }
        });

        // One visible assignment must not mask any other inaccessible active
        // provenance. Mixed Site, Room, private-person, or Asset context fails
        // closed until every active target is authorized.
        $this->excludeUnauthorizedAssignments($query, DeviceAssignment::TARGET_SITE, $siteIds);
        $this->excludeUnauthorizedAssignments($query, DeviceAssignment::TARGET_ROOM, $roomIds);
        $this->excludeUnauthorizedAssignments($query, DeviceAssignment::TARGET_CLIENT, $clientIds);
        $this->excludeUnauthorizedAssignments($query, DeviceAssignment::TARGET_STAFF, $staffIds);
        $this->excludeUnauthorizedAssignments($query, DeviceAssignment::TARGET_VEHICLE, $assetIds);
        if ($assetIds === []) {
            $query->whereDoesntHave('activeAssetLinks');
        } else {
            $query->whereDoesntHave(
                'activeAssetLinks',
                fn (Builder $link): Builder => $link->whereNotIn('asset_id', $assetIds),
            );
        }

        return $query;
    }

    /**
     * Narrow the canonical visible register to every active provenance path
     * owned by one operational Site. The base visibility query remains the
     * privacy and direct-object boundary; this method only narrows it further.
     */
    public function visibleDevicesForSite(User $user, int $siteId): Builder
    {
        $query = $this->visibleDevices($user);
        if (! $this->siteIsAccessible($user, $siteId)) {
            return $query->whereRaw('1 = 0');
        }

        $roomIds = SiteRoom::query()
            ->where('site_id', $siteId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $clientIds = Client::query()
            ->where('site_id', $siteId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $staffIds = $this->applyCurrentStaffSiteScope(HrEmployeeProfile::query(), [$siteId])
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $assetIds = $this->applyAssetSiteScope(Asset::query(), [$siteId])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $query->where(function (Builder $siteScope) use (
            $siteId,
            $roomIds,
            $clientIds,
            $staffIds,
            $assetIds,
        ): void {
            $siteScope->whereHas('assignments', function (Builder $assignment) use (
                $siteId,
                $roomIds,
                $clientIds,
                $staffIds,
                $assetIds,
            ): void {
                $assignment->active()->where(function (Builder $target) use (
                    $siteId,
                    $roomIds,
                    $clientIds,
                    $staffIds,
                    $assetIds,
                ): void {
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_SITE, [$siteId], false);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_ROOM, $roomIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_CLIENT, $clientIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_STAFF, $staffIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_VEHICLE, $assetIds);
                });
            });

            if ($assetIds !== []) {
                $siteScope->orWhereHas(
                    'activeAssetLinks',
                    fn (Builder $link): Builder => $link->whereIn('asset_id', $assetIds),
                );
            }
        });
    }

    /**
     * Count active canonical Devices for a bounded set of Assets without
     * exposing Device existence outside the viewer's Security & Devices scope.
     *
     * @param  Collection<int, int|string>  $assetIds
     * @return Collection<int, int>
     */
    public function visibleActiveDeviceCountsForAssets(User $user, Collection $assetIds): Collection
    {
        $ids = $assetIds
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if (! $user->canDo('securityDevices.devices.view') || $ids->isEmpty()) {
            return collect();
        }

        return DeviceAssetLink::query()
            ->active()
            ->whereIn('asset_id', $ids)
            ->whereIn('device_id', $this->visibleDevices($user)->select('devices.id'))
            ->selectRaw('asset_id, COUNT(DISTINCT device_id) AS aggregate')
            ->groupBy('asset_id')
            ->pluck('aggregate', 'asset_id')
            ->map(fn (mixed $count): int => (int) $count);
    }

    public function releasableDevices(User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        $roomIds = $siteIds === []
            ? []
            : SiteRoom::query()->whereIn('site_id', $siteIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $assetIds = array_values(array_unique([
            ...$this->authorizedAssetIds($user),
            ...$this->accessibleHistoricalLinkedAssetIds($user),
        ]));
        $assetTargets = $assetIds === []
            ? collect()
            : Asset::query()->whereKey($assetIds)->get(['client_id', 'primary_driver_user_id']);
        $clientIds = array_values(array_unique([
            ...$this->authorizedClientIds($user),
            ...$this->accessibleHistoricalAssignedClientIds($user),
            ...$assetTargets->pluck('client_id')->filter()->map(fn (mixed $id): int => (int) $id)->all(),
        ]));
        $staffIds = array_values(array_unique([
            (int) $user->getKey(),
            ...$this->accessibleAssignedStaffIds($user),
            ...$assetTargets->pluck('primary_driver_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all(),
        ]));

        $query = Device::query()->where(function (Builder $visibility) use (
            $siteIds,
            $roomIds,
            $clientIds,
            $staffIds,
            $assetIds,
        ): void {
            $visibility->whereHas('assignments', function (Builder $assignment) use (
                $siteIds,
                $roomIds,
                $clientIds,
                $staffIds,
                $assetIds,
            ): void {
                $assignment->active()->where(function (Builder $target) use (
                    $siteIds,
                    $roomIds,
                    $clientIds,
                    $staffIds,
                    $assetIds,
                ): void {
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_SITE, $siteIds, false);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_ROOM, $roomIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_CLIENT, $clientIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_STAFF, $staffIds);
                    $this->addAssignmentTargetBranch($target, DeviceAssignment::TARGET_VEHICLE, $assetIds);
                });
            });

            if ($assetIds !== []) {
                $visibility->orWhereHas('activeAssetLinks', fn (Builder $link): Builder => $link->whereIn('asset_id', $assetIds));
            }
        });

        foreach ([
            DeviceAssignment::TARGET_SITE => $siteIds,
            DeviceAssignment::TARGET_ROOM => $roomIds,
            DeviceAssignment::TARGET_CLIENT => $clientIds,
            DeviceAssignment::TARGET_STAFF => $staffIds,
            DeviceAssignment::TARGET_VEHICLE => $assetIds,
        ] as $targetType => $authorizedIds) {
            $this->excludeUnauthorizedAssignments($query, $targetType, $authorizedIds);
        }
        if ($assetIds === []) {
            $query->whereDoesntHave('activeAssetLinks');
        } else {
            $query->whereDoesntHave(
                'activeAssetLinks',
                fn (Builder $link): Builder => $link->whereNotIn('asset_id', $assetIds),
            );
        }

        return $query;
    }

    /** @return list<int> */
    private function accessibleAssignedStaffIds(User $user): array
    {
        if (! $user->canDo('hazards.view') || ! $user->canDo('staff.viewAny')) {
            return [];
        }

        $candidateIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->distinct()
            ->pluck('assignable_id');
        if ($candidateIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereKey($candidateIds)
            ->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', fn (Builder $profile): Builder => $this->applyCurrentStaffSiteScope(
                $profile,
                $this->accessibleSiteIds($user),
            ))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function accessibleAssetIds(User $user): array
    {
        $candidateIds = DeviceAssetLink::query()
            ->active()
            ->pluck('asset_id')
            ->merge(DeviceAssignment::query()
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
                ->pluck('assignable_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        if ($candidateIds->isEmpty()) {
            return [];
        }

        return $this->applyAssetSiteScope(Asset::query()->whereKey($candidateIds), $this->accessibleSiteIds($user))
            ->with('categoryRef:id,slug')
            ->get()
            ->filter(function (Asset $asset) use ($user): bool {
                $isVehicle = strcasecmp((string) $asset->category, 'vehicle') === 0
                    || $asset->categoryRef?->slug === 'vehicle';

                return ($isVehicle && $user->canDo('fleet.viewAny'))
                    || Gate::forUser($user)->allows('view', $asset);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function accessibleAssignedClientIds(User $user): array
    {
        $candidateIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->distinct()
            ->pluck('assignable_id');
        if ($candidateIds->isEmpty()) {
            return [];
        }

        return $this->assignableClientQuery($user)
            ->whereKey($candidateIds)
            ->get()
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function accessibleHistoricalAssignedClientIds(User $user): array
    {
        $candidateIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->distinct()
            ->pluck('assignable_id');
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $siteIds = $this->accessibleSiteIds($user);
        if ($siteIds === []) {
            return [];
        }

        return Client::withTrashed()
            ->whereKey($candidateIds)
            ->whereNotNull('site_id')
            ->whereIn('site_id', $siteIds)
            ->whereHas('site', fn (Builder $site): Builder => $this->applyOperationalSiteScope($site))
            ->get()
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function accessibleHistoricalLinkedAssetIds(User $user): array
    {
        $candidateIds = DeviceAssetLink::query()
            ->active()
            ->distinct()
            ->pluck('asset_id');
        if ($candidateIds->isEmpty()) {
            return [];
        }

        $siteIds = $this->accessibleSiteIds($user);
        if ($siteIds === []) {
            return [];
        }

        $assets = Asset::query()
            ->whereKey($candidateIds)
            ->whereNotNull('site_id')
            ->whereIn('site_id', $siteIds)
            ->whereHas('site', fn (Builder $site): Builder => $this->applyOperationalSiteScope($site))
            ->get();
        $historicalClientSiteIds = Client::withTrashed()
            ->whereKey($assets->pluck('client_id')->filter()->unique())
            ->pluck('site_id', 'id');

        return $assets
            ->filter(function (Asset $asset) use ($historicalClientSiteIds, $user): bool {
                $historicalSiteId = $asset->client_id
                    ? $historicalClientSiteIds->get($asset->client_id)
                    : $asset->site_id;

                return (int) $historicalSiteId === (int) $asset->site_id
                    && Gate::forUser($user)->allows('view', $asset);
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function assertCanViewDevice(User $user, Device $device): void
    {
        abort_unless($this->visibleDevices($user)->whereKey($device->getKey())->exists(), 404);
    }

    public function assertCanAssignTarget(User $user, Device $device, string $targetType, int $targetId): void
    {
        abort_unless($this->canAccessAssignmentTarget($user, $device, $targetType, $targetId), 404);
    }

    /** @return Collection<int, DeviceAssignment> */
    public function assertCanManageActiveAssignment(User $user, Device $device, bool $lockForUpdate = false): Collection
    {
        $query = $device->assignments()->active();
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $assignments = $query->get();
        foreach ($assignments as $assignment) {
            abort_unless($this->canAccessAssignmentTarget(
                $user,
                $device,
                $assignment->assignable_type,
                (int) $assignment->assignable_id,
            ), 404);
        }

        return $assignments;
    }

    /** @return Collection<int, DeviceAssignment> */
    public function assertCanReleaseActiveAssignment(User $user, Device $device, bool $lockForUpdate = false): Collection
    {
        $query = $device->assignments()->active();
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $assignments = $query->get();
        foreach ($assignments as $assignment) {
            abort_unless($this->canAccessHistoricalAssignmentTarget(
                $user,
                $assignment->assignable_type,
                (int) $assignment->assignable_id,
            ), 404);
        }

        return $assignments;
    }

    public function canAccessAssignmentTarget(User $user, Device $device, string $targetType, int $targetId): bool
    {
        return match ($targetType) {
            DeviceAssignment::TARGET_SITE => $this->siteIsAccessible($user, $targetId),
            DeviceAssignment::TARGET_ROOM => SiteRoom::query()
                ->whereKey($targetId)
                ->whereIn('site_id', $this->accessibleSiteIds($user))
                ->whereHas('site', fn (Builder $site): Builder => $this->applyOperationalSiteScope($site))
                ->exists(),
            DeviceAssignment::TARGET_CLIENT => $this->assignableClient($user, $targetId) !== null,
            DeviceAssignment::TARGET_STAFF => $this->assignableStaffMember($user, $targetId) !== null,
            DeviceAssignment::TARGET_VEHICLE => $this->canUseAsset($user, $targetId, true),
            default => false,
        };
    }

    public function assertCanUseAsset(User $user, Device $device, int $assetId): void
    {
        abort_unless($this->canUseAsset($user, $assetId), 404);
    }

    private function canUseAsset(User $user, int $assetId, bool $vehicleOnly = false): bool
    {
        $asset = $this->assetCandidateQuery($user, $vehicleOnly)->whereKey($assetId)->first();
        if (! $asset) {
            return false;
        }

        return ($vehicleOnly && $user->canDo('fleet.viewAny'))
            || Gate::forUser($user)->allows('view', $asset);
    }

    private function assignableClientQuery(User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);

        return Client::query()
            ->whereNotNull('site_id')
            ->when($siteIds === [], fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn (Builder $query): Builder => $query->whereIn('site_id', $siteIds))
            ->whereHas('site', fn (Builder $site): Builder => $this->applyOperationalSiteScope($site));
    }

    private function assetCandidateQuery(User $user, bool $vehicleOnly = false): Builder
    {
        $query = $this->applyAssetSiteScope(Asset::query(), $this->accessibleSiteIds($user));

        return $vehicleOnly ? $query->vehicles() : $query;
    }

    private function canAccessHistoricalAssignmentTarget(User $user, string $targetType, int $targetId): bool
    {
        return match ($targetType) {
            DeviceAssignment::TARGET_SITE => $this->siteIsAccessible($user, $targetId),
            DeviceAssignment::TARGET_ROOM => SiteRoom::query()
                ->whereKey($targetId)
                ->whereIn('site_id', $this->accessibleSiteIds($user))
                ->whereHas('site', fn (Builder $site): Builder => $this->applyOperationalSiteScope($site))
                ->exists(),
            DeviceAssignment::TARGET_CLIENT => (function () use ($user, $targetId): bool {
                $client = Client::withTrashed()->whereKey($targetId)->first();

                return ($client instanceof Client
                    && is_numeric($client->site_id)
                    && $this->siteIsAccessible($user, (int) $client->site_id)
                    && Gate::forUser($user)->allows('view', $client))
                    || Asset::query()
                        ->whereKey($this->authorizedAssetIds($user))
                        ->where('client_id', $targetId)
                        ->exists();
            })(),
            DeviceAssignment::TARGET_STAFF => $this->assignableStaffMember($user, $targetId) !== null
                || Asset::query()
                    ->whereKey($this->authorizedAssetIds($user))
                    ->where('primary_driver_user_id', $targetId)
                    ->exists(),
            DeviceAssignment::TARGET_VEHICLE => $this->canUseAsset($user, $targetId, true),
            default => false,
        };
    }

    public function assertCanViewSite(User $user, int $siteId): void
    {
        abort_unless($this->siteIsAccessible($user, $siteId), 404);
    }

    private function siteIsAccessible(User $user, int $siteId): bool
    {
        return in_array($siteId, $this->accessibleSiteIds($user), true)
            && $this->operationalSites()->whereKey($siteId)->exists();
    }

    private function operationalSites(): Builder
    {
        return $this->applyOperationalSiteScope(Site::query());
    }

    private function applyOperationalSiteScope(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at');
    }

    /** @param list<int> $siteIds */
    private function applyCurrentStaffSiteScope(Builder $query, array $siteIds): Builder
    {
        $query
            ->where('is_active', true)
            ->where(function (Builder $dates): void {
                $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today());
            })
            ->where(function (Builder $dates): void {
                $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today());
            });

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sites) use ($siteIds): void {
            $sites->whereIn('primary_site_id', $siteIds);
            foreach ($siteIds as $siteId) {
                $sites->orWhereJsonContains('secondary_site_ids', $siteId);
            }
        });
    }

    /** @param list<int> $siteIds */
    private function applyAssetSiteScope(Builder $query, array $siteIds): Builder
    {
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $siteColumn = $query->qualifyColumn('site_id');
        $homeSiteColumn = $query->qualifyColumn('home_site_id');
        $clientColumn = $query->qualifyColumn('client_id');

        return $query->where(function (Builder $provenance) use (
            $siteIds,
            $siteColumn,
            $homeSiteColumn,
            $clientColumn,
        ): void {
            $provenance->where(function (Builder $directSite) use (
                $siteIds,
                $siteColumn,
                $clientColumn,
            ): void {
                $directSite->whereIn($siteColumn, $siteIds)
                    ->whereHas('site', fn (Builder $canonical): Builder => $this->applyOperationalSiteScope($canonical))
                    ->where(function (Builder $clientAgreement) use ($siteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client): Builder => $client->whereColumn(
                                $client->qualifyColumn('site_id'),
                                $siteColumn,
                            ));
                    });
            })->orWhere(function (Builder $homeSite) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
                $clientColumn,
            ): void {
                $homeSite->whereNull($siteColumn)
                    ->whereIn($homeSiteColumn, $siteIds)
                    ->whereHas('homeSite', fn (Builder $canonical): Builder => $this->applyOperationalSiteScope($canonical))
                    ->where(function (Builder $clientAgreement) use ($homeSiteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client): Builder => $client->whereColumn(
                                $client->qualifyColumn('site_id'),
                                $homeSiteColumn,
                            ));
                    });
            })->orWhere(function (Builder $clientSite) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
                $clientColumn,
            ): void {
                $clientSite->whereNull($siteColumn)
                    ->whereNull($homeSiteColumn)
                    ->whereNotNull($clientColumn)
                    ->whereHas('client', fn (Builder $client): Builder => $client
                        ->whereIn($client->qualifyColumn('site_id'), $siteIds)
                        ->whereHas('site', fn (Builder $canonical): Builder => $this->applyOperationalSiteScope($canonical)));
            });
        });
    }

    /**
     * Mirror AssetPolicy's assigned-only support-worker boundary in SQL so
     * pagination, exports and aggregate counts cannot enumerate Site inventory
     * that the same actor cannot open directly.
     */
    private function applyAssignedAssetPolicyScope(Builder $query, User $user): Builder
    {
        if (! $user->canDo('assets.viewAssigned') || ! $user->hasRole('support_worker')) {
            return $query->whereRaw('1 = 0');
        }

        $assignedClientIds = Client::query()
            ->select('clients.id')
            ->whereHas('supportWorkers', fn (Builder $workers): Builder => $workers->whereKey($user->id));
        $assignedClientSiteIds = Client::query()
            ->select('clients.site_id')
            ->whereNotNull('clients.site_id')
            ->whereHas('supportWorkers', fn (Builder $workers): Builder => $workers->whereKey($user->id));

        return $query->where(function (Builder $assigned) use ($assignedClientIds, $assignedClientSiteIds): void {
            $assigned->whereIn($assigned->qualifyColumn('client_id'), $assignedClientIds)
                ->orWhereIn($assigned->qualifyColumn('site_id'), $assignedClientSiteIds);
        });
    }

    /** @param list<int> $ids */
    private function addAssignmentTargetBranch(Builder $target, string $type, array $ids, bool $or = true): void
    {
        if ($ids === []) {
            if (! $or) {
                $target->whereRaw('1 = 0');
            }

            return;
        }

        $method = $or ? 'orWhere' : 'where';
        $target->{$method}(fn (Builder $branch): Builder => $branch
            ->where('assignable_type', $type)
            ->whereIn('assignable_id', $ids));
    }

    /** @param list<int> $authorizedIds */
    private function excludeUnauthorizedAssignments(Builder $query, string $type, array $authorizedIds): void
    {
        $query->whereDoesntHave('assignments', function (Builder $assignment) use ($type, $authorizedIds): void {
            $assignment->active()->where('assignable_type', $type);
            if ($authorizedIds !== []) {
                $assignment->whereNotIn('assignable_id', $authorizedIds);
            }
        });
    }
}
