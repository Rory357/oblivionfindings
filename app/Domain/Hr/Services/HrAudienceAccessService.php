<?php

namespace App\Domain\Hr\Services;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves explicitly supported HR audiences against canonical current staff.
 * Invalid, conflicting, missing or retired targeting evidence fails closed.
 */
class HrAudienceAccessService
{
    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /**
     * @param  array<int, array{type?: mixed, value?: mixed}>  $targets
     * @return Collection<int, User>
     */
    public function resolveUsers(array $targets, ?int $excludeUserId = null): Collection
    {
        $targets = $this->normaliseTargets($targets);
        if ($targets === null || $targets === []) {
            return collect();
        }

        $allTargets = array_filter($targets, fn (array $target) => $target['type'] === 'all');
        if ($allTargets !== []) {
            if (count($targets) !== 1) {
                return collect();
            }

            return $this->finishQuery($this->currentStaff->currentUsersQuery(), $excludeUserId);
        }

        foreach ($targets as $target) {
            if ($target['type'] === 'site' && ! $this->isCurrentSiteId($target['value'])) {
                return collect();
            }
        }

        $query = $this->currentStaff->currentUsersQuery();
        $query->where(function (Builder $audience) use ($targets): void {
            foreach ($targets as $target) {
                $type = $target['type'];
                $value = $target['value'];

                match ($type) {
                    'user' => $audience->orWhere($audience->qualifyColumn('id'), (int) $value),
                    'site' => $audience->orWhereHas('hrEmployeeProfile', function (Builder $profile) use ($value): void {
                        $siteId = (int) $value;
                        $profile->where(function (Builder $sites) use ($siteId): void {
                            $sites->where('primary_site_id', $siteId)
                                ->orWhereJsonContains('secondary_site_ids', $siteId);
                        });
                    }),
                    'department' => $audience->orWhereHas('hrEmployeeProfile', function (Builder $profile) use ($value): void {
                        ctype_digit($value)
                            ? $profile->where('department_id', (int) $value)
                            : $profile->where('department', $value);
                    }),
                    'role' => $audience->orWhere(function (Builder $roles) use ($value): void {
                        $roles->where('role', $value)
                            ->orWhereHas('roles', fn (Builder $role) => $role->where('name', $value))
                            ->orWhereHas('hrEmployeeProfile', fn (Builder $profile) => $profile->where('position_role', $value));
                    }),
                    'team' => $audience->orWhereHas(
                        'hrEmployeeProfile',
                        fn (Builder $profile) => $profile->where('team', $value),
                    ),
                };
            }
        });

        return $this->finishQuery($query, $excludeUserId);
    }

    public function canManageOwnedAudience(User $actor, ?int $creatorUserId, ?int $ownerUserId): bool
    {
        return $this->currentStaff->isCurrent($actor)
            && in_array((int) $actor->getKey(), array_filter([
                $creatorUserId ? (int) $creatorUserId : null,
                $ownerUserId ? (int) $ownerUserId : null,
            ]), true);
    }

    /**
     * @param  array<int, mixed>  $targets
     * @return array<int, array{type: string, value: string}>|null
     */
    private function normaliseTargets(array $targets): ?array
    {
        $supported = ['all', 'user', 'site', 'department', 'role', 'team'];
        $normalised = [];

        foreach ($targets as $target) {
            if (! is_array($target)) {
                return null;
            }

            $type = strtolower(trim((string) ($target['type'] ?? '')));
            $type = in_array($type, ['person', 'people'], true) ? 'user' : $type;
            $value = trim((string) ($target['value'] ?? ''));

            if (! in_array($type, $supported, true)) {
                return null;
            }

            if ($type === 'all') {
                if ($value !== '') {
                    return null;
                }
            } elseif ($value === '') {
                return null;
            }

            if (in_array($type, ['user', 'site'], true) && (! ctype_digit($value) || (int) $value < 1)) {
                return null;
            }

            $normalised[] = ['type' => $type, 'value' => $value];
        }

        return $normalised;
    }

    private function isCurrentSiteId(string $siteId): bool
    {
        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey((int) $siteId)
            ->exists();
    }

    /** @return Collection<int, User> */
    private function finishQuery(Builder $query, ?int $excludeUserId): Collection
    {
        return $query
            ->when($excludeUserId !== null, fn (Builder $users) => $users->whereKeyNot($excludeUserId))
            ->orderBy('id')
            ->get()
            ->values();
    }
}
