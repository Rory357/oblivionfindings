<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

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

        if ($this->canViewAllTenantSites($user)) {
            return $query;
        }

        $siteIds = $this->accessibleSiteIds($user);
        $roomIds = $siteIds === []
            ? collect()
            : SiteRoom::query()->whereIn('site_id', $siteIds)->pluck('id');

        return $query->where(function (Builder $visibility) use ($user, $siteIds, $roomIds): void {
            $visibility->whereHas('assignments', function (Builder $assignment) use ($user, $siteIds, $roomIds): void {
                $assignment->active()->where(function (Builder $target) use ($user, $siteIds, $roomIds): void {
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
                });
            });

            if ($user->canDo('securityDevices.devices.assign')
                || $user->canDo('securityDevices.devices.update')) {
                $visibility->orWhereDoesntHave('assignments', fn (Builder $assignment) => $assignment->active());
            }
        });
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
