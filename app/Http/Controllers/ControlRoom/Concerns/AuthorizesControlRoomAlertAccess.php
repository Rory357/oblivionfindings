<?php

namespace App\Http\Controllers\ControlRoom\Concerns;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

trait AuthorizesControlRoomAlertAccess
{
    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanAccessAlert(User $user, ControlRoomAlert $alert): void
    {
        app(ControlRoomAlertAccessService::class)->assertCanView(
            $alert,
            $user,
        );
    }

    protected function accessibleStaffQuery(User $user): Builder
    {
        $query = User::query()->staff();

        $this->siteAccess()->applyStaffScope(
            $query,
            $user,
            $this->alertBypassPermissions(),
        );

        return $query;
    }

    protected function assertCanAccessStaff(
        User $user,
        int $staffUserId,
        string $message = 'You are not authorized to access that staff member.',
    ): User {
        $staff = $this->accessibleStaffQuery($user)
            ->whereKey($staffUserId)
            ->first();

        abort_unless($staff, 403, $message);

        return $staff;
    }
}
