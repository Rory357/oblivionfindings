<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class SecurityDevicesAccessService
{
    private const ASSIGNMENT_PICKER_LIMIT = 500;

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
        $tenantId = $this->tenantId($user);
        $query = User::query()
            ->where('organization_id', $tenantId)
            ->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', fn (Builder $profile): Builder => $profile
                ->where('tenant_id', $tenantId)
                ->whereNotNull('primary_site_id')
                ->whereHas('primarySite', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId)));

        if (! $user->canDo('staff.viewAny') || ! $user->canDo('hazards.view')) {
            return $query->whereKey($user->id);
        }

        $this->siteAccess->applyStaffScope($query, $user, ['securityDevices.integrations.manage']);

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

        return $clients;
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

    public function assignableVehicles(User $user, ?string $search = null, ?int $selectedId = null): Collection
    {
        $tenantId = $this->tenantId($user);
        $siteIds = $this->accessibleSiteIds($user);
        $search = trim((string) $search);
        $assets = $this->assetCandidateQuery($user, true)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $name) use ($search): void {
                $name->where('name', 'like', "%{$search}%")
                    ->orWhere('registration_number', 'like', "%{$search}%");
            }))
            ->with([
                'site:id,tenant_id',
                'homeSite:id,tenant_id',
                'client:id,organization_id,site_id',
                'client.site:id,tenant_id',
                'categoryRef:id,slug',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::ASSIGNMENT_PICKER_LIMIT)
            ->get()
            ->filter(function (Asset $asset) use ($user, $tenantId, $siteIds): bool {
                if (! $this->assetMatchesTenant($asset, $tenantId)) {
                    return false;
                }

                if (! $this->canViewAllTenantSites($user)) {
                    $assetSiteIds = array_filter([
                        $asset->site_id,
                        $asset->home_site_id,
                        $asset->client?->site_id,
                    ], fn ($id): bool => is_numeric($id));
                    if (array_intersect($siteIds, array_map('intval', $assetSiteIds)) === []) {
                        return false;
                    }
                }

                return $user->canDo('fleet.viewAny') || Gate::forUser($user)->allows('view', $asset);
            })
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
        $tenantId = $this->tenantId($user);
        $query = $this->assetCandidateQuery($user, true)
            ->whereKey($id)
            ->with([
                'site:id,tenant_id',
                'homeSite:id,tenant_id',
                'client:id,organization_id,site_id',
                'client.site:id,tenant_id',
                'categoryRef:id,slug',
            ]);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $asset = $query->first();

        if (! $asset instanceof Asset || ! $this->assetMatchesTenant($asset, $tenantId)) {
            return null;
        }

        return $user->canDo('fleet.viewAny') || Gate::forUser($user)->allows('view', $asset)
            ? $asset
            : null;
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
                'client:id,organization_id,site_id',
                'client.site:id,tenant_id',
                'categoryRef:id,slug',
            ])
            ->get()
            ->filter(function (Asset $asset) use ($user, $tenantId): bool {
                if (! $this->assetMatchesTenant($asset, $tenantId)) {
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
            ->with('site:id,tenant_id')
            ->get()
            ->filter(fn (Client $client): bool => $this->clientMatchesTenant($client, $this->tenantId($user))
                && Gate::forUser($user)->allows('view', $client))
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

    /**
     * Release is a historical cleanup operation. It proves the persisted
     * target belonged to this device tenant and remains inside the operator's
     * site authority without requiring the target to still be live/assignable.
     *
     * @return Collection<int, DeviceAssignment>
     */
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
                $device,
                $assignment->assignable_type,
                (int) $assignment->assignable_id,
            ), 404);
        }

        return $assignments;
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
            })
            ->whereHas('hrEmployeeProfile', fn (Builder $profile): Builder => $profile
                ->where('tenant_id', $tenantId)
                ->whereNotNull('primary_site_id')
                ->whereHas('primarySite', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId)));

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
        $client = $this->applyClientTenantPredicate(Client::query(), $tenantId)
            ->whereKey($clientId)
            ->whereNotNull('site_id')
            ->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
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
                    ->orWhereHas('client', fn (Builder $client): Builder => $this->applyClientTenantPredicate($client, $tenantId));
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

        $asset = $query->with([
            'site:id,tenant_id',
            'homeSite:id,tenant_id',
            'client:id,organization_id,site_id',
            'client.site:id,tenant_id',
        ])->first();
        if (! $asset || ! $this->assetMatchesTenant($asset, $tenantId)) {
            return false;
        }

        return ($vehicleOnly && $user->canDo('fleet.viewAny'))
            || Gate::forUser($user)->allows('view', $asset);
    }

    public function assetMatchesTenant(Asset $asset, int $tenantId): bool
    {
        $asset->loadMissing([
            'site:id,tenant_id',
            'homeSite:id,tenant_id',
            'client:id,organization_id,site_id',
            'client.site:id,tenant_id',
        ]);

        $hasTenantEvidence = false;
        foreach ([['site_id', 'site'], ['home_site_id', 'homeSite']] as [$siteKey, $relation]) {
            $siteId = $asset->getAttribute($siteKey);
            if ($siteId === null) {
                continue;
            }

            $hasTenantEvidence = true;
            if (! is_numeric($siteId) || (int) ($asset->{$relation}?->tenant_id ?? 0) !== $tenantId) {
                return false;
            }
        }

        if ($asset->client_id !== null) {
            $hasTenantEvidence = true;
            $client = $asset->client;
            if (! $client || ! $this->clientMatchesTenant($client, $tenantId)) {
                return false;
            }
        }

        return $hasTenantEvidence;
    }

    public function clientMatchesTenant(Client $client, int $tenantId): bool
    {
        $client->loadMissing('site:id,tenant_id');

        if (is_numeric($client->organization_id)) {
            return (int) $client->organization_id === $tenantId
                && ($client->site_id === null
                    || (is_numeric($client->site_id) && (int) ($client->site?->tenant_id ?? 0) === $tenantId));
        }

        return $tenantId === 1
            && is_numeric($client->site_id)
            && (int) ($client->site?->tenant_id ?? 0) === 1;
    }

    private function assignableClientQuery(User $user): Builder
    {
        $tenantId = $this->tenantId($user);
        $siteIds = $this->accessibleSiteIds($user);

        return $this->applyClientTenantPredicate(Client::query(), $tenantId)
            ->whereNotNull('site_id')
            ->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
            ->when(! $this->canViewAllTenantSites($user), fn (Builder $query): Builder => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $siteIds));
    }

    private function assetCandidateQuery(User $user, bool $vehicleOnly = false): Builder
    {
        $tenantId = $this->tenantId($user);
        $siteIds = $this->accessibleSiteIds($user);
        $query = Asset::query()
            ->where(function (Builder $tenant) use ($tenantId): void {
                $tenant->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
                    ->orWhereHas('homeSite', fn (Builder $site): Builder => $site->where('tenant_id', $tenantId))
                    ->orWhereHas('client', fn (Builder $client): Builder => $this->applyClientTenantPredicate($client, $tenantId));
            })
            ->when(! $this->canViewAllTenantSites($user), fn (Builder $query): Builder => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->where(function (Builder $site) use ($siteIds): void {
                    $site->whereIn('site_id', $siteIds)
                        ->orWhereIn('home_site_id', $siteIds)
                        ->orWhereHas('client', fn (Builder $client): Builder => $client->whereIn('site_id', $siteIds));
                }));

        return $vehicleOnly ? $query->vehicles() : $query;
    }

    private function applyClientTenantPredicate(Builder $query, int $tenantId): Builder
    {
        return $query->where(function (Builder $candidate) use ($tenantId): void {
            $candidate->where(function (Builder $owned) use ($tenantId): void {
                $owned->where('organization_id', $tenantId)
                    ->where(function (Builder $site) use ($tenantId): void {
                        $site->whereNull('site_id')
                            ->orWhereHas('site', fn (Builder $related): Builder => $related->where('tenant_id', $tenantId));
                    });
            });

            if ($tenantId === 1) {
                $candidate->orWhere(function (Builder $legacy): void {
                    $legacy->whereNull('organization_id')
                        ->whereNotNull('site_id')
                        ->whereHas('site', fn (Builder $site): Builder => $site->where('tenant_id', 1));
                });
            }
        });
    }

    public function assetMatchesTenantHistorically(Asset $asset, int $tenantId): bool
    {
        $hasTenantEvidence = false;
        foreach (['site_id', 'home_site_id'] as $siteKey) {
            $siteId = $asset->getAttribute($siteKey);
            if ($siteId === null) {
                continue;
            }

            $hasTenantEvidence = true;
            if (! is_numeric($siteId)
                || ! Site::query()->whereKey((int) $siteId)->where('tenant_id', $tenantId)->exists()) {
                return false;
            }
        }

        if ($asset->client_id !== null) {
            $hasTenantEvidence = true;
            $client = Client::withTrashed()->with('site:id,tenant_id')->find($asset->client_id);
            if (! $client || ! $this->clientMatchesTenant($client, $tenantId)) {
                return false;
            }
        }

        return $hasTenantEvidence;
    }

    private function canAccessHistoricalAssignmentTarget(User $user, Device $device, string $targetType, int $targetId): bool
    {
        $tenantId = (int) $device->tenant_id;
        $siteIds = $this->accessibleSiteIds($user);
        $isPlatformAdmin = $this->siteAccess->isUnrestrictedPlatformUser($user);
        $siteIsAuthorized = fn (int $siteId): bool => Site::query()
            ->whereKey($siteId)
            ->where('tenant_id', $tenantId)
            ->when(! $isPlatformAdmin, fn (Builder $site): Builder => $site->whereIn('id', $siteIds))
            ->exists();

        return match ($targetType) {
            DeviceAssignment::TARGET_SITE => $siteIsAuthorized($targetId),
            DeviceAssignment::TARGET_ROOM => SiteRoom::query()
                ->whereKey($targetId)
                ->where('tenant_id', $tenantId)
                ->whereHas('site', fn (Builder $site): Builder => $site
                    ->where('tenant_id', $tenantId)
                    ->when(! $isPlatformAdmin, fn (Builder $bounded): Builder => $bounded->whereIn('id', $siteIds)))
                ->exists(),
            DeviceAssignment::TARGET_CLIENT => (function () use ($targetId, $tenantId, $siteIsAuthorized): bool {
                $client = Client::withTrashed()->with('site:id,tenant_id')->find($targetId);

                return $client !== null
                    && is_numeric($client->site_id)
                    && $this->clientMatchesTenant($client, $tenantId)
                    && $siteIsAuthorized((int) $client->site_id);
            })(),
            DeviceAssignment::TARGET_STAFF => (function () use ($targetId, $tenantId, $siteIsAuthorized): bool {
                $staff = User::query()->whereKey($targetId)->first(['id', 'organization_id']);
                $profile = HrEmployeeProfile::withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $targetId)
                    ->first(['id', 'tenant_id', 'user_id', 'primary_site_id']);

                return $staff !== null
                    && (int) $staff->organization_id === $tenantId
                    && $profile !== null
                    && is_numeric($profile->primary_site_id)
                    && $siteIsAuthorized((int) $profile->primary_site_id);
            })(),
            DeviceAssignment::TARGET_VEHICLE => (function () use ($targetId, $tenantId, $siteIds, $isPlatformAdmin): bool {
                $asset = Asset::query()->whereKey($targetId)->first();
                if (! $asset || ! $this->assetMatchesTenantHistorically($asset, $tenantId)) {
                    return false;
                }

                if ($isPlatformAdmin) {
                    return true;
                }

                $assetSiteIds = collect([$asset->site_id, $asset->home_site_id])
                    ->filter(fn ($id): bool => is_numeric($id))
                    ->map(fn ($id): int => (int) $id);
                if ($asset->client_id !== null) {
                    $clientSiteId = Client::withTrashed()->whereKey($asset->client_id)->value('site_id');
                    if (is_numeric($clientSiteId)) {
                        $assetSiteIds->push((int) $clientSiteId);
                    }
                }

                return $assetSiteIds->intersect($siteIds)->isNotEmpty();
            })(),
            default => false,
        };
    }

    public function assertCanViewSite(User $user, int $siteId): void
    {
        abort_unless(in_array($siteId, $this->accessibleSiteIds($user), true), 404);
    }
}
