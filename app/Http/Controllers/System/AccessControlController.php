<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AccessControlController extends Controller
{
    /**
     * Access Control Dashboard
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $stats = [
            'total_roles' => Role::count(),
            'system_roles' => Role::where('type', 'system')->count(),
            'custom_roles' => Role::where('type', 'custom')->count(),
            'permission_groups' => Permission::distinct('group')->count(),
            'total_permissions' => Permission::count(),
            'active_users' => User::whereNotNull('approved_at')->count(),
            'pending_invitations' => UserInvitation::where('status', 'pending')->count(),
        ];

        $roles = Role::withCount('users')
            ->byLevel()
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'level' => $role->level,
                'level_display' => $role->level_display,
                'type' => $role->type,
                'description' => $role->description,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions()->count(),
            ]);

        return Inertia::render('system/access/Dashboard', [
            'stats' => $stats,
            'roles' => $roles,
        ]);
    }

    /**
     * Roles Management Page
     */
    public function roles(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $systemRoles = Role::system()
            ->byLevel()
            ->withCount(['users', 'permissions'])
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'level' => $role->level,
                'level_display' => $role->level_display,
                'type' => $role->type,
                'description' => $role->description,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ]);

        $customRoles = Role::custom()
            ->byLevel()
            ->withCount(['users', 'permissions'])
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'level' => $role->level,
                'level_display' => $role->level_display,
                'type' => $role->type,
                'description' => $role->description,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'permission_keys' => $role->permissions->pluck('key')->values(),
            ]);

        $permissions = Permission::orderBy('group')->orderBy('key')->get(['id', 'key', 'description', 'group', 'module']);

        return Inertia::render('system/access/Roles', [
            'systemRoles' => $systemRoles,
            'customRoles' => $customRoles,
            'permissions' => $permissions,
            'permissionGroups' => Permission::groups(),
        ]);
    }

    /**
     * Store new custom role
     */
    public function storeRole(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'min:1', 'max:100'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ], [
            'name.regex' => 'Role key must be lowercase letters, numbers, or underscores.',
        ]);

        $permissionIds = Permission::whereIn('key', $data['permission_keys'] ?? [])->pluck('id')->all();
        $actorId = (int) $user->id;
        DB::transaction(function () use ($actorId, $data, $permissionIds): void {
            $this->lockAccessActor($actorId);
            $role = Role::query()->create([
                'name' => $data['name'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'level' => $data['level'],
                'type' => 'custom',
            ]);
            $role->permissions()->sync($permissionIds);
        });

        return redirect()->back()->with('success', 'Role created successfully.');
    }

    /**
     * Update role permissions
     */
    public function updateRole(Request $request, Role $role)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        // Cannot modify system role core attributes, only permissions
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'permission_keys' => ['array'],
            'permission_keys.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $permissionIds = isset($data['permission_keys'])
            ? Permission::whereIn('key', $data['permission_keys'])->pluck('id')->all()
            : null;
        $actorId = (int) $user->id;
        $roleId = (int) $role->id;
        DB::transaction(function () use ($actorId, $data, $permissionIds, $roleId): void {
            $this->lockAccessActor($actorId, [$roleId]);
            $lockedRole = app(AuthorizationEvidenceLockService::class)->lockRoleMutex($roleId);

            // Only allow label/description update for custom roles
            if ($lockedRole->isCustom()) {
                $lockedRole->update([
                    'label' => $data['label'] ?? $lockedRole->label,
                    'description' => $data['description'] ?? $lockedRole->description,
                ]);
            }

            if ($permissionIds !== null) {
                $lockedRole->permissions()->sync($permissionIds);
            }
        });

        return redirect()->back()->with('success', 'Role updated successfully.');
    }

    /**
     * Clone a role
     */
    public function cloneRole(Request $request, Role $role)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:80'],
        ]);

        $actorId = (int) $user->id;
        $roleId = (int) $role->id;
        DB::transaction(function () use ($actorId, $data, $roleId): void {
            $this->lockAccessActor($actorId, [$roleId]);
            $sourceRole = app(AuthorizationEvidenceLockService::class)->lockRoleMutex($roleId);
            $permissionIds = DB::table('role_permission')
                ->where('role_id', $sourceRole->id)
                ->orderBy('permission_id')
                ->lockForUpdate()
                ->pluck('permission_id')
                ->all();
            $newRole = Role::query()->create([
                'name' => $data['name'],
                'label' => $data['label'],
                'description' => $sourceRole->description,
                'level' => $sourceRole->level,
                'type' => 'custom',
            ]);
            $newRole->permissions()->sync($permissionIds);
        });

        return redirect()->back()->with('success', 'Role cloned successfully.');
    }

    /**
     * Delete custom role
     */
    public function destroyRole(Request $request, Role $role)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);
        abort_if($role->isSystem(), 403, 'System roles cannot be deleted.');

        // Physical deletion cascades through role_user. Acquiring Role first can
        // therefore deadlock current-authorization readers that already hold an
        // assignee pivot and are waiting for the Role mutex. Safe role retirement
        // needs a separate lifecycle; this packet deliberately preserves custom
        // roles instead of performing an unsafe cascade.
        throw ValidationException::withMessages([
            'role' => 'Role deletion is unavailable. Remove its assignments and leave the role unassigned.',
        ]);
    }

    /**
     * Permissions Matrix Page
     */
    public function matrix(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $roles = Role::byLevel()->get(['id', 'name', 'label', 'level', 'type']);

        $permissions = Permission::orderBy('group')
            ->orderBy('key')
            ->get(['id', 'key', 'description', 'group']);

        // Get all role-permission mappings
        $rolePermissions = \DB::table('role_permission')
            ->select('role_id', 'permission_id')
            ->get()
            ->groupBy('role_id')
            ->map(fn ($items) => $items->pluck('permission_id')->toArray())
            ->toArray();

        return Inertia::render('system/access/Matrix', [
            'roles' => $roles,
            'permissions' => $permissions,
            'permissionGroups' => $permissions->groupBy('group')->keys(),
            'rolePermissions' => $rolePermissions,
        ]);
    }

    /**
     * User Assignments Page
     */
    public function assignments(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $users = User::with(['roles', 'staffProfile'])
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'label' => $r->label,
                    'level' => $r->level,
                ]),
                'primary_role' => $user->roles->sortByDesc('level')->first()?->label ?? 'No Role',
                'is_staff' => $user->staffProfile !== null,
                'created_at' => $user->created_at,
            ]);

        $roles = Role::byLevel()->get(['id', 'name', 'label', 'level']);

        return Inertia::render('system/access/Assignments', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    /**
     * Update user role assignments
     */
    public function updateAssignments(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $actorId = (int) $user->id;
        $targetId = (int) $target->id;
        $roleIds = collect($data['role_ids'])
            ->map(fn ($roleId): int => (int) $roleId)
            ->filter(fn (int $roleId): bool => $roleId > 0)
            ->unique()
            ->sort()
            ->values();
        DB::transaction(function () use ($actorId, $roleIds, $targetId): void {
            $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                [$actorId, $targetId],
                ['settings.access.manage'],
                $roleIds->all(),
            );
            /** @var User|null $lockedActor */
            $lockedActor = $lockedUsers->get($actorId);
            /** @var User|null $lockedTarget */
            $lockedTarget = $lockedUsers->get($targetId);
            abort_unless($lockedActor?->canDo('settings.access.manage'), 403);
            abort_unless($lockedTarget, 404);
            $lockedTarget->roles()->sync($roleIds->all());

            // Update legacy role field
            $primaryRole = Role::query()
                ->whereIn('id', $roleIds->all())
                ->orderByDesc('level')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $lockedTarget->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();
        });

        return redirect()->back()->with('success', 'User roles updated successfully.');
    }

    /** @param list<int> $additionalRoleIds */
    private function lockAccessActor(int $actorId, array $additionalRoleIds = []): User
    {
        $users = app(AuthorizationEvidenceLockService::class)->lockForUsers(
            [$actorId],
            ['settings.access.manage'],
            $additionalRoleIds,
        );
        /** @var User|null $actor */
        $actor = $users->get($actorId);
        abort_unless($actor?->canDo('settings.access.manage'), 403);

        return $actor;
    }
}
