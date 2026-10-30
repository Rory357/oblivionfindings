<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkforceMutationGuard
{
    public function lock(): void
    {
        // Use the same first lock as Shift lifecycle, attendance and payroll.
        if (! DB::table('hr_payroll_run_mutexes')->where('key', 'application')->lockForUpdate()->first()) {
            throw new \LogicException('The application payroll mutex is missing; migration repair is required.');
        }
    }

    public function actor(User $actor, string $permission, array $participantIds = [], array $additionalPermissions = []): User
    {
        return $this->lockParticipants($actor, $permission, $participantIds, $additionalPermissions)->get($actor->id);
    }

    /** @return Collection<int, User> Current locked User/RBAC/Profile evidence for the complete decision. */
    public function lockParticipants(User $actor, string $permission, array $participantIds = [], array $additionalPermissions = []): Collection
    {
        $ids = collect([$actor->id, ...$participantIds])->filter()->unique()->sort()->values()->all();
        $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($ids, [
            'shifts.create', 'shifts.update', 'shifts.manageAny', 'reports.viewAny', ...$additionalPermissions,
        ]);
        $profiles = HrEmployeeProfile::query()->whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
        foreach ($users as $user) {
            $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
        }
        $actor = $users->get($actor->id);
        // Middleware may have passed before this command waited for its locks.
        // Check the current account approval alongside the locked exact grant.
        abort_unless($actor?->isApproved() && $actor->canDo($permission), 403);

        return $users;
    }
}
