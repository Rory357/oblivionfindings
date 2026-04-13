<?php

namespace App\Domain\SecurityDevices\Policies;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('securityDevices.viewAny');
    }

    public function view(User $user, Device $device): bool
    {
        return $user->canDo('securityDevices.devices.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('securityDevices.devices.create');
    }

    public function update(User $user, Device $device): bool
    {
        return $user->canDo('securityDevices.devices.update');
    }

    public function delete(User $user, Device $device): bool
    {
        return $user->canDo('securityDevices.devices.delete');
    }

    public function assign(User $user, Device $device): bool
    {
        return $user->canDo('securityDevices.devices.assign');
    }

    public function manageGroups(User $user): bool
    {
        return $user->canDo('securityDevices.groups.manage');
    }

    public function viewEvents(User $user): bool
    {
        return $user->canDo('securityDevices.events.view');
    }

    public function viewMaintenance(User $user): bool
    {
        return $user->canDo('securityDevices.maintenance.view');
    }

    public function manageMaintenance(User $user): bool
    {
        return $user->canDo('securityDevices.maintenance.manage');
    }

    public function viewReports(User $user): bool
    {
        return $user->canDo('securityDevices.reports.view');
    }

    public function viewIntegrations(User $user): bool
    {
        return $user->canDo('securityDevices.integrations.view');
    }

    public function manageIntegrations(User $user): bool
    {
        return $user->canDo('securityDevices.integrations.manage');
    }
}
