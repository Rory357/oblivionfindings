<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Governance\Models\BoardMember;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->mapWithKeys(fn ($p) => [$p->id => (bool) $p->pivot->allowed])
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

        $actorId = (int) $user->id;
        $targetId = (int) $target->id;
        $roleIds = $this->normalizedRoleIds($data);
        DB::transaction(function () use ($actorId, $data, $roleIds, $targetId): void {
            [$lockedActor, $lockedTarget] = $this->lockAccessMutationUsers($actorId, $targetId, $roleIds);
            $this->applyAccessChanges($lockedTarget, $data);

            AuditLogger::log('rbac.roles.updated', $lockedTarget, [
                'roles' => $data['role_ids'] ?? [],
                'changed_by' => $lockedActor->id,
            ]);

            if (! empty($data['overrides'] ?? [])) {
                AuditLogger::log('rbac.permission.override', $lockedTarget, [
                    'overrides' => $data['overrides'],
                    'changed_by' => $lockedActor->id,
                ]);
            }
        });

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

        $actorId = (int) $user->id;
        $targetId = (int) $target->id;
        $roleIds = $this->normalizedRoleIds($data);
        DB::transaction(function () use ($actorId, $data, $roleIds, $targetId): void {
            [$lockedActor, $lockedTarget] = $this->lockAccessMutationUsers($actorId, $targetId, $roleIds);
            $this->applyAccessChanges($lockedTarget, $data);

            $lockedTarget->forceFill([
                'approved_at' => $lockedTarget->approved_at ?? now(),
                'approved_by' => $lockedTarget->approved_by ?? $lockedActor->id,
            ])->save();

            AuditLogger::log('rbac.user.approved', $lockedTarget, [
                'approved_by' => $lockedActor->id,
                'roles_assigned' => $data['role_ids'] ?? [],
            ]);
        });

        return redirect()->back()->with('success', 'User approved.');
    }

    private function applyAccessChanges(User $target, array $data): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Access changes must hold the authorization mutex.');
        }

        // Roles
        $roleIds = $this->normalizedRoleIds($data);
        $target->roles()->sync($roleIds);

        // Keep legacy users.role in sync (React UI still references auth.user.role)
        $primaryRoleName = null;
        if (! empty($roleIds)) {
            $primaryRoleName = Role::query()
                ->whereIn('id', $roleIds)
                ->orderBy('id')
                ->lockForUpdate()
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

        $actorId = (int) $user->id;
        $targetId = (int) $data['user_id'];
        $roleMap = $this->boardRoleMap();
        $boardRoleNames = array_values(array_unique($roleMap));
        $selectedRoleName = $roleMap[$data['board_role']] ?? 'board_member';
        $selectedRoleId = (int) Role::query()->where('name', $selectedRoleName)->value('id');
        abort_unless($selectedRoleId > 0, 404);
        DB::transaction(function () use (
            $actorId,
            $boardRoleNames,
            $data,
            $selectedRoleId,
            $selectedRoleName,
            $targetId,
        ): void {
            [, $targetUser] = $this->lockAccessMutationUsers($actorId, $targetId, [$selectedRoleId]);
            $lockedSelectedRole = Role::query()
                ->whereKey($selectedRoleId)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $lockedSelectedRole instanceof Role
                    && (string) $lockedSelectedRole->name === $selectedRoleName,
                409,
                'The requested board role changed. Please retry.',
            );
            $currentBoardRoleIds = $targetUser->roles
                ->filter(fn (Role $role): bool => in_array((string) $role->name, $boardRoleNames, true))
                ->pluck('id')
                ->map(fn ($roleId): int => (int) $roleId)
                ->all();
            $existing = BoardMember::withTrashed()
                ->where('user_id', $targetId)
                ->lockForUpdate()
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
                    'user_id' => $targetId,
                    'board_role' => $data['board_role'],
                    'term_start' => $data['term_start'],
                    'term_end' => $data['term_end'] ?? null,
                    'is_active' => true,
                    'is_independent' => true,
                ]);
            }

            $targetUser->roles()->detach($currentBoardRoleIds);
            $targetUser->roles()->syncWithoutDetaching([$selectedRoleId]);
        });

        return redirect()->back()->with('success', 'Board member appointed.');
    }

    public function destroyBoardMember(Request $request, BoardMember $boardMember)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $actorId = (int) $user->id;
        $targetId = (int) $boardMember->user_id;
        $boardMemberId = (int) $boardMember->id;
        $boardRoleNames = array_values(array_unique($this->boardRoleMap()));
        DB::transaction(function () use ($actorId, $boardMemberId, $boardRoleNames, $targetId): void {
            [, $targetUser] = $this->lockAccessMutationUsers($actorId, $targetId, []);
            $currentBoardRoleIds = $targetUser->roles
                ->filter(fn (Role $role): bool => in_array((string) $role->name, $boardRoleNames, true))
                ->pluck('id')
                ->map(fn ($roleId): int => (int) $roleId)
                ->all();
            $lockedBoardMember = BoardMember::withTrashed()
                ->whereKey($boardMemberId)
                ->where('user_id', $targetId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedBoardMember->update(['is_active' => false]);
            $lockedBoardMember->delete();
            $targetUser->roles()->detach($currentBoardRoleIds);
        });

        return redirect()->back()->with('success', 'Board member removed.');
    }

    /** @return array<string, string> */
    private function boardRoleMap(): array
    {
        return [
            'chair' => 'board_chair',
            'secretary' => 'board_secretary',
            'treasurer' => 'board_member', // Treasurer uses board_member role
            'member' => 'board_member',
            'observer' => 'board_observer',
        ];
    }

    /** @return list<int> */
    private function normalizedRoleIds(array $data): array
    {
        return collect($data['role_ids'] ?? [])
            ->map(fn ($roleId): int => (int) $roleId)
            ->filter(fn (int $roleId): bool => $roleId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $additionalRoleIds
     * @return array{0: User, 1: User}
     */
    private function lockAccessMutationUsers(
        int $actorId,
        int $targetId,
        array $additionalRoleIds,
    ): array {
        $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
            [$actorId, $targetId],
            ['settings.access.manage'],
            $additionalRoleIds,
        );
        /** @var User|null $actor */
        $actor = $lockedUsers->get($actorId);
        /** @var User|null $target */
        $target = $lockedUsers->get($targetId);
        abort_unless($actor?->canDo('settings.access.manage'), 403);
        abort_unless($target, 404);

        return [$actor, $target];
    }
}
