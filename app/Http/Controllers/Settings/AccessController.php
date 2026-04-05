<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $users = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'approved_at'])
            ->load(['roles:id,name,label', 'permissionOverrides:id,key']);

        $roles = Role::query()->orderBy('label')->get(['id', 'name', 'label']);
        $permissions = Permission::query()->orderBy('key')->get(['id', 'key', 'description']);

        // Permission overrides pivot values
        $userOverrides = [];
        foreach ($users as $u) {
            $pairs = $u->permissionOverrides()
                ->select('permissions.id', 'permissions.key', 'permission_user.allowed')
                ->get()
                ->mapWithKeys(fn($p) => [$p->id => (bool) $p->pivot->allowed])
                ->toArray();
            $userOverrides[$u->id] = $pairs;
        }

        // Board members
        $boardMembers = BoardMember::with('user:id,name,email')
            ->orderBy('board_role')
            ->get();

        return inertia('settings/access', [
            'users' => $users,
            'roles' => $roles,
            'permissions' => $permissions,
            'userOverrides' => $userOverrides,
            'boardMembers' => $boardMembers,
        ]);
    }

    public function update(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            // overrides: { permission_id: "inherit"|"allow"|"deny" }
            'overrides' => ['array'],
            'overrides.*' => ['in:inherit,allow,deny'],
        ]);

        // Roles
        $roleIds = $data['role_ids'] ?? [];
        $target->roles()->sync($roleIds);

        // Keep legacy users.role in sync (the React UI still references auth.user.role
        // and some screens list users.role).
        $primaryRoleName = null;
        if (!empty($roleIds)) {
            $primaryRoleName = Role::query()
                ->whereIn('id', $roleIds)
                ->orderBy('id')
                ->value('name');
        }
        $target->forceFill([
            'role' => $primaryRoleName ?? ($target->role ?? 'support_worker'),
        ])->save();

        \App\Services\AuditLogger::log('rbac.roles.updated', $target, [
            'roles' => $roleIds,
            'changed_by' => $request->user()->id,
        ]);

        // Overrides (grant/deny wins over role perms)
        $overrides = $data['overrides'] ?? [];
        foreach ($overrides as $permissionId => $mode) {
            if ($mode === 'inherit') {
                $target->permissionOverrides()->detach($permissionId);
                continue;
            }
            $allowed = $mode === 'allow';
            $pid = (int) $permissionId;
            // Ensure row exists, then update the pivot value.
            $target->permissionOverrides()->syncWithoutDetaching([$pid]);
            $target->permissionOverrides()->updateExistingPivot($pid, ['allowed' => $allowed]);
        }

        if (!empty($overrides)) {
            \App\Services\AuditLogger::log('rbac.permission.override', $target, [
                'overrides' => $overrides,
                'changed_by' => $request->user()->id,
            ]);
        }

        return redirect()->back()->with('success', 'Access updated.');
    }

    public function approve(Request $request, User $target)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            // overrides: { permission_id: "inherit"|"allow"|"deny" }
            'overrides' => ['array'],
            'overrides.*' => ['in:inherit,allow,deny'],
        ]);

        // Roles + overrides use the same logic as update.
        $this->applyAccessChanges($target, $data);

        $target->forceFill([
            'approved_at' => $target->approved_at ?? now(),
            'approved_by' => $target->approved_by ?? $user->id,
        ])->save();

        \App\Services\AuditLogger::log('rbac.user.approved', $target, [
            'approved_by' => $user->id,
            'roles_assigned' => $data['role_ids'] ?? [],
        ]);

        return redirect()->back()->with('success', 'User approved.');
    }

    private function applyAccessChanges(User $target, array $data): void
    {
        // Roles
        $roleIds = $data['role_ids'] ?? [];
        $target->roles()->sync($roleIds);

        // Keep legacy users.role in sync (React UI still references auth.user.role)
        $primaryRoleName = null;
        if (!empty($roleIds)) {
            $primaryRoleName = Role::query()
                ->whereIn('id', $roleIds)
                ->orderBy('id')
                ->value('name');
        }

        $target->forceFill([
            'role' => $primaryRoleName ?? ($target->role ?? 'support_worker'),
        ])->save();

        // Overrides
        $overrides = $data['overrides'] ?? [];
        foreach ($overrides as $permissionId => $mode) {
            if ($mode === 'inherit') {
                $target->permissionOverrides()->detach($permissionId);
                continue;
            }
            $allowed = $mode === 'allow';
            $pid = (int) $permissionId;
            $target->permissionOverrides()->syncWithoutDetaching([$pid]);
            $target->permissionOverrides()->updateExistingPivot($pid, ['allowed' => $allowed]);
        }
    }

    // Board Member Management
    public function storeBoardMember(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('board_members', 'user_id')->whereNull('deleted_at'),
            ],
            'board_role' => ['required', 'in:chair,secretary,treasurer,member,observer'],
            'term_start' => ['required', 'date'],
            'term_end' => ['nullable', 'date', 'after:term_start'],
        ]);

        $existing = BoardMember::withTrashed()
            ->where('user_id', $data['user_id'])
            ->first();

        if ($existing) {
            $existing->restore();
            $existing->update([
                'board_role' => $data['board_role'],
                'term_start' => $data['term_start'],
                'term_end' => $data['term_end'] ?? null,
                'is_active' => true,
                'is_independent' => true,
            ]);
        } else {
            BoardMember::create([
                'user_id' => $data['user_id'],
                'board_role' => $data['board_role'],
                'term_start' => $data['term_start'],
                'term_end' => $data['term_end'] ?? null,
                'is_active' => true,
                'is_independent' => true,
            ]);
        }

        // Auto-assign the corresponding system role for governance permissions
        $targetUser = User::find($data['user_id']);
        if ($targetUser) {
            $this->assignBoardRole($targetUser, $data['board_role']);
        }

        return redirect()->back()->with('success', 'Board member appointed.');
    }

    public function destroyBoardMember(Request $request, BoardMember $boardMember)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $targetUser = $boardMember->user;

        $boardMember->update(['is_active' => false]);
        $boardMember->delete();

        // Remove the board role from the user
        if ($targetUser) {
            $this->removeBoardRole($targetUser);
        }

        return redirect()->back()->with('success', 'Board member removed.');
    }

    /**
     * Map board_role to system role name and assign it to the user.
     */
    private function assignBoardRole(User $user, string $boardRole): void
    {
        $roleMap = [
            'chair' => 'board_chair',
            'secretary' => 'board_secretary',
            'treasurer' => 'board_member', // Treasurer uses board_member role
            'member' => 'board_member',
            'observer' => 'board_observer',
        ];

        $systemRoleName = $roleMap[$boardRole] ?? 'board_member';
        $role = Role::where('name', $systemRoleName)->first();

        if ($role) {
            // Remove any existing board roles first
            $this->removeBoardRole($user);

            // Add the new board role
            $user->roles()->attach($role->id);
        }
    }

    /**
     * Remove all board-related roles from a user.
     */
    private function removeBoardRole(User $user): void
    {
        $boardRoleNames = ['board_chair', 'board_secretary', 'board_member', 'board_observer'];
        $boardRoleIds = Role::whereIn('name', $boardRoleNames)->pluck('id');

        $user->roles()->detach($boardRoleIds);
    }
}
