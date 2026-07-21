<?php

namespace App\Domain\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who staffs the IT helpdesk. One home for the recipient queries the
 * controllers and the SLA scheduler share, so "every it.manage agent"
 * means the same thing everywhere. Mirrors the proven
 * HrNotificationService::getUsersWithPermission query shape.
 */
class ItStaffDirectory
{
    /**
     * Current staff accounts holding a permission (role grant or
     * allow-override, minus deny-overrides).
     *
     * @return Collection<int, User>
     */
    public static function holdingPermission(string $permissionKey): Collection
    {
        return User::query()
            ->whereNotNull('approved_at')
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['client', 'next_of_kin']))
            ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', ['client', 'next_of_kin']))
            ->where(function ($query) use ($permissionKey) {
                $query->whereHas('roles.permissions', fn ($q) => $q->where('key', $permissionKey))
                    ->orWhereHas('permissionOverrides', fn ($q) => $q
                        ->where('permissions.key', $permissionKey)
                        ->where('permission_user.allowed', true));
            })
            ->whereDoesntHave('permissionOverrides', fn ($q) => $q
                ->where('permissions.key', $permissionKey)
                ->where('permission_user.allowed', false))
            ->distinct()
            ->get();
    }

    /**
     * The queue-alert audience: everyone with it.manage.
     *
     * @return Collection<int, User>
     */
    public static function agents(): Collection
    {
        return static::holdingPermission('it.manage');
    }

    /** @return Collection<int, User> */
    public static function agentsForTicket(ItTicket $ticket): Collection
    {
        $access = app(ItWorkAccessService::class);

        return static::agents()
            ->filter(fn (User $agent): bool => $access->canWork($agent, $ticket))
            ->values();
    }

    /** @return Collection<int, User> */
    public static function agentsForSharedSites(User $viewer): Collection
    {
        if ($viewer->canDo('it.organisationWide')) {
            return static::agents();
        }

        $access = app(ItWorkAccessService::class);
        $viewerSiteIds = $access->approvedSiteIds($viewer);

        return static::agents()
            ->filter(fn (User $agent): bool => array_intersect(
                $viewerSiteIds,
                $access->approvedSiteIds($agent),
            ) !== [])
            ->values();
    }

    /**
     * Escalation of last resort: current admins (role, not permission —
     * the unassigned-urgent alarm goes over the queue's head by design).
     *
     * @return Collection<int, User>
     */
    public static function admins(): Collection
    {
        return User::query()
            ->whereNotNull('approved_at')
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();
    }
}
