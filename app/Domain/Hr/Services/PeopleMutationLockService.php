<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Collection;

/**
 * Acquires the shared lock prefix for every People mutation.
 *
 * All affected Users are locked by ascending ID before any employee Profile,
 * then all affected Profiles are locked by ascending ID. Callers may only
 * lock Site/department/offer destinations after this graph is acquired.
 */
class PeopleMutationLockService
{
    private ?AuthorizationEvidenceLockService $authorizationEvidence = null;

    public function __construct(
        ?AuthorizationEvidenceLockService $authorizationEvidence = null,
    ) {
        $this->authorizationEvidence = $authorizationEvidence;
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  iterable<int>  $profileIds
     * @param  iterable<int>  $additionalRoleIds
     * @return array{users: Collection<int, User>, profiles: Collection<int, HrEmployeeProfile>}
     */
    public function lock(
        iterable $userIds,
        iterable $profileIds = [],
        iterable $additionalRoleIds = [],
    ): array {
        $userIds = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $profileIds = collect($profileIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        $users = ($this->authorizationEvidence ?? app(AuthorizationEvidenceLockService::class))->lockForUsers(
            $userIds,
            ['*'],
            collect($additionalRoleIds)
                ->map(fn ($roleId): int => (int) $roleId)
                ->filter(fn (int $roleId): bool => $roleId > 0)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        );

        $profiles = HrEmployeeProfile::withTrashed()
            ->where(function ($query) use ($userIds, $profileIds): void {
                $query->whereIn('user_id', $userIds);
                if ($profileIds->isNotEmpty()) {
                    $query->orWhereIn('id', $profileIds);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $profilesByUser = $profiles->keyBy(fn (HrEmployeeProfile $profile): int => (int) $profile->user_id);
        $users->each(function (User $user) use ($profilesByUser): void {
            $profile = $profilesByUser->get((int) $user->id);
            $user->setRelation(
                'hrEmployeeProfile',
                $profile && ! $profile->trashed() ? $profile : null,
            );
        });

        return ['users' => $users, 'profiles' => $profiles];
    }
}
