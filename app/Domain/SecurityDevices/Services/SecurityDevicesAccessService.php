<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
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

    public function visibleDevices(User $user): Builder
    {
        $tenantId = $this->tenantId($user);
        $query = Device::query()->forTenant($tenantId);
        $clientIds = $this->accessibleAssignedClientIds($user);

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

        return $query->where(function (Builder $visibility) use ($user, $siteIds, $roomIds, $clientIds): void {
            $visibility->whereHas('assignments', function (Builder $assignment) use ($user, $siteIds, $roomIds, $clientIds): void {
                $assignment->active()->where(function (Builder $target) use ($user, $siteIds, $roomIds, $clientIds): void {
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

                    if ($clientIds !== []) {
                        $target->orWhere(function (Builder $clientTarget) use ($clientIds): void {
                            $clientTarget
                                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                                ->whereIn('assignable_id', $clientIds);
                        });
                    }
                });
            });

            if ($user->canDo('securityDevices.devices.assign')
                || $user->canDo('securityDevices.devices.update')) {
                $visibility->orWhereDoesntHave('assignments', fn (Builder $assignment) => $assignment->active());
            }
        });
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

    public function assertCanViewSite(User $user, int $siteId): void
    {
        abort_unless(in_array($siteId, $this->accessibleSiteIds($user), true), 404);
    }
}
