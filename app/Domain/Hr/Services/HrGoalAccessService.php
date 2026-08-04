<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrKeyResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Canonical ownership and Site boundary for Goals and OKRs.
 *
 * Retained objectives remain readable through their owner's historical Site
 * provenance. New assignments and all mutations require a current, approved
 * staff owner at a Site visible to the acting user. Inaccessible direct
 * objects are deliberately concealed with model-not-found responses.
 */
class HrGoalAccessService
{
    public function __construct(private readonly HrPerformanceAccessService $performanceAccess) {}

    public function currentViewer(?User $viewer): User
    {
        abort_unless($viewer, 403);

        return $this->performanceAccess->currentStaff($viewer, $viewer);
    }

    /** @return Builder<HrGoal>|Relation<HrGoal, *, *> */
    public function applyHistoricalGoalScope(Builder|Relation $query, User $viewer): Builder|Relation
    {
        return $query->whereIn(
            $query->qualifyColumn('user_id'),
            $this->performanceAccess->historicalUserIds($viewer),
        );
    }

    /** @return Builder<HrGoal>|Relation<HrGoal, *, *> */
    public function applyCurrentGoalScope(Builder|Relation $query, User $viewer): Builder|Relation
    {
        return $query->whereIn(
            $query->qualifyColumn('user_id'),
            $this->performanceAccess->currentUserIds($viewer),
        );
    }

    public function historicalGoal(User $viewer, HrGoal|int $goal): HrGoal
    {
        $goalId = $goal instanceof HrGoal ? $goal->getKey() : $goal;

        return $this->applyHistoricalGoalScope(HrGoal::query(), $viewer)
            ->findOrFail($goalId);
    }

    public function currentGoal(User $viewer, HrGoal|int $goal): HrGoal
    {
        $goalId = $goal instanceof HrGoal ? $goal->getKey() : $goal;

        return $this->applyCurrentGoalScope(HrGoal::query(), $viewer)
            ->findOrFail($goalId);
    }

    /** @return Builder<HrKeyResult> */
    public function applyHistoricalKeyResultScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'goal',
            fn (Builder $goalQuery) => $this->applyHistoricalGoalScope($goalQuery, $viewer),
        );
    }

    /** @return Builder<HrKeyResult> */
    public function applyCurrentKeyResultScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'goal',
            fn (Builder $goalQuery) => $this->applyCurrentGoalScope($goalQuery, $viewer),
        );
    }

    public function currentKeyResult(User $viewer, HrKeyResult|int $keyResult): HrKeyResult
    {
        $keyResultId = $keyResult instanceof HrKeyResult ? $keyResult->getKey() : $keyResult;

        return $this->applyCurrentKeyResultScope(HrKeyResult::query(), $viewer)
            ->findOrFail($keyResultId);
    }

    public function currentStaff(User $viewer, User|int $staff): User
    {
        return $this->performanceAccess->currentStaff($viewer, $staff);
    }

    /** @return Builder<User> */
    public function currentStaffQuery(User $viewer): Builder
    {
        return $this->performanceAccess->currentUserIds($viewer);
    }

    /** @return Builder<User> */
    public function historicalStaffQuery(User $viewer): Builder
    {
        return $this->performanceAccess->historicalUserIds($viewer);
    }
}
