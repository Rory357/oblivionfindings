<?php

namespace App\Domain\SecurityDevices\Policies;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;

class DevicePolicy
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.view')
            && $this->access->visibleDevices($user)->whereKey($device->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.create');
    }

    public function update(User $user, Device $device): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.update')
            && $this->access->visibleDevices($user)->whereKey($device->id)->exists();
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.delete')
            && $this->access->visibleDevices($user)->whereKey($device->id)->exists();
    }

    public function assign(User $user, Device $device): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.devices.assign')
            && $this->access->visibleDevices($user)->whereKey($device->id)->exists();
    }

    public function manageGroups(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.groups.manage')
            && $this->access->canViewAllSites($user);
    }

    public function viewEvents(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.events.view');
    }

    public function viewMaintenance(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.maintenance.view');
    }

    public function manageMaintenance(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.maintenance.manage');
    }

    public function viewReports(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.reports.view');
    }

    public function viewIntegrations(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.integrations.view');
    }

    public function manageIntegrations(User $user): bool
    {
        return $this->canAccessModule($user)
            && $user->canDo('securityDevices.integrations.manage');
    }

    private function canAccessModule(User $user): bool
    {
        return $user->isApproved()
            && $user->canDo('securityDevices.viewAny');
    }
}
