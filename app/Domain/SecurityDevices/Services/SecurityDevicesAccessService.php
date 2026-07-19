<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class SecurityDevicesAccessService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function tenantId(User $user): int
    {
        return (int) ($user->organization_id ?? 1);
    }

    /** @return array<int, int> */
    public function accessibleSiteIds(User $user): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $user,
            ['securityDevices.integrations.manage'],
        );
    }

    public function canViewAllTenantSites(User $user): bool
    {
        return $user->canDo('securityDevices.integrations.manage');
    }

    public function assignableStaff(User $user): Builder
    {
        $query = User::query()
            ->where('organization_id', $this->tenantId($user))
            ->whereNotNull('approved_at');

        if (! $user->canDo('staff.viewAny') || ! $user->canDo('hazards.view')) {
            return $query->whereKey($user->id);
        }

        $this->siteAccess->applyStaffScope($query, $user, ['securityDevices.integrations.manage']);

        return $query;
    }

    public function visibleDevices(User $user): Builder
    {
        $tenantId = $this->tenantId($user);
        $query = Device::query()->forTenant($tenantId);
        $clientIds = $this->accessibleAssignedClientIds($user);
        $staffIds = $this->accessibleAssignedStaffIds($user);
        $assetIds = $this->accessibleAssetIds($user);

        if ($this->canViewAllTenantSites($user)) {
            return $query->where(function (Builder $visibility) use ($clientIds): void {
                $visibility->whereDoesntHave('assignments', fn (Builder $assignment) => $assignment
                    ->active()
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT));

                if ($clientIds !== []) {
                    $visibility->orWhereHas('assignments', fn (Builder $assignment) => $assignment
                        ->active()
                        ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                        ->whereIn('assignable_id', $clientIds));
                }
            });
        }

        $siteIds = $this->accessibleSiteIds($user);
        $roomIds = $siteIds === []
            ? collect()
            : SiteRoom::query()->whereIn('site_id', $siteIds)->pluck('id');

        return $query->where(function (Builder $visibility) use ($user, $siteIds, $roomIds, $clientIds, $staffIds, $assetIds): void {
            $visibility->whereHas('assignments', function (Builder $assignment) use ($user, $siteIds, $roomIds, $clientIds, $staffIds, $assetIds): void {
                $assignment->active()->where(function (Builder $target) use ($user, $siteIds, $roomIds, $clientIds, $staffIds, $assetIds): void {
                    if ($siteIds !== []) {
                        $target->where(function (Builder $siteTarget) use ($siteIds): void {
                            $siteTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                                ->whereIn('assignable_id', $siteIds);
                        });
                    } else {
                        $target->whereRaw('1 = 0');
                    }

                    if ($roomIds->isNotEmpty()) {
                        $target->orWhere(function (Builder $roomTarget) use ($roomIds): void {
                            $roomTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                                ->whereIn('assignable_id', $roomIds);
                        });
                    }

                    $target->orWhere(function (Builder $staffTarget) use ($user): void {
                        $staffTarget
                            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                            ->where('assignable_id', $user->id);
                    });

                    if ($staffIds !== []) {
                        $target->orWhere(function (Builder $staffTarget) use ($staffIds): void {
                            $staffTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
                                ->whereIn('assignable_id', $staffIds);
                        });
                    }

                    if ($clientIds !== []) {
                        $target->orWhere(function (Builder $clientTarget) use ($clientIds): void {
                            $clientTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                                ->whereIn('assignable_id', $clientIds);
                        });
                    }

                    if ($assetIds !== []) {
                        $target->orWhere(function (Builder $vehicleTarget) use ($assetIds): void {
                            $vehicleTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
                                ->whereIn('assignable_id', $assetIds);
                        });
                    }
                });
            });

            if ($assetIds !== []) {
                $visibility->orWhereHas('activeAssetLinks', fn (Builder $link) => $link
                    ->whereIn('asset_id', $assetIds));
            }

            if ($user->canDo('securityDevices.devices.assign')
                || $user->canDo('securityDevices.devices.update')) {
                $visibility->orWhereDoesntHave('assignments', fn (Builder $assignment) => $assignment->active());
            }
        });
    }

    /**
     * Staff tracking remains a projection of the H&S staff boundary. A device
     * permission by itself never expands the visible staff population.
     *
     * @return array<int, int>
     */
    private function accessibleAssignedStaffIds(User $user): array
    {
        if (! $user->canDo('hazards.view') || ! $user->canDo('staff.viewAny')) {
            return [];
        }

        $candidateIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->whereHas('device', fn (Builder $device) => $device->forTenant($this->tenantId($user)))
            ->distinct()
            ->pluck('assignable_id');

        if ($candidateIds->isEmpty()) {
            return [];
        }

        $query = User::query()
            ->whereKey($candidateIds)
            ->whereNotNull('approved_at');
        $this->siteAccess->applyStaffScope(
            $query,
            $user,
            ['healthSafety.viewAllSites'],
        );

        return $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Resolve Fleet/Asset targets through their canonical destination policy
     * and tenant provenance. A device link by itself never grants access to an
     * otherwise foreign asset.
     *
     * @return array<int, int>
     */
    public function accessibleAssetIds(User $user): array
    {
        $tenantId = $this->tenantId($user);
        $linkedIds = DeviceAssetLink::query()
            ->active()
            ->whereHas('device', fn (Builder $device) => $device->forTenant($tenantId))
            ->pluck('asset_id');
        $assignedVehicleIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
            ->whereHas('device', fn (Builder $device) => $device->forTenant($tenantId))
            ->pluck('assignable_id');
        $candidateIds = $linkedIds
            ->merge($assignedVehicleIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return [];
        }

        return Asset::query()
            ->whereKey($candidateIds)
            ->with([
                'site:id,tenant_id',
                'homeSite:id,tenant_id',
                'client:id,organization_id',
                'categoryRef:id,slug',
            ])
            ->get()
            ->filter(function (Asset $asset) use ($user, $tenantId): bool {
                $sameTenant = (int) ($asset->site?->tenant_id ?? 0) === $tenantId
                    || (int) ($asset->homeSite?->tenant_id ?? 0) === $tenantId
                    || (int) ($asset->client?->organization_id ?? 0) === $tenantId;

                if (! $sameTenant) {
                    return false;
                }

                $isVehicle = strcasecmp((string) $asset->category, 'vehicle') === 0
                    || $asset->categoryRef?->slug === 'vehicle';

                return ($isVehicle && $user->canDo('fleet.viewAny'))
                    || Gate::forUser($user)->allows('view', $asset);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Resolve client targets through the canonical per-client policy. Starting
     * from client assignments in this device tenant keeps the candidate set
     * bounded and ensures Security & Devices never invents a broader client
     * access rule of its own.
     *
     * @return array<int, int>
     */
    private function accessibleAssignedClientIds(User $user): array
    {
        $candidateIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereHas('device', fn (Builder $device) => $device->forTenant($this->tenantId($user)))
            ->distinct()
            ->pluck('assignable_id');

        if ($candidateIds->isEmpty()) {
            return [];
        }

        return Client::query()
            ->whereKey($candidateIds)
            ->get()
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function assertCanViewDevice(User $user, Device $device): void
    {
        abort_unless(
            $this->visibleDevices($user)->whereKey($device->getKey())->exists(),
            404,
        );
    }

    public function assertCanAssignTarget(User $user, Device $device, string $targetType, int $targetId): void
    {
        abort_unless($this->canAccessAssignmentTarget($user, $device, $targetType, $targetId), 404);
    }

    public function assertCanManageActiveAssignment(User $user, Device $device, bool $lockForUpdate = false): void
    {
        $query = $device->assignments()->active();
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $assignment = $query->first();
        if (! $assignment) {
            return;
        }

        abort_unless($this->canAccessAssignmentTarget(
            $user,
            $device,
            $assignment->assignable_type,
            (int) $assignment->assignable_id,
        ), 404);
    }

    public function canAccessAssignmentTarget(User $user, Device $device, string $targetType, int $targetId): bool
    {
        $siteIds = $this->accessibleSiteIds($user);
        $isPlatformAdmin = $this->siteAccess->isUnrestrictedPlatformUser($user);
        $tenantId = (int) $device->tenant_id;

        return match ($targetType) {
            DeviceAssignment::TARGET_SITE => Site::query()
                ->whereKey($targetId)
                ->where('tenant_id', $tenantId)
                ->when(! $isPlatformAdmin, fn (Builder $query): Builder => $query->whereIn('id', $siteIds))
                ->exists(),
            DeviceAssignment::TARGET_ROOM => SiteRoom::query()
                ->whereKey($targetId)
                ->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
                ->when(! $isPlatformAdmin, fn (Builder $query): Builder => $query->whereIn('site_id', $siteIds))
                ->exists(),
            DeviceAssignment::TARGET_CLIENT => $this->canAssignClient(
                $user,
                $tenantId,
                $targetId,
                $siteIds,
                $isPlatformAdmin,
            ),
            DeviceAssignment::TARGET_STAFF => (
                $targetId === (int) $user->id
                && (int) ($user->organization_id ?? 1) === $tenantId
            ) || $this->canAssignStaff($user, $tenantId, $targetId),
            DeviceAssignment::TARGET_VEHICLE => $this->canUseAsset($user, $device, $targetId, true),
            default => false,
        };
    }

    public function assertCanUseAsset(User $user, Device $device, int $assetId): void
    {
        abort_unless($this->canUseAsset($user, $device, $assetId), 404);
    }

    private function canAssignStaff(User $user, int $tenantId, int $staffId): bool
    {
        if (! $user->canDo('staff.viewAny') || ! $user->canDo('hazards.view')) {
            return false;
        }

        $isPlatformAdmin = $this->siteAccess->isUnrestrictedPlatformUser($user);
        $query = User::query()
            ->whereKey($staffId)
            ->whereNotNull('approved_at')
            ->where(function (Builder $organization) use ($isPlatformAdmin, $tenantId): void {
                $organization->where('organization_id', $tenantId);
                if ($isPlatformAdmin && $tenantId === 1) {
                    $organization->orWhereNull('organization_id');
                }
            });

        $this->siteAccess->applyStaffScope($query, $user, ['securityDevices.integrations.manage']);

        return $query->exists();
    }

    /** @param array<int, int> $siteIds */
    private function canAssignClient(
        User $user,
        int $tenantId,
        int $clientId,
        array $siteIds,
        bool $isPlatformAdmin,
    ): bool {
        $client = Client::query()
            ->whereKey($clientId)
            ->where(function (Builder $organization) use ($isPlatformAdmin, $tenantId): void {
                $organization->where('organization_id', $tenantId);
                if ($isPlatformAdmin && $tenantId === 1) {
                    $organization->orWhereNull('organization_id');
                }
            })
            ->when(! $isPlatformAdmin, fn (Builder $query): Builder => $query->whereIn('site_id', $siteIds))
            ->first();

        return $client !== null && Gate::forUser($user)->allows('view', $client);
    }

    private function canUseAsset(User $user, Device $device, int $assetId, bool $vehicleOnly = false): bool
    {
        $siteIds = $this->accessibleSiteIds($user);
        $tenantId = (int) $device->tenant_id;
        $query = Asset::query()
            ->whereKey($assetId)
            ->where(function (Builder $tenant) use ($tenantId): void {
                $tenant->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
                    ->orWhereHas('homeSite', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
                    ->orWhereHas('client', fn (Builder $client): Builder => $client->where('organization_id', $tenantId));
            });

        if (! $this->siteAccess->isUnrestrictedPlatformUser($user)) {
            if ($siteIds === []) {
                return false;
            }

            $query->where(function (Builder $site) use ($siteIds): void {
                $site->whereIn('site_id', $siteIds)
                    ->orWhereIn('home_site_id', $siteIds)
                    ->orWhereHas('client', fn (Builder $client): Builder => $client->whereIn('site_id', $siteIds));
            });
        }

        if ($vehicleOnly) {
            $query->vehicles();
        }

        $asset = $query->first();
        if (! $asset) {
            return false;
        }

        return ($vehicleOnly && $user->canDo('fleet.viewAny'))
            || Gate::forUser($user)->allows('view', $asset);
    }

    public function assertCanViewSite(User $user, int $siteId): void
    {
        abort_unless(in_array($siteId, $this->accessibleSiteIds($user), true), 404);
    }
}
