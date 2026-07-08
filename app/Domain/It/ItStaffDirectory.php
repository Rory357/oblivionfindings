<?php

namespace App\Domain\It;

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
     * Tenant users holding a permission (role grant or allow-override,
     * minus deny-overrides).
     *
     * @return Collection<int, User>
     */
    public static function holdingPermission(int $tenantId, string $permissionKey): Collection
    {
        return User::query()
            // Users are tenanted by organization_id; a NULL means the default
            // tenant on this single-tenant install — don't silently drop them.
            ->where(fn ($q) => $q->where('organization_id', $tenantId)->orWhereNull('organization_id'))
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
    public static function agents(int $tenantId): Collection
    {
        return static::holdingPermission($tenantId, 'it.manage');
    }

    /**
     * Escalation of last resort: tenant admins (role, not permission —
     * the unassigned-urgent alarm goes over the queue's head by design).
     *
     * @return Collection<int, User>
     */
    public static function admins(int $tenantId): Collection
    {
        return User::query()
            ->where(fn ($q) => $q->where('organization_id', $tenantId)->orWhereNull('organization_id'))
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();
    }
}
