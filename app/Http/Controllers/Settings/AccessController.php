<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $users = User::query()
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

        return inertia('settings/access', [
            'users' => $users,
            'roles' => $roles,
            'permissions' => $permissions,
            'userOverrides' => $userOverrides,
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
}
