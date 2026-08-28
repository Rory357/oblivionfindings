<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serializes authorization decisions with direct-override, role-assignment,
 * and role-permission writers by locking the exact RBAC evidence used by
 * User::canDo(). Callers must acquire their governing application mutex first.
 */
class AuthorizationEvidenceLockService
{
    /**
     * @param  array<int, string>  $permissionKeys
     */
    public function lockForUser(User|int $user, array $permissionKeys): User
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        /** @var User $lockedUser */
        $lockedUser = $this->lockForUsers([$userId], $permissionKeys)->get($userId);

        return $lockedUser;
    }

    /**
     * Lock and hydrate current authorization evidence for several users in one
     * deterministic order. This is required for two-person workflows: taking
     * actor and witness mutexes one at a time can deadlock when two commands
     * present the same pair in the opposite order.
     *
     * @param  iterable<int, User|int>  $users
     * @param  array<int, string>  $permissionKeys
     * @param  array<int, int|string>  $additionalRoleIds  Roles the same command will mutate.
     * @return Collection<int, User>
     */
    public function lockForUsers(
        iterable $users,
        array $permissionKeys,
        array $additionalRoleIds = [],
    ): Collection {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Authorization evidence must be locked inside a transaction.');
        }

        $userIds = collect($users)
            ->map(fn (User|int $user): int => $user instanceof User ? (int) $user->id : (int) $user)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->sort()
            ->values();
        if ($userIds->isEmpty()) {
            throw new \LogicException('At least one authorization subject is required.');
        }

        // User is the durable mutex shared with every live RBAC writer. Take
        // every mutex in ascending primary-key order before touching any pivot
        // or Role row so actor/witness input order cannot invert the graph.
        $lockedUsers = User::query()
            ->whereIn('id', $userIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (User $lockedUser): int => (int) $lockedUser->id);
        abort_unless($lockedUsers->count() === $userIds->count(), 404);

        // Permission definitions are an application catalogue; every mutable
        // assignment/override query below is a current locking read. Do not
        // eager-load these relations afterwards: under MySQL REPEATABLE READ a
        // later ordinary read could resurrect evidence deleted by a writer
        // while this command waited for the mutex.
        $permissionQuery = Permission::query();
        if (! in_array('*', $permissionKeys, true)) {
            $permissionQuery->whereIn('key', array_values(array_unique($permissionKeys)));
        }
        $permissions = $permissionQuery
            ->orderBy('id')
            ->get(['id', 'key'])
            ->keyBy(fn (Permission $permission): int => (int) $permission->id);
        $permissionIds = $permissions->keys()->all();

        $overrideRows = collect();
        if ($permissionIds !== []) {
            // The composite primary key is permission_id,user_id. Supplying
            // both columns locks existing overrides and the exact insert gaps
            // a concurrent allow/deny writer would otherwise use.
            $overrideRows = DB::table('permission_user')
                ->whereIn('permission_id', $permissionIds)
                ->whereIn('user_id', $userIds->all())
                ->orderBy('permission_id')
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get(['permission_id', 'user_id', 'allowed']);
        }

        $roleAssignments = DB::table('role_user')
            ->whereIn('user_id', $userIds->all())
            ->orderBy('role_id')
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get(['role_id', 'user_id']);
        $roleIds = $roleAssignments
            ->pluck('role_id')
            ->map(fn ($id): int => (int) $id)
            ->merge(collect($additionalRoleIds)->map(fn ($id): int => (int) $id))
            ->filter(fn (int $roleId): bool => $roleId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $roles = collect();
        $rolePermissionRows = collect();
        if ($roleIds !== []) {
            $roles = Role::query()
                ->whereIn('id', $roleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'name'])
                ->keyBy(fn (Role $role): int => (int) $role->id);
            abort_unless($roles->count() === count($roleIds), 404);

            if ($permissionIds !== []) {
                $rolePermissionRows = DB::table('role_permission')
                    ->whereIn('role_id', $roleIds)
                    ->whereIn('permission_id', $permissionIds)
                    ->orderBy('role_id')
                    ->orderBy('permission_id')
                    ->lockForUpdate()
                    ->get(['role_id', 'permission_id']);
            }
        }

        $roles->each(function (Role $role) use ($rolePermissionRows, $permissions): void {
            $rolePermissionIds = $rolePermissionRows
                ->where('role_id', $role->id)
                ->pluck('permission_id')
                ->map(fn ($permissionId): int => (int) $permissionId);
            $role->setRelation(
                'permissions',
                $permissions->only($rolePermissionIds->all())->values(),
            );
        });

        $lockedUsers->each(function (User $lockedUser) use (
            $overrideRows,
            $permissions,
            $roleAssignments,
            $roles,
        ): void {
            $overrides = $overrideRows
                ->where('user_id', $lockedUser->id)
                ->map(function (object $row) use ($permissions): Permission {
                    /** @var Permission $permission */
                    $permission = clone $permissions->get((int) $row->permission_id);
                    $pivot = new Pivot;
                    $pivot->setTable('permission_user');
                    $pivot->forceFill([
                        'permission_id' => (int) $row->permission_id,
                        'user_id' => (int) $row->user_id,
                        'allowed' => (bool) $row->allowed,
                    ]);
                    $permission->setRelation('pivot', $pivot);

                    return $permission;
                })
                ->values();
            $assignedRoleIds = $roleAssignments
                ->where('user_id', $lockedUser->id)
                ->pluck('role_id')
                ->map(fn ($roleId): int => (int) $roleId)
                ->all();

            $lockedUser->unsetRelations();
            $lockedUser->setRelation('permissionOverrides', $overrides);
            $lockedUser->setRelation('roles', $roles->only($assignedRoleIds)->values());
        });

        return $lockedUsers;
    }

    /** Acquire the durable per-user authorization mutex used by RBAC writers. */
    public function lockUserMutex(User|int $user): User
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Authorization mutexes must be locked inside a transaction.');
        }

        $userId = $user instanceof User ? (int) $user->id : $user;
        $lockedUser = User::query()->whereKey($userId)->lockForUpdate()->first();
        abort_unless($lockedUser, 404);

        return $lockedUser;
    }

    /** Acquire the durable per-role mutex before changing role permissions. */
    public function lockRoleMutex(Role|int $role): Role
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Authorization mutexes must be locked inside a transaction.');
        }

        $roleId = $role instanceof Role ? (int) $role->id : $role;
        $lockedRole = Role::query()->whereKey($roleId)->lockForUpdate()->first();
        abort_unless($lockedRole, 404);

        return $lockedRole;
    }
}
