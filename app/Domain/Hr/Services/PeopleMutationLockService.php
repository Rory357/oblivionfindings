<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
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
    /**
     * @param  iterable<int>  $userIds
     * @param  iterable<int>  $profileIds
     * @return array{users: Collection<int, User>, profiles: Collection<int, HrEmployeeProfile>}
     */
    public function lock(iterable $userIds, iterable $profileIds = []): array
    {
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

        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

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

        return ['users' => $users, 'profiles' => $profiles];
    }
}
