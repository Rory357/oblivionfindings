<?php

namespace App\Domain\It\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ItRemoteSupportAccess
{
    // Workspace discovery only. Connection and execution need their own grants.
    public function canOpen(User $user): bool
    {
        return $user->isApproved()
            && $user->canDo('it.view')
            && $user->canDo('it.manage')
            && $user->canDo('securityDevices.viewAny')
            && $user->canDo('securityDevices.devices.view');
    }

    public function isComputer(Device $device): bool
    {
        return $device->domain === 'it_infrastructure'
            && in_array($device->category, ['endpoint', 'server'], true);
    }

    public function computers(User $user): Builder
    {
        abort_unless($this->canOpen($user), 403);

        return app(SecurityDevicesAccessService::class)->visibleDevices($user)
            ->where('domain', 'it_infrastructure')
            ->whereIn('category', ['endpoint', 'server']);
    }
}
