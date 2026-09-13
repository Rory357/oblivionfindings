<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Models\User;

class PerformanceReviewPolicy
{
    public function viewAny(User $user): bool
    {
        if (! $user->canDo('governance.performance.view')) {
            return false;
        }

        if ($user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair')) {
            return true;
        }

        $boardMember = $user->boardMember;
        if ($boardMember && $boardMember->is_active) {
            if ($boardMember->isCommitteeMember('remuneration')
                || $boardMember->isCommitteeMember('governance')
                || $boardMember->isCommitteeMember('executive')) {
                return true;
            }
        }

        // Reviewee can view reviews list if they have any reviews
        return PerformanceReview::where('reviewee_id', $user->id)->exists();
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        if (! $user->canDo('governance.performance.view')) {
            return false;
        }

        return app(GovernanceRecordAccessService::class)->canViewPerformanceReview($user, $review);
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    public function update(User $user, PerformanceReview $review): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    public function delete(User $user, PerformanceReview $review): bool
    {
        return $user->hasRole('admin');
    }

    public function assess(User $user, PerformanceReview $review): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    public function submitSelfAssessment(User $user, PerformanceReview $review): bool
    {
        if ((int) $user->id === (int) $review->reviewee_id) {
            return true;
        }

        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin');
    }
}
