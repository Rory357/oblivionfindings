<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Canonical current-staff eligibility for the single Oblivion Findings
 * application. Historical HR records deliberately remain available through a
 * separate lookup and can never become notification or audience recipients.
 */
class HrCurrentStaffService
{
    /** @return Builder<User> */
    public function currentUsersQuery(): Builder
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return User::query()
            ->staff()
            ->whereNotNull('approved_at')
            ->whereHas('hrEmployeeProfile', function (Builder $profile) use ($today): void {
                $profile
                    ->where('is_active', true)
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('start_date')
                            ->orWhereDate('start_date', '<=', $today);
                    })
                    ->where(function (Builder $dates) use ($today): void {
                        $dates->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $today);
                    });
            });
    }

    /** @return Collection<int, User> */
    public function currentUsers(): Collection
    {
        return $this->currentUsersQuery()->get();
    }

    /** @return array<int, int> */
    public function currentUserIds(): array
    {
        return $this->currentUsersQuery()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function isCurrent(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return is_numeric($userId)
            && (int) $userId > 0
            && $this->currentUsersQuery()->whereKey((int) $userId)->exists();
    }

    public function historicalProfileFor(User|int $user): ?HrEmployeeProfile
    {
        $userId = $user instanceof User ? $user->getKey() : $user;
        if (! is_numeric($userId) || (int) $userId < 1) {
            return null;
        }

        return HrEmployeeProfile::withTrashed()
            ->where('user_id', (int) $userId)
            ->first();
    }

    public function recipientRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_numeric($value) || ! $this->isCurrent((int) $value)) {
                $fail('The selected person must be current approved staff.');
            }
        };
    }
}
