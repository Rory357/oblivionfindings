<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            ->map(fn($role) => [
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
            ->map(fn($role) => [
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
            ->map(fn($role) => [
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

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'level' => $data['level'],
            'type' => 'custom',
        ]);

        if (!empty($data['permission_keys'])) {
            $permissionIds = Permission::whereIn('key', $data['permission_keys'])->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

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

        // Only allow label/description update for custom roles
        if ($role->isCustom()) {
            $role->update([
                'label' => $data['label'] ?? $role->label,
                'description' => $data['description'] ?? $role->description,
            ]);
        }

        if (isset($data['permission_keys'])) {
            $permissionIds = Permission::whereIn('key', $data['permission_keys'])->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

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

        $newRole = Role::create([
            'name' => $data['name'],
            'label' => $data['label'],
            'description' => $role->description,
            'level' => $role->level,
            'type' => 'custom',
        ]);

        // Copy permissions
        $newRole->permissions()->sync($role->permissions->pluck('id'));

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

        $role->delete();

        return redirect()->back()->with('success', 'Role deleted successfully.');
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
            ->map(fn($items) => $items->pluck('permission_id')->toArray())
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
            ->map(fn($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->map(fn($r) => [
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

        $target->roles()->sync($data['role_ids']);

        // Update legacy role field
        $primaryRole = $target->roles()->orderByDesc('level')->first();
        $target->forceFill(['role' => $primaryRole?->name ?? 'support_worker'])->save();

        return redirect()->back()->with('success', 'User roles updated successfully.');
    }
}
